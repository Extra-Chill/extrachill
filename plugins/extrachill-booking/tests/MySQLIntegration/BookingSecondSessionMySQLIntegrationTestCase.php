<?php
/**
 * Shared second-session fixture and proof bodies for the booking-attachment
 * MySQL proofs and their genuine two-process admission-concurrency sibling.
 *
 * `BookingAdmissionConcurrencyMySQLProof` used to `require_once` and
 * `extends BookingAttachmentMySQLIntegrationTest` directly (extrachill-events
 * #871), which — same mechanism #870 already fixed for
 * `InternalBookingHoldConcurrencyMySQLProof` — dragged every one of
 * `BookingAttachmentMySQLIntegrationTest`'s own `test_*` methods into the
 * `booking-concurrency` suite alongside the one file it actually declares,
 * including `test_production_settlement_locks_evidence_and_preserves_payment_audit()`,
 * which calls `mysqli::rollback()` — the exact call PHP-WASM traps (see
 * Automattic/wp-codebox#2518, extrachill-events#870/#884). That's a suite
 * that declares one file but silently runs ten, and once the trap is fixed
 * it would duplicate `booking-mysql`'s own coverage on every CI run.
 *
 * This base class carries the second-session connection, the probe
 * provider, and the two proof bodies both proofs need, as a sibling both
 * concrete classes extend — not a parent one `require_once`s the other's
 * whole test file to reach.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.DB.RestrictedFunctions,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.unlink_unlink -- PHPUnit fixture keeps its probe provider local and requires a second raw MySQL session; forked-process proofs coordinate readiness/results via disposable local marker files (never user input), matching InternalBookingHoldConcurrencyMySQLProof's own established pattern (extrachill-events#870/#882).

require_once __DIR__ . '/BookingMySQLIntegrationTestCase.php';

use ExtraChillEvents\Core\BookingPrivateFileProvider;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\TicketSettlementService;

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

/**
 * Shared fixture: a second, independent mysqli session plus the probe
 * provider both booking-attachment proofs race production behaviour
 * against.
 */
abstract class BookingSecondSessionMySQLIntegrationTestCase extends BookingMySQLIntegrationTestCase {
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

	/** Open the second contender session shared proofs race against. */
	public function set_up(): void {
		parent::set_up();
		$this->provider  = new BookingAttachmentMySQLProbeProvider();
		$this->contender = $this->connect_second_session();
		$this->contender->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
	}

	/** Close the contender session before the shared fixture tears down. */
	public function tear_down(): void {
		if ( $this->contender instanceof mysqli ) {
			$this->contender->close();
		}
		parent::tear_down();
	}

	/**
	 * Prove concurrent installers serialize on the exact site-scoped schema lock.
	 *
	 * PHP-WASM has no pcntl; skip cleanly in the sandbox and run for real
	 * in the host MySQL proof job (extrachill-events#870/#884).
	 */
	protected function prove_concurrent_booking_schema_installer_waits_and_converges(): void {
		global $wpdb;
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'This proof requires pcntl_fork(), which is unavailable in the managed sandbox (PHP-WASM cannot fork real OS processes). It executes against real MySQL and real pcntl in the host MySQL proof workflow — see extrachill-events#870/#884.' );
		}
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

	/**
	 * Prove a resolution racing finalization cannot alter the frozen snapshot.
	 *
	 * PHP-WASM has no pcntl; skip cleanly in the sandbox and run for real
	 * in the host MySQL proof job (extrachill-events#870/#884).
	 */
	protected function prove_resolution_and_finalization_race_freezes_one_consistent_winner(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'This proof requires pcntl_fork(), which is unavailable in the managed sandbox (PHP-WASM cannot fork real OS processes). It executes against real MySQL and real pcntl in the host MySQL proof workflow — see extrachill-events#870/#884.' );
		}
		$event_id       = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings       = new ExtraChillEvents\Core\BookingRepository();
		$booking        = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Resolution Race Artist',
				'intake'        => array(),
			)
		);
		$booking        = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$reconciliation = new ExtraChillEvents\Core\TicketReconciliationService();
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
			$resolved = ( new ExtraChillEvents\Core\TicketReconciliationService() )->resolve(
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
}
