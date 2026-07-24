<?php
/**
 * Production booking-attachment service coverage across two MySQL sessions.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.DB.RestrictedFunctions,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PHPUnit fixture keeps its probe provider local and requires a second raw MySQL session.

use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingPrivateFileProvider;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;

/** Provider probe that pauses inside production service callbacks. */
final class BookingAttachmentMySQLProbeProvider implements BookingPrivateFileProvider {
	/** Probe invoked while the production claim callback holds its locks.
	 *
	 * @var callable|null
	 */
	public $claim_probe;
	/** Probe invoked while production cleanup holds its reference lock.
	 *
	 * @var callable|null
	 */
	public $retire_probe;
	/** References retired by the probe provider.
	 *
	 * @var string[]
	 */
	public $retired = array();

	/** The integration probe does not stage files. */
	public function stage( string $source_path, string $filename, string $purpose ) {
		unset( $source_path, $filename, $purpose );
		return new WP_Error( 'not_implemented' );
	}

	/** Return fixed trusted metadata after running the concurrency probe. */
	public function claim( string $storage_reference, string $claim_key, string $purpose = '' ) {
		unset( $storage_reference, $claim_key, $purpose );
		if ( is_callable( $this->claim_probe ) ) {
			( $this->claim_probe )();
		}
		return array(
			'filename'     => 'integration-rider.pdf',
			'mime_type'    => 'application/pdf',
			'byte_size'    => 1024,
			'content_hash' => hash( 'sha256', 'integration-rider.pdf' ),
			'scan_status'  => 'clean',
		);
	}

	/** Claims never need compensation in this successful-path probe. */
	public function release_claim( string $storage_reference, string $claim_key ) {
		unset( $storage_reference, $claim_key );
		return true;
	}

	/** Return an empty reconciliation inventory. */
	public function inspect_claims( ?string $cursor = null ) {
		unset( $cursor );
		return array(
			'claims'       => array(),
			'uncertain'    => 0,
			'truncated'    => false,
			'continuation' => null,
		);
	}

	/** Downloads are outside this integration scope. */
	public function download_descriptor( string $storage_reference, string $attachment_public_id, int $actor_id, string $purpose, string $claim_key ) {
		unset( $storage_reference, $attachment_public_id, $actor_id, $purpose, $claim_key );
		return new WP_Error( 'not_implemented' );
	}

	/** Downloads are outside this integration scope. */
	public function open_stream( string $stream_token, string $attachment_public_id, int $actor_id, string $purpose ) {
		unset( $stream_token, $attachment_public_id, $actor_id, $purpose );
		return new WP_Error( 'not_implemented' );
	}

	/** Record retirement after probing the held production reference lock. */
	public function retire( string $storage_reference ) {
		if ( is_callable( $this->retire_probe ) ) {
			( $this->retire_probe )();
		}
		$this->retired[] = $storage_reference;
		return true;
	}
}

/** Exercises production repositories, authorization, transactions, and cleanup. */
final class BookingAttachmentMySQLIntegrationTest extends WP_UnitTestCase {
	/** Independent contender connection.
	 *
	 * @var mysqli
	 */
	private $contender;
	/** Probe provider injected into the production service.
	 *
	 * @var BookingAttachmentMySQLProbeProvider
	 */
	private $provider;
	/** Venue fixture ID.
	 *
	 * @var int
	 */
	private $venue_id;
	/** Authorized actor fixture ID.
	 *
	 * @var int
	 */
	private $actor_id;
	/** Whether the membership contender remained blocked during claim.
	 *
	 * @var bool
	 */
	private $membership_update_waited = false;
	/** Whether the named-lock contender remained blocked during retirement.
	 *
	 * @var bool
	 */
	private $reference_lock_waited = false;

	/** Install the production schema and create two real database sessions. */
	public function set_up(): void {
		parent::set_up();
		if ( ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'The mysqli extension is required for two-session MySQL coverage.' );
		}
		if ( ':memory:' === DB_NAME || false !== stripos( (string) DB_HOST, 'sqlite' ) ) {
			$this->markTestSkipped( 'A real MySQL test database is required; SQLite substitution is not faithful.' );
		}

