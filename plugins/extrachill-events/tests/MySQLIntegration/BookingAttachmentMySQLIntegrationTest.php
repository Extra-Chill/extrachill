<?php
/**
 * Production booking-attachment service coverage across two MySQL sessions.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.DB.RestrictedFunctions,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PHPUnit fixture keeps its probe provider local and requires a second raw MySQL session.

use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingPrivateFileProvider;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\TicketSettlementService;
use ExtraChillEvents\Core\TicketReconciliationService;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;
use ExtraChillEvents\Abilities\TicketSettlementAbilities;

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
	/** @var callable|null Probe invoked while the complete inquiry lock is held. */
	public $stage_probe;
	/** @var int Number of staged objects. */
	public $stage_count = 0;
	/** @var string Shared cross-process stage/retire journal. */
	public $event_log = '';
	/** @var array<string,string> Private bytes used by CSV integration probes. */
	public $contents = array();
	/** @var array<string,array<string,string>> Metadata for private byte probes. */
	public $metadata = array();

	/** Stage one deterministic probe object. */
	public function stage( string $source_path, string $filename, string $purpose ) {
		++$this->stage_count;
		if ( '' !== $this->event_log ) {
			file_put_contents( $this->event_log, "stage\n", FILE_APPEND | LOCK_EX );
		}
		if ( is_callable( $this->stage_probe ) ) {
			( $this->stage_probe )();
		}
		$reference = 'private_inquiry_probe_object_' . $this->stage_count;
		if ( 'other_private_evidence' === $purpose && is_file( $source_path ) ) {
			$this->contents[ $reference ] = (string) file_get_contents( $source_path );
			$this->metadata[ $reference ] = array(
				'filename'  => $filename,
				'mime_type' => 'text/csv',
			);
		}
		return $reference;
	}

	/** Return fixed trusted metadata after running the concurrency probe. */
	public function claim( string $storage_reference, string $claim_key, string $purpose = '' ) {
		unset( $claim_key, $purpose );
		if ( is_callable( $this->claim_probe ) ) {
			( $this->claim_probe )();
		}
		if ( isset( $this->contents[ $storage_reference ] ) ) {
			return array(
				'filename'     => $this->metadata[ $storage_reference ]['filename'],
				'mime_type'    => $this->metadata[ $storage_reference ]['mime_type'],
				'byte_size'    => strlen( $this->contents[ $storage_reference ] ),
				'content_hash' => hash( 'sha256', $this->contents[ $storage_reference ] ),
				'scan_status'  => 'clean',
			);
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
	public function download_descriptor( string $storage_reference, string $attachment_public_id, int $actor_id, string $purpose, string $claim_key, string $correlation_id ) {
		unset( $attachment_public_id, $actor_id, $purpose, $claim_key, $correlation_id );
		return isset( $this->contents[ $storage_reference ] )
			? array(
				'stream_token' => $storage_reference,
				'expires_at'   => gmdate( 'c', time() + 300 ),
			)
			: new WP_Error( 'not_implemented' );
	}

	/** Downloads are outside this integration scope. */
	public function open_stream( string $stream_token, string $attachment_public_id, int $actor_id, string $purpose, string $correlation_id ) {
		unset( $attachment_public_id, $actor_id, $purpose, $correlation_id );
		if ( ! isset( $this->contents[ $stream_token ] ) ) {
			return new WP_Error( 'not_implemented' );
		}
		$stream = fopen( 'php://temp', 'w+b' );
		fwrite( $stream, $this->contents[ $stream_token ] );
		rewind( $stream );
		return $stream;
	}

	/** Record retirement after probing the held production reference lock. */
	public function retire( string $storage_reference ) {
		if ( is_callable( $this->retire_probe ) ) {
			( $this->retire_probe )();
		}
		$this->retired[] = $storage_reference;
		if ( '' !== $this->event_log ) {
			file_put_contents( $this->event_log, "retire\n", FILE_APPEND | LOCK_EX );
		}
		return true;
	}
}

/** Settlement service probe that races only after production locks evidence. */
final class TicketSettlementMySQLProbeService extends TicketSettlementService {
	/** @var callable|null */
	public $evidence_probe;

	/** Invoke the configured contender while finalize owns the evidence range. */
	protected function after_evidence_locked( array $booking ): void {
		unset( $booking );
		if ( is_callable( $this->evidence_probe ) ) {
			( $this->evidence_probe )();
		}
	}
}

/** Real-MySQL connection that reports one already-committed transaction as uncertain. */
final class BookingCommitUncertainWpdb extends wpdb {
	/** @var bool */
	public $fail_next_commit = false;

	/** Commit normally, then simulate a lost acknowledgement once. */
	public function query( $query ) {
		$result = parent::query( $query );
		if ( $this->fail_next_commit && 'COMMIT' === strtoupper( trim( (string) $query ) ) ) {
			$this->fail_next_commit = false;
			return false;
		}
		return $result;
	}
}

/** CSV importer probe that loses the first row commit acknowledgement. */
final class TicketSettlementCSVReplayProbeService extends TicketSettlementService {
	/** @var callable|null Probe invoked between byte authentication and locking. */
	public $provenance_probe;

	/** Arm the real connection only after complete byte authentication. */
	protected function after_csv_authenticated( array $rows ): void {
		unset( $rows );
		$GLOBALS['wpdb']->fail_next_commit = true;
	}

	/** Invoke a second-session mutation after complete byte authentication. */
	protected function after_settlement_provenance_authenticated( array $booking ): void {
		unset( $booking );
		if ( is_callable( $this->provenance_probe ) ) {
			( $this->provenance_probe )();
		}
	}
}

/** Exercises production repositories, authorization, transactions, and cleanup. */
class BookingAttachmentMySQLIntegrationTest extends WP_UnitTestCase {
	/** Independent contender connection.
	 *
	 * @var mysqli
	 */
	protected $contender;
	/** Probe provider injected into the production service.
	 *
	 * @var BookingAttachmentMySQLProbeProvider
	 */
	protected $provider;
	/** Venue fixture ID.
	 *
	 * @var int
	 */
	protected $venue_id;
	/** Authorized actor fixture ID.
	 *
	 * @var int
	 */
	protected $actor_id;
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
	/** Whether a concurrent evidence insert waited on the settlement snapshot.
	 *
	 * @var bool
	 */
	private $evidence_insert_waited = false;

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
		$venue = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'venue',
				'name'     => 'Integration Room ' . wp_generate_uuid4(),
			)
		);
		$this->assertNotWPError( $venue );
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
		foreach ( array( BookingSchema::settlements_table(), BookingSchema::sales_resolutions_table(), BookingSchema::sales_reports_table(), BookingSchema::ticket_sources_table(), BookingSchema::holds_table(), BookingSchema::attachment_deliveries_table(), BookingSchema::attachments_table(), BookingSchema::activity_table(), BookingSchema::bookings_table(), BookingSchema::memberships_table() ) as $table ) {
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
			$escaped                     = $this->contender->real_escape_string( $lock_name );
			$result                      = $this->contender->query( "SELECT GET_LOCK('{$escaped}', 1)" );
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

	/** Prove concurrent installers serialize on the exact site-scoped schema lock. */
	protected function prove_concurrent_booking_schema_installer_waits_and_converges(): void {
		global $wpdb;
		$this->assertTrue( function_exists( 'pcntl_fork' ), 'The installer concurrency proof requires pcntl_fork().' );
		$lock_name = 'ec_booking_schema_' . substr( hash( 'sha256', (string) DB_NAME . "\0" . $wpdb->prefix ), 0, 40 );
		$escaped   = $this->contender->real_escape_string( $lock_name );
		$this->assertSame( 1, (int) $this->contender->query( "SELECT GET_LOCK('{$escaped}', 1)" )->fetch_row()[0] );
		$started = wp_tempnam( 'booking-schema-started.txt' );
		$result  = $started . '.result';
		unlink( $started );
		$pid = pcntl_fork();
		$this->assertGreaterThanOrEqual( 0, $pid );
		if ( 0 === $pid ) {
			$this->reconnect_wordpress_database();
			file_put_contents( $started, 'started', LOCK_EX );
			$installed = BookingSchema::install();
			file_put_contents( $result, is_wp_error( $installed ) ? $installed->get_error_code() : 'ok', LOCK_EX );
			exit( 0 );
		}
		$deadline = microtime( true ) + 5;
		while ( ! file_exists( $started ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		$this->assertFileExists( $started );
		usleep( 200000 );
		$this->assertFileDoesNotExist( $result, 'A concurrent installer bypassed the site schema lock.' );
		$this->assertSame( 1, (int) $this->contender->query( "SELECT RELEASE_LOCK('{$escaped}')" )->fetch_row()[0] );
		$status = 0;
		pcntl_waitpid( $pid, $status );
		$this->assertTrue( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ) );
		$this->assertSame( 'ok', file_get_contents( $result ) );
		$this->assertTrue( BookingSchema::health() );
		foreach ( array( $started, $result ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** Prove second-session source/report competitors converge or conflict exactly. */
	public function test_competing_source_and_report_identities_are_exact_and_lossless(): void {
		$event_id       = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings       = new BookingRepository();
		$booking        = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Competing Identity Artist',
				'intake'        => array(),
			)
		);
		$booking        = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$reconciliation = new TicketReconciliationService();
		$source_input   = array(
			'booking_id' => $booking['id'],
			'provider'   => 'neutral-provider',
			'source_key' => 'Opaque/Case ID',
			'ticket_url' => 'https://tickets.example.test/identity-one',
		);
		$source         = $reconciliation->register_source( $source_input, $this->actor_id );
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$sources          = BookingSchema::ticket_sources_table();
		$duplicate_source = "INSERT INTO {$sources} (public_id,booking_id,event_id,venue_term_id,provider,source_key,source_key_hash,canonical_url,url_hash,request_hash,created_by_user_id,created_at) SELECT UUID(),booking_id,event_id,venue_term_id,provider,source_key,source_key_hash,'https://tickets.example.test/competitor',SHA2('competitor',256),SHA2('competitor',256),created_by_user_id,UTC_TIMESTAMP() FROM {$sources} WHERE id = " . (int) $source['id'];
		$source_duplicate = false;
		try {
			$source_duplicate = false === $this->contender->query( $duplicate_source ) && 1062 === $this->contender->errno;
		} catch ( mysqli_sql_exception $exception ) {
			$source_duplicate = 1062 === $exception->getCode();
		}
		$this->assertTrue( $source_duplicate, 'A second session inserted a competing source identity.' );
		$this->assertSame( $source['id'], $reconciliation->register_source( $source_input, $this->actor_id )['id'] );
		$changed_url               = $source_input;
		$changed_url['ticket_url'] = 'https://tickets.example.test/conflict';
		$this->assertSame( 'ticket_source_idempotency_conflict', $reconciliation->register_source( $changed_url, $this->actor_id )->get_error_code() );
		$case_distinct               = $source_input;
		$case_distinct['source_key'] = 'opaque/case id';
		$case_distinct['ticket_url'] = 'https://tickets.example.test/identity-two';
		$this->assertIsArray( $reconciliation->register_source( $case_distinct, $this->actor_id ) );

		$service           = new TicketSettlementService( $bookings, null, null, null, $reconciliation );
		$input             = $this->settlement_report_input( $booking['id'], 'Opaque/Report ID', $source['id'] );
		$input['provider'] = 'neutral-provider';
		$report            = $service->record_sales( $input, $this->actor_id );
		$this->assertIsArray( $report, is_wp_error( $report ) ? $report->get_error_code() : '' );
		$sales            = BookingSchema::sales_reports_table();
		$duplicate_report = "INSERT INTO {$sales} (booking_id,event_id,venue_term_id,ticket_source_id,evidence_attachment_id,provider,external_report_id,external_report_id_hash,source_type,provenance_version,ticket_source_request_hash,evidence_attachment_request_hash,evidence_content_hash,evidence_byte_size,period_start,period_end,tickets_sold,tickets_refunded,gross_minor,fees_minor,tax_minor,refunds_minor,net_minor,currency,corrects_report_id,source_payload,request_hash,created_by_user_id,created_at) SELECT booking_id,event_id,venue_term_id,ticket_source_id,evidence_attachment_id,provider,external_report_id,external_report_id_hash,source_type,provenance_version,ticket_source_request_hash,evidence_attachment_request_hash,evidence_content_hash,evidence_byte_size,period_start,period_end,tickets_sold,tickets_refunded,gross_minor + 1,fees_minor,tax_minor,refunds_minor,net_minor,currency,corrects_report_id,source_payload,SHA2('competitor',256),created_by_user_id,UTC_TIMESTAMP() FROM {$sales} WHERE id = " . (int) $report['id'];
		$report_duplicate = false;
		try {
			$report_duplicate = false === $this->contender->query( $duplicate_report ) && 1062 === $this->contender->errno;
		} catch ( mysqli_sql_exception $exception ) {
			$report_duplicate = 1062 === $exception->getCode();
		}
		$this->assertTrue( $report_duplicate, 'A second session inserted a competing report identity.' );
		$this->assertSame( $report['id'], $service->record_sales( $input, $this->actor_id )['id'] );
		$conflict                = $input;
		$conflict['gross_minor'] = $input['gross_minor'] + 1;
		$this->assertSame( 'sales_report_idempotency_conflict', $service->record_sales( $conflict, $this->actor_id )->get_error_code() );
	}

	/** Prove a resolution racing finalization cannot alter the frozen snapshot. */
	protected function prove_resolution_and_finalization_race_freezes_one_consistent_winner(): void {
		$this->assertTrue( function_exists( 'pcntl_fork' ), 'The resolution race proof requires pcntl_fork().' );
		$event_id       = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings       = new BookingRepository();
		$booking        = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Resolution Race Artist',
				'intake'        => array(),
			)
		);
		$booking        = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$reconciliation = new TicketReconciliationService();
		$source         = $reconciliation->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'race-provider',
				'source_key' => 'race-source',
				'ticket_url' => 'https://tickets.example.test/race',
			),
			$this->actor_id
		);
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$service           = new TicketSettlementMySQLProbeService( $bookings, null, null, null, $reconciliation );
		$input             = $this->settlement_report_input( $booking['id'], 'resolution-race-report', $source['id'] );
		$input['provider'] = 'race-provider';
		$report            = $service->record_sales( $input, $this->actor_id );
		$this->assertIsArray( $report, is_wp_error( $report ) ? $report->get_error_code() : '' );
		$preview = $service->calculate(
			array(
				'booking_id'   => $booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			),
			$this->actor_id
		);
		$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() : '' );

		$start   = wp_tempnam( 'resolution-race-start.txt' );
		$attempt = $start . '.attempt';
		$result  = $start . '.result';
		unlink( $start );
		$pid = pcntl_fork();
		$this->assertGreaterThanOrEqual( 0, $pid );
		if ( 0 === $pid ) {
			$this->reconnect_wordpress_database();
			$deadline = microtime( true ) + 20;
			while ( ! file_exists( $start ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
			file_put_contents( $attempt, 'attempt', LOCK_EX );
			$resolved = ( new TicketReconciliationService() )->resolve(
				array(
					'booking_id'       => $booking['id'],
					'report_id'        => $report['id'],
					'expected_version' => 0,
					'decision'         => 'exclude',
					'reason'           => 'Racing finalization.',
				),
				$this->actor_id
			);
			file_put_contents( $result, is_wp_error( $resolved ) ? $resolved->get_error_code() : 'resolved', LOCK_EX );
			exit( 0 );
		}
		$service->evidence_probe = function () use ( $start, $attempt, $result ): void {
			file_put_contents( $start, 'locked', LOCK_EX );
			$deadline = microtime( true ) + 5;
			while ( ! file_exists( $attempt ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
			$this->assertFileExists( $attempt );
			usleep( 200000 );
			$this->assertFileDoesNotExist( $result, 'Resolution bypassed finalization booking/evidence locks.' );
		};
		$settlement              = $service->finalize(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $preview['booking_version'],
				'expected_report_ids'      => $preview['included_report_ids'],
				'expected_evidence_hash'   => $preview['evidence_hash'],
				'basis'                    => $preview['basis'],
				'basis_points'             => $preview['basis_points'],
				'currency'                 => $preview['currency'],
				'formula_version'          => $preview['formula_version'],
				'adjustment_minor'         => 0,
			),
			$this->actor_id
		);
		$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
		$status = 0;
		pcntl_waitpid( $pid, $status );
		$this->assertTrue( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ) );
		$this->assertSame( 'sales_resolution_settlement_frozen', file_get_contents( $result ) );
		foreach ( array( $start, $attempt, $result ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** Prove a lost mid-import commit acknowledgement replays without duplicates. */
	public function test_csv_mid_loop_commit_uncertain_replay_converges_exactly(): void {
		global $wpdb;
		$event_id           = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings           = new BookingRepository();
		$booking            = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'CSV Replay Artist',
				'intake'        => array(),
			)
		);
		$booking            = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$attachments        = new BookingAttachmentRepository();
		$activity           = new BookingActivityRepository();
		$attachment_service = new BookingAttachmentService( $attachments, $bookings, $activity, null, $this->provider );
		$csv                = "external_report_id,period_start,period_end,tickets_sold,tickets_refunded,gross_minor,fees_minor,tax_minor,refunds_minor,net_minor,currency\n"
			. "mysql-csv-1,2026-07-01 00:00:00,2026-07-01 23:59:59,10,0,10000,500,0,0,9500,USD\n"
			. "mysql-csv-2,2026-07-02 00:00:00,2026-07-02 23:59:59,5,0,5000,250,0,0,4750,USD\n";
		$path               = wp_tempnam( 'ticket-sales.csv' );
		file_put_contents( $path, $csv );
		$reference  = $this->provider->stage( $path, 'ticket-sales.csv', 'other_private_evidence' );
		$attachment = $attachment_service->attach(
			array(
				'booking_id'        => $booking['id'],
				'storage_reference' => $reference,
				'idempotency_key'   => 'mysql-csv-replay',
				'purpose'           => 'other_private_evidence',
				'uploader_type'     => 'user',
				'uploader_user_id'  => $this->actor_id,
			)
		);
		$this->assertIsArray( $attachment, is_wp_error( $attachment ) ? $attachment->get_error_code() : '' );
		$reconciliation = new TicketReconciliationService( $bookings, $activity, null, $attachments, $attachment_service );
		$source         = $reconciliation->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'csv-provider',
				'source_key' => 'csv-source',
				'ticket_url' => 'https://tickets.example.test/csv',
			),
			$this->actor_id
		);
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$service   = new TicketSettlementCSVReplayProbeService( $bookings, $activity, null, null, $reconciliation );
		$original  = $wpdb;
		$uncertain = new BookingCommitUncertainWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$uncertain->set_prefix( $original->base_prefix );
		$uncertain->set_blog_id( $original->blogid, $original->siteid );
		$wpdb = $uncertain;
		try {
			$input   = array(
				'booking_id'       => $booking['id'],
				'attachment_id'    => $attachment['id'],
				'ticket_source_id' => $source['id'],
			);
			$reports = $service->import_csv( $input, $this->actor_id );
			$this->assertIsArray( $reports, is_wp_error( $reports ) ? $reports->get_error_code() : '' );
			$this->assertCount( 2, $reports );
			$this->assertSame( array( 'mysql-csv-1', 'mysql-csv-2' ), array_column( $reports, 'external_report_id' ) );
			$retry = $service->import_csv( $input, $this->actor_id );
			$this->assertSame( array_column( $reports, 'id' ), array_column( $retry, 'id' ) );
			$sales_table = BookingSchema::sales_reports_table();
			$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sales_table} WHERE booking_id = %d", $booking['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact disposable integration row count.
			$preview = $service->calculate(
				array(
					'booking_id'   => $booking['id'],
					'basis'        => 'gross_ticket_sales',
					'basis_points' => 2000,
					'currency'     => 'USD',
				),
				$this->actor_id
			);
			$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() : '' );
			$finalize                  = array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $preview['booking_version'],
				'expected_report_ids'      => $preview['included_report_ids'],
				'expected_evidence_hash'   => $preview['evidence_hash'],
				'basis'                    => $preview['basis'],
				'basis_points'             => $preview['basis_points'],
				'currency'                 => $preview['currency'],
				'formula_version'          => $preview['formula_version'],
				'adjustment_minor'         => 0,
			);
			$attachments_table         = BookingSchema::attachments_table();
			$service->provenance_probe = function () use ( $attachments_table, $attachment ): void {
				$this->assertTrue( $this->contender->query( "UPDATE {$attachments_table} SET state = 'purged', purged_at = UTC_TIMESTAMP() WHERE id = " . (int) $attachment['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Independent session wins between byte authentication and settlement locking.
			};
			$this->assertSame( 'settlement_csv_evidence_invalid', $service->finalize( $finalize, $this->actor_id )->get_error_code() );
			$settlements_table = BookingSchema::settlements_table();
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$settlements_table} WHERE booking_id = %d", $booking['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No settlement may survive the won retirement race.
			$this->assertTrue( $this->contender->query( "UPDATE {$attachments_table} SET state = 'active', purged_at = NULL WHERE id = " . (int) $attachment['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Restores the disposable fixture after proving the race.
			$service->provenance_probe = null;
			$settlement                = $service->finalize( $finalize, $this->actor_id );
			$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
			$this->provider->contents[ $reference ] = substr( $csv, 0, -1 );
			$this->assertSame( 'settlement_csv_evidence_invalid', $service->finalize( $finalize, $this->actor_id )->get_error_code() );
			$this->assertSame( $settlement['id'], $service->get( $booking['id'], $this->actor_id )['id'] );
		} finally {
			$wpdb = $original;
			$uncertain->dbh->close();
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}

	/** Prove settlement evidence snapshots block concurrent same-booking inserts. */
	public function test_production_settlement_locks_evidence_and_preserves_payment_audit(): void {
		global $wpdb;
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings = new BookingRepository();
		$booking  = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Settlement Integration Artist',
				'intake'        => array(),
			)
		);
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : '' );
		$booking = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : '' );

		$service   = new TicketSettlementMySQLProbeService();
		$abilities = $this->register_settlement_abilities( $service );
		wp_set_current_user( $this->actor_id );
		$source = ( new TicketReconciliationService() )->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'manual-certified',
				'source_key' => 'primary',
				'ticket_url' => 'https://tickets.example.test/mysql-primary?token=private',
			),
			$this->actor_id
		);
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$this->assertSame(
			$source,
			( new TicketReconciliationService() )->register_source(
				array(
					'booking_id' => $booking['id'],
					'provider'   => 'manual-certified',
					'source_key' => 'primary',
					'ticket_url' => 'https://tickets.example.test/mysql-primary?token=private',
				),
				$this->actor_id
			)
		);
		$report = $abilities['extrachill/record-booking-ticket-sales']->execute(
			array(
				'booking_id'         => $booking['id'],
				'ticket_source_id'   => $source['id'],
				'provider'           => 'manual-certified',
				'external_report_id' => 'mysql-settlement-report-1',
				'source_type'        => 'manual',
				'period_start'       => '2026-07-01 00:00:00',
				'period_end'         => '2026-07-31 23:59:59',
				'tickets_sold'       => 100,
				'tickets_refunded'   => 5,
				'gross_minor'        => 100000,
				'fees_minor'         => 5000,
				'tax_minor'          => 3000,
				'refunds_minor'      => 5000,
				'net_minor'          => 87000,
				'currency'           => 'USD',
				'source'             => array( 'certificate' => 'mysql-proof' ),
			)
		);
		$this->assertIsArray( $report, is_wp_error( $report ) ? $report->get_error_code() : '' );
		$listed = $abilities['extrachill/list-booking-ticket-sales']->execute( array( 'booking_id' => $booking['id'] ) );
		$this->assertIsArray( $listed, is_wp_error( $listed ) ? $listed->get_error_code() : '' );
		$this->assertSame( $report['id'], $listed[0]['id'] );

		$sales         = BookingSchema::sales_reports_table();
		$external      = wp_json_encode(
			array(
				'version' => 1,
				'data'    => array( 'certificate' => 'contender' ),
			)
		);
		$sql           = $this->contender->prepare( "INSERT INTO {$sales} (booking_id,event_id,venue_term_id,provider,external_report_id,external_report_id_hash,source_type,provenance_version,period_start,period_end,tickets_sold,tickets_refunded,gross_minor,fees_minor,tax_minor,refunds_minor,net_minor,currency,corrects_report_id,source_payload,request_hash,created_by_user_id,created_at) VALUES (?,?,?,?,?,?,'manual',1,'2026-07-01 00:00:00','2026-07-31 23:59:59',1,0,100,0,0,0,100,'USD',NULL,?,?,?,UTC_TIMESTAMP())" );
		$provider      = 'manual-certified';
		$external_id   = 'mysql-settlement-contender';
		$external_hash = hash( 'sha256', $external_id );
		$request_hash  = hash( 'sha256', 'contender' );
		$sql->bind_param( 'iiisssssi', $booking['id'], $event_id, $this->venue_id, $provider, $external_id, $external_hash, $external, $request_hash, $this->actor_id );
		$preview = $abilities['extrachill/calculate-booking-settlement']->execute(
			array(
				'booking_id'   => $booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			)
		);
		$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() : '' );
		$this->assertSame( 20000, $preview['amount_due_minor'] );
		$service->evidence_probe = function () use ( $sql ): void {
			try {
				$inserted                     = $sql->execute();
				$this->evidence_insert_waited = false === $inserted && 1205 === $sql->errno;
			} catch ( mysqli_sql_exception $exception ) {
				$this->evidence_insert_waited = 1205 === $exception->getCode();
			}
			$this->contender->rollback();
		};
		$settlement              = $abilities['extrachill/finalize-booking-settlement']->execute(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $booking['version'],
				'expected_report_ids'      => $preview['included_report_ids'],
				'expected_evidence_hash'   => $preview['evidence_hash'],
				'basis'                    => $preview['basis'],
				'basis_points'             => $preview['basis_points'],
				'currency'                 => $preview['currency'],
				'formula_version'          => $preview['formula_version'],
				'adjustment_minor'         => 0,
			)
		);
		$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
		$this->assertSame( 'finalized', $settlement['status'] );
		$this->assertTrue( $this->evidence_insert_waited, 'Concurrent ticket evidence bypassed production finalize() evidence locking.' );
		$next_booking_version = $booking['version'] + 1;
		$this->assertSame(
			1,
			$wpdb->update(
				BookingSchema::bookings_table(),
				array(
					'status'  => 'completed',
					'version' => $next_booking_version,
				),
				array(
					'id'      => $booking['id'],
					'version' => $booking['version'],
				)
			)
		);
		$paid = $abilities['extrachill/mark-booking-settlement-paid']->execute(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $next_booking_version,
				'expected_version'         => 1,
				'payment_reference'        => 'mysql-ach-proof',
			)
		);
		$this->assertIsArray( $paid, is_wp_error( $paid ) ? $paid->get_error_code() : '' );
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertSame( 'mysql-ach-proof', $paid['payment_reference'] );
		$this->assertSame( $this->actor_id, $paid['paid_by_user_id'] );

		$service->evidence_probe = null;
		$void_event_id           = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$void_booking            = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Settlement Void Artist',
				'intake'        => array(),
			)
		);
		$void_booking            = $bookings->claim_event( $void_booking['id'], $void_event_id, $void_booking['version'] );
		$this->assertIsArray( $void_booking, is_wp_error( $void_booking ) ? $void_booking->get_error_code() : '' );
		$void_source = ( new TicketReconciliationService() )->register_source(
			array(
				'booking_id' => $void_booking['id'],
				'provider'   => 'manual-certified',
				'source_key' => 'primary',
				'ticket_url' => 'https://tickets.example.test/mysql-void',
			),
			$this->actor_id
		);
		$this->assertIsArray( $void_source, is_wp_error( $void_source ) ? $void_source->get_error_code() : '' );
		$this->assertIsArray( $abilities['extrachill/record-booking-ticket-sales']->execute( $this->settlement_report_input( $void_booking['id'], 'mysql-settlement-void-report', $void_source['id'] ) ) );
		$void_preview = $abilities['extrachill/calculate-booking-settlement']->execute(
			array(
				'booking_id'   => $void_booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			)
		);
		$this->assertIsArray( $void_preview, is_wp_error( $void_preview ) ? $void_preview->get_error_code() : '' );
		$void_settlement = $abilities['extrachill/finalize-booking-settlement']->execute(
			array(
				'booking_id'               => $void_booking['id'],
				'expected_booking_version' => $void_preview['booking_version'],
				'expected_report_ids'      => $void_preview['included_report_ids'],
				'expected_evidence_hash'   => $void_preview['evidence_hash'],
				'basis'                    => $void_preview['basis'],
				'basis_points'             => $void_preview['basis_points'],
				'currency'                 => $void_preview['currency'],
				'formula_version'          => $void_preview['formula_version'],
				'adjustment_minor'         => 0,
			)
		);
		$this->assertIsArray( $void_settlement, is_wp_error( $void_settlement ) ? $void_settlement->get_error_code() : '' );
		$voided = $abilities['extrachill/void-booking-settlement']->execute(
			array(
				'booking_id'               => $void_booking['id'],
				'expected_booking_version' => $void_booking['version'],
				'expected_version'         => 1,
				'reason'                   => 'MySQL ability void proof.',
			)
		);
		$this->assertIsArray( $voided, is_wp_error( $voided ) ? $voided->get_error_code() : '' );
		$this->assertSame( 'void', $voided['status'] );
	}

	/** Register settlement definitions into the real Core Abilities registry. */
	private function register_settlement_abilities( TicketSettlementService $service ): array {
		$names = array( 'extrachill/register-booking-ticket-source', 'extrachill/list-booking-ticket-sources', 'extrachill/record-booking-ticket-sales', 'extrachill/import-booking-ticket-sales-csv', 'extrachill/list-booking-ticket-sales', 'extrachill/diagnose-booking-ticket-sales', 'extrachill/resolve-booking-ticket-sales', 'extrachill/calculate-booking-settlement', 'extrachill/finalize-booking-settlement', 'extrachill/mark-booking-settlement-paid', 'extrachill/void-booking-settlement' );
		foreach ( $names as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$registrar = new TicketSettlementAbilities( $service );
		$previous  = isset( $GLOBALS['wp_filter']['wp_abilities_api_init'] ) ? clone $GLOBALS['wp_filter']['wp_abilities_api_init'] : null;
		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', array( $registrar, 'register' ) );
		do_action( 'wp_abilities_api_init' );
		remove_all_actions( 'wp_abilities_api_init' );
		if ( null !== $previous ) {
			$GLOBALS['wp_filter']['wp_abilities_api_init'] = $previous;
		}
		$abilities = array();
		foreach ( $names as $name ) {
			$abilities[ $name ] = wp_get_ability( $name );
			$this->assertInstanceOf( WP_Ability::class, $abilities[ $name ], $name . ' was not registered in Core.' );
		}
		return $abilities;
	}

	/** Build valid immutable evidence for an ability execution. */
	protected function settlement_report_input( int $booking_id, string $external_id, int $source_id ): array {
		return array(
			'booking_id'         => $booking_id,
			'ticket_source_id'   => $source_id,
			'provider'           => 'manual-certified',
			'external_report_id' => $external_id,
			'source_type'        => 'manual',
			'period_start'       => '2026-07-01 00:00:00',
			'period_end'         => '2026-07-31 23:59:59',
			'tickets_sold'       => 1,
			'tickets_refunded'   => 0,
			'gross_minor'        => 100,
			'fees_minor'         => 0,
			'tax_minor'          => 0,
			'refunds_minor'      => 0,
			'net_minor'          => 100,
			'currency'           => 'USD',
			'source'             => array( 'certificate' => $external_id ),
		);
	}

	/** Give a forked application process an independent WordPress DB session. */
	protected function reconnect_wordpress_database(): void {
		global $wpdb, $table_prefix;
		if ( $wpdb->dbh instanceof mysqli ) {
			$wpdb->dbh->close();
		}
		$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$wpdb->set_prefix( $table_prefix );
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