		register_taxonomy(
			'venue',
			'post',
			array( 'public' => false )
		);
		$venue          = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'venue',
				'name'     => 'Integration Room',
			)
		);
		$this->venue_id = (int) $venue->term_id;
		$this->actor_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->actor_id )->add_cap( VenueAuthorization::ACCESS_CAPABILITY );

		$this->assertTrue( BookingSchema::install() );
		$membership = ( new VenueMembershipRepository() )->create(
			array(
				'venue_term_id'      => $this->venue_id,
				'user_id'            => $this->actor_id,
				'is_owner'           => true,
				'status'             => VenueAuthorization::STATUS_ACTIVE,
				'created_by_user_id' => $this->actor_id,
			)
		);
		$this->assertIsArray( $membership, is_wp_error( $membership ) ? $membership->get_error_code() : '' );
		$this->provider  = new BookingAttachmentMySQLProbeProvider();
		$this->contender = $this->connect_second_session();
		$this->contender->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
	}

	/** Remove all disposable booking state and close the contender session. */
	public function tear_down(): void {
		global $wpdb;
		if ( $this->contender instanceof mysqli ) {
			$this->contender->close();
		}
		foreach ( array( BookingSchema::holds_table(), BookingSchema::attachments_table(), BookingSchema::activity_table(), BookingSchema::bookings_table(), BookingSchema::memberships_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Disposable test database cleanup.
		}
		delete_option( BookingSchema::VERSION_OPTION );
		delete_option( BookingSchema::FAILURE_OPTION );
		parent::tear_down();
	}

	/** Prove production attach and cleanup serialize their authority domains. */
	public function test_production_attach_and_cleanup_hold_the_locked_authority_and_reference_domains(): void {
		global $wpdb;
		$booking = ( new BookingRepository() )->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Integration Artist',
				'intake'        => array(),
			)
		);
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : '' );

		$memberships                 = BookingSchema::memberships_table();
		$this->provider->claim_probe = function () use ( $memberships ): void {
			try {
				$updated                        = $this->contender->query( "UPDATE {$memberships} SET status = 'revoked' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Independent test connection races the production transaction.
				$this->membership_update_waited = false === $updated && 1205 === $this->contender->errno;
			} catch ( mysqli_sql_exception $exception ) {
				$this->membership_update_waited = 1205 === $exception->getCode();
			}
		};

		$service    = new BookingAttachmentService( null, null, null, null, $this->provider );
		$attachment = $service->attach(
			array(
				'booking_id'        => $booking['id'],
				'storage_reference' => 'private_object_integration_123456',
				'idempotency_key'   => 'mysql-integration-attach',
				'purpose'           => 'other_private_evidence',
				'uploader_type'     => 'user',
				'uploader_user_id'  => $this->actor_id,
			)
		);
		$this->assertIsArray( $attachment, is_wp_error( $attachment ) ? $attachment->get_error_code() : '' );
		$this->assertTrue( $this->membership_update_waited, 'Membership revocation bypassed the rows used by production attachment authorization.' );
		$this->assertTrue( $this->contender->query( "UPDATE {$memberships} SET status = 'revoked' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}" ), 'Membership revocation did not complete after the production transaction committed.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Independent test connection confirms lock release.
		$this->assertSame( 1, $this->contender->affected_rows );

		$this->contender->query( "UPDATE {$memberships} SET status = 'active' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Restores isolated fixture authority for cleanup.
		$deleted = $service->delete( $booking['id'], $attachment['id'], $this->actor_id );
		$this->assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->get_error_code() : '' );
		$wpdb->update( BookingSchema::attachments_table(), array( 'retired_at' => '2020-01-01 00:00:00' ), array( 'id' => $attachment['id'] ) );

		$lock_name                    = $this->reference_lock_name( $attachment['storage_reference'] );
		$this->provider->retire_probe = function () use ( $lock_name ): void {
			$escaped = $this->contender->real_escape_string( $lock_name );
			$result                       = $this->contender->query( "SELECT GET_LOCK('{$escaped}', 1)" );
			$this->reference_lock_waited = $result instanceof mysqli_result && 0 === (int) $result->fetch_row()[0];
		};
		$cleanup                      = $service->cleanup(
			array(
				'actor_id'            => $this->actor_id,
				'retention_days'      => 1,
				'legal_hold_callback' => static function (): bool {
					return false;
				},
			)
		);
		$this->assertSame( 1, $cleanup['purged'] ?? 0, is_wp_error( $cleanup ) ? $cleanup->get_error_code() : '' );
		$this->assertTrue( $this->reference_lock_waited, 'A second session acquired the reference domain while production cleanup was retiring bytes.' );
		$result = $this->contender->query( "SELECT GET_LOCK('{$lock_name}', 1)" );
		$this->assertInstanceOf( mysqli_result::class, $result );
		$this->assertSame( 1, (int) $result->fetch_row()[0], 'The second session did not acquire the reference domain after cleanup committed.' );
		$this->assertSame( 1, (int) $this->contender->query( "SELECT RELEASE_LOCK('{$lock_name}')" )->fetch_row()[0] );
		$this->assertSame( array( 'private_object_integration_123456' ), $this->provider->retired );
		$this->assertSame( 'purged', ( new BookingAttachmentRepository() )->get( $attachment['id'] )['state'] );
	}

	/** Connect to the same disposable database independently of WordPress. */
	private function connect_second_session(): mysqli {
		$host = (string) getenv( 'DB_HOST' );
		$port = (int) getenv( 'DB_PORT' );
		$user = (string) getenv( 'DB_USER' );
		$pass = (string) getenv( 'DB_PASSWORD' );
		$name = (string) getenv( 'DB_NAME' );
		$host = '' !== $host ? $host : (string) DB_HOST;
		$user = '' !== $user ? $user : (string) DB_USER;
		$pass = '' !== $pass ? $pass : (string) DB_PASSWORD;
		$name = '' !== $name ? $name : (string) DB_NAME;
		if ( 0 === $port && 1 === preg_match( '/^(.+):(\d+)$/', $host, $match ) ) {
			$host = $match[1];
			$port = (int) $match[2];
		}
		$connection = mysqli_init();
		$port       = $port > 0 ? $port : 3306;
		$this->assertTrue( mysqli_real_connect( $connection, $host, $user, $pass, $name, $port ), (string) mysqli_connect_error() );
		$connection->set_charset( 'utf8mb4' );
		return $connection;
	}

	/** Derive the exact production advisory-lock identity. */
	private function reference_lock_name( string $reference ): string {
		$scope = get_current_blog_id() . ':' . BookingSchema::attachments_table() . ':' . $reference;
		return 'ec_booking_file_' . substr( hash( 'sha256', $scope ), 0, 40 );
	}
}
