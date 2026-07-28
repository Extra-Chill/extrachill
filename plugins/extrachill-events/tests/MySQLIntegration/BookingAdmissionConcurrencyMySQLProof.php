<?php
/** Genuine two-process booking-admission MySQL integration proof. */

require_once __DIR__ . '/BookingAttachmentMySQLIntegrationTest.php';

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\TicketReconciliationService;
use ExtraChillEvents\Core\TicketSettlementService;
use ExtraChillEvents\Core\VenueBookingConfig;

/** Prove overlapping application processes converge on one complete winner. */
final class BookingAdmissionConcurrencyMySQLProof extends BookingAttachmentMySQLIntegrationTest {
	/** Prove overlapping source and report registrations converge after real lock contention. */
	public function test_concurrent_source_and_report_registrations_converge_or_conflict_exactly(): void {
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
				'artist_name'   => 'Concurrent Evidence Artist',
				'intake'        => array(),
			)
		);
		$booking  = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : '' );
		$this->assertNotFalse( $wpdb->query( 'COMMIT' ), 'The source/report race fixture must be visible to independent sessions.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publishes the fixture before genuine cross-process contention.

		$exact_source = array(
			'booking_id' => $booking['id'],
			'provider'   => 'manual-certified',
			'source_key' => 'Concurrent/Exact Source',
			'ticket_url' => 'https://tickets.example.test/concurrent-exact',
		);
		$exact_race   = $this->race_booking_services(
			$booking['id'],
			fn() => ( new TicketReconciliationService() )->register_source( $exact_source, $this->actor_id ),
			fn() => ( new TicketReconciliationService() )->register_source( $exact_source, $this->actor_id ),
			true
		);
		$this->assertSame( array( 'result', 'sales_reconciliation_commit_uncertain' ), $this->race_result_kinds( $exact_race ) );
		$source = ( new TicketReconciliationService() )->register_source( $exact_source, $this->actor_id );
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$sources = BookingSchema::ticket_sources_table();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sources} WHERE booking_id = %d AND provider = %s AND source_key_hash = %s", $booking['id'], $exact_source['provider'], hash( 'sha256', $exact_source['source_key'] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-race row count.

		$conflicting_source_a               = $exact_source;
		$conflicting_source_a['source_key'] = 'Concurrent/Conflicting Source';
		$conflicting_source_a['ticket_url'] = 'https://tickets.example.test/concurrent-conflict-a';
		$conflicting_source_b               = $conflicting_source_a;
		$conflicting_source_b['ticket_url'] = 'https://tickets.example.test/concurrent-conflict-b';
		$source_conflict                    = $this->race_booking_services(
			$booking['id'],
			fn() => ( new TicketReconciliationService() )->register_source( $conflicting_source_a, $this->actor_id ),
			fn() => ( new TicketReconciliationService() )->register_source( $conflicting_source_b, $this->actor_id )
		);
		$this->assertSame( array( 'result', 'ticket_source_idempotency_conflict' ), $this->race_result_kinds( $source_conflict ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sources} WHERE booking_id = %d AND provider = %s AND source_key_hash = %s", $booking['id'], $exact_source['provider'], hash( 'sha256', $conflicting_source_a['source_key'] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conflicting contenders cannot duplicate an identity.

		$exact_report = $this->settlement_report_input( $booking['id'], 'Concurrent/Exact Report', $source['id'] );
		$report_race  = $this->race_booking_services(
			$booking['id'],
			fn() => ( new TicketSettlementService() )->record_sales( $exact_report, $this->actor_id ),
			fn() => ( new TicketSettlementService() )->record_sales( $exact_report, $this->actor_id ),
			true
		);
		$this->assertSame( array( 'result', 'settlement_commit_uncertain' ), $this->race_result_kinds( $report_race ) );
		$report = ( new TicketSettlementService() )->record_sales( $exact_report, $this->actor_id );
		$this->assertIsArray( $report, is_wp_error( $report ) ? $report->get_error_code() : '' );
		$reports = BookingSchema::sales_reports_table();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reports} WHERE booking_id = %d AND provider = %s AND external_report_id_hash = %s", $booking['id'], $exact_report['provider'], hash( 'sha256', $exact_report['external_report_id'] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-race row count.

		$conflicting_report_a                       = $this->settlement_report_input( $booking['id'], 'Concurrent/Conflicting Report', $source['id'] );
		$conflicting_report_b                       = $conflicting_report_a;
		$conflicting_report_b['gross_minor']        = 200;
		$conflicting_report_b['net_minor']          = 200;
		$report_conflict                            = $this->race_booking_services(
			$booking['id'],
			fn() => ( new TicketSettlementService() )->record_sales( $conflicting_report_a, $this->actor_id ),
			fn() => ( new TicketSettlementService() )->record_sales( $conflicting_report_b, $this->actor_id )
		);
		$this->assertSame( array( 'result', 'sales_report_idempotency_conflict' ), $this->race_result_kinds( $report_conflict ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reports} WHERE booking_id = %d AND provider = %s AND external_report_id_hash = %s", $booking['id'], $conflicting_report_a['provider'], hash( 'sha256', $conflicting_report_a['external_report_id'] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conflicting contenders cannot duplicate an identity.
	}

	/** Prove concurrent installers serialize on the exact site-scoped schema lock. */
	public function test_concurrent_booking_schema_installer_waits_and_converges(): void {
		$this->prove_concurrent_booking_schema_installer_waits_and_converges();
	}

	/** Prove a resolution racing finalization cannot alter the frozen snapshot. */
	public function test_resolution_and_finalization_race_freezes_one_consistent_winner(): void {
		$this->prove_resolution_and_finalization_race_freezes_one_consistent_winner();
	}

	/** Prove the loser replays the completed exact winner without duplicate effects. */
	public function test_concurrent_exact_inquiry_retry_reuses_one_complete_winner(): void {
		global $wpdb;
		$this->assertTrue( function_exists( 'pcntl_fork' ), 'The MySQL concurrency proof requires pcntl_fork().' );
		$config_service    = new VenueBookingConfig();
		$config            = $config_service->get( $this->venue_id );
		$config['enabled'] = true;
		$config            = $config_service->update( $this->venue_id, $config, 0, $this->actor_id );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_code() : 'booking config was not committed' );
		add_filter( 'extrachill_events_allow_test_booking_file', '__return_true' );
		$path        = wp_tempnam( 'booking-inquiry.txt' );
		$event_log   = wp_tempnam( 'booking-inquiry-events.txt' );
		$stage_held  = $path . '.stage-held';
		$release     = $path . '.release';
		$winner_file = $path . '.winner';
		file_put_contents( $path, 'concurrent inquiry' );
		$input = array(
			'idempotency_key' => 'mysql-concurrent-inquiry',
			'venue_term_id'   => $this->venue_id,
			'artist_name'     => 'Concurrent Artist',
			'intake'          => array(),
			'attachments'     => array(
				array(
					'name'     => 'booking-inquiry.txt',
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $path ),
					'purpose'  => 'press_release',
				),
			),
		);
		$this->provider->event_log = $event_log;
		$pid = pcntl_fork();
		$this->assertGreaterThanOrEqual( 0, $pid, 'The winner process could not be created.' );
		if ( 0 === $pid ) {
			$this->reconnect_wordpress_database();
			$winner_provider              = new BookingAttachmentMySQLProbeProvider();
			$winner_provider->event_log   = $event_log;
			$winner_provider->stage_probe = static function () use ( $stage_held, $release ): void {
				file_put_contents( $stage_held, 'ready', LOCK_EX );
				$deadline = microtime( true ) + 20;
				while ( ! file_exists( $release ) && microtime( true ) < $deadline ) {
					usleep( 10000 );
				}
				if ( ! file_exists( $release ) ) {
					throw new RuntimeException( 'The held winner was not released.' );
				}
			};
			$winner_bookings = new BookingRepository();
			$winner_service  = new BookingInquiryAdmissionService( new BookingLifecycle( $winner_bookings ), new BookingAttachmentRepository(), null, $winner_provider, null, $winner_bookings, new BookingActivityRepository() );
			$result          = $winner_service->admit( $input );
			file_put_contents(
				$winner_file,
				wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code(), 'data' => $result->get_error_data() ) : array( 'receipt' => $result ) ),
				LOCK_EX
			);
			exit( 0 );
		}

		$deadline = microtime( true ) + 5;
		while ( ! file_exists( $stage_held ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		$this->assertFileExists( $stage_held, 'The winner never entered provider stage while holding the saga lock.' );
		$bookings    = new BookingRepository();
		$attachments = new BookingAttachmentRepository();
		$service     = new BookingInquiryAdmissionService( new BookingLifecycle( $bookings ), $attachments, null, $this->provider, null, $bookings, new BookingActivityRepository() );
		$contention  = $service->admit( $input );
		$this->assertWPError( $contention );
		$this->assertSame( 'booking_inquiry_processing', $contention->get_error_code() );
		$this->assertSame(
			array(
				'status'      => 423,
				'retryable'   => true,
				'retry_after' => 1,
			),
			$contention->get_error_data()
		);
		$this->assertFileDoesNotExist( $winner_file, 'The winner completed before the lock-timeout contender returned.' );
		file_put_contents( $release, 'continue', LOCK_EX );
		$status = 0;
		pcntl_waitpid( $pid, $status );
		$winner_result = json_decode( (string) file_get_contents( $winner_file ), true );
		$winner        = $winner_result['receipt'] ?? new WP_Error( (string) ( $winner_result['error'] ?? 'missing_winner_receipt' ), '', $winner_result['data'] ?? array() );
		$retry         = $service->admit( $input );

		$this->assertIsArray( $winner, is_wp_error( $winner ) ? $winner->get_error_code() : 'winner was not a receipt' );
		$this->assertIsArray( $retry, is_wp_error( $retry ) ? $retry->get_error_code() : 'retry was not a receipt' );
		$this->assertTrue( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'The winner process did not exit cleanly.' );
		$this->assertSame( $winner, $retry );
		$provider_events = file( $event_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$this->assertSame( array( 'stage' ), $provider_events, 'The overlapping loser staged or retired winner bytes.' );
		$this->assertSame( array(), $this->provider->retired );
		$bookings_table    = BookingSchema::bookings_table();
		$attachments_table = BookingSchema::attachments_table();
		$activity_table    = BookingSchema::activity_table();
		$booking_rows      = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, admission_owner_token FROM {$bookings_table} WHERE inquiry_idempotency_key = %s", $input['idempotency_key'] ), ARRAY_A );
		$this->assertCount( 1, $booking_rows );
		$this->assertSame( 'submitted', $booking_rows[0]['status'] );
		$this->assertTrue( null === $booking_rows[0]['admission_owner_token'] || '' === $booking_rows[0]['admission_owner_token'], 'The completed booking retained its admission reservation token.' );
		$attachment_states = $wpdb->get_col( $wpdb->prepare( "SELECT a.state FROM {$attachments_table} a INNER JOIN {$bookings_table} b ON b.id = a.booking_id WHERE b.inquiry_idempotency_key = %s", $input['idempotency_key'] ) );
		$this->assertSame( array( 'active' ), $attachment_states );
		$activity_kinds = $wpdb->get_col( $wpdb->prepare( "SELECT a.kind FROM {$activity_table} a INNER JOIN {$bookings_table} b ON b.id = a.booking_id WHERE b.inquiry_idempotency_key = %s ORDER BY a.kind ASC", $input['idempotency_key'] ) );
		$this->assertCount( 2, $activity_kinds, 'The canonical booking must have exactly one source activity and one outbox activity.' );
		$this->assertSame(
			array(
				'inquiry_submitted'     => 1,
				'notification_requested' => 1,
			),
			array_count_values( $activity_kinds ),
			'The canonical booking contained a duplicate or unexpected activity kind.'
		);
		foreach ( array( $path, $event_log, $stage_held, $release, $winner_file ) as $temporary_file ) {
			if ( file_exists( $temporary_file ) ) {
				unlink( $temporary_file );
			}
		}
		remove_filter( 'extrachill_events_allow_test_booking_file', '__return_true' );
	}

	/** Run two service calls while both are blocked behind the same booking lock. */
	private function race_booking_services( int $booking_id, callable $first, callable $second, bool $first_commit_uncertain = false ): array {
		$bookings = BookingSchema::bookings_table();
		$this->assertTrue( $this->contender->begin_transaction() );
		$locked = $this->contender->query( "SELECT id FROM {$bookings} WHERE id = {$booking_id} FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Independent fixture connection deliberately owns the application lock.
		$this->assertInstanceOf( mysqli_result::class, $locked );
		$locked->free();

		$base = wp_tempnam( 'booking-service-race.txt' );
		unlink( $base );
		$files = array(
			array( 'ready' => $base . '.first-ready', 'result' => $base . '.first-result' ),
			array( 'ready' => $base . '.second-ready', 'result' => $base . '.second-result' ),
		);
		$pids  = array(
			$this->fork_service_call( $first, $files[0], $first_commit_uncertain ),
			$this->fork_service_call( $second, $files[1], false ),
		);
		$deadline = microtime( true ) + 5;
		while ( ( ! file_exists( $files[0]['ready'] ) || ! file_exists( $files[1]['ready'] ) ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		$both_ready      = file_exists( $files[0]['ready'] ) && file_exists( $files[1]['ready'] );
		$both_waiting    = false;
		$booking_waiting = false;
		if ( $both_ready ) {
			$thread_ids = array_map( 'intval', array( file_get_contents( $files[0]['ready'] ), file_get_contents( $files[1]['ready'] ) ) );
			$deadline   = microtime( true ) + 5;
			do {
				$processes = $this->contender->query( 'SELECT ID, INFO FROM information_schema.PROCESSLIST WHERE COMMAND = \'Query\' AND ID IN (' . implode( ',', $thread_ids ) . ')' );
				if ( $processes instanceof mysqli_result ) {
					$queries = array_column( $processes->fetch_all( MYSQLI_ASSOC ), 'INFO' );
					$processes->free();
					$both_waiting    = 2 === count( array_filter( $queries, static fn( string $query ): bool => false !== stripos( $query, 'FOR UPDATE' ) ) );
					$booking_waiting = 1 === count( array_filter( $queries, static fn( string $query ): bool => false !== stripos( $query, $bookings ) && false !== stripos( $query, 'FOR UPDATE' ) ) );
				}
				if ( ! $both_waiting || ! $booking_waiting ) {
					usleep( 10000 );
				}
			} while ( ( ! $both_waiting || ! $booking_waiting ) && microtime( true ) < $deadline );
		}
		$both_blocked = ! file_exists( $files[0]['result'] ) && ! file_exists( $files[1]['result'] );
		$released     = $this->contender->commit();

		$statuses = array();
		foreach ( $pids as $pid ) {
			$status = 0;
			pcntl_waitpid( $pid, $status );
			$statuses[] = pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status );
		}
		$results = array(
			json_decode( (string) file_get_contents( $files[0]['result'] ), true ),
			json_decode( (string) file_get_contents( $files[1]['result'] ), true ),
		);
		foreach ( $files as $paths ) {
			foreach ( $paths as $path ) {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		}

		$this->assertTrue( $both_ready, 'Both service contenders must reach the held booking lock.' );
		$this->assertTrue( $both_waiting, 'Both service contenders must overlap in production FOR UPDATE lock acquisition.' );
		$this->assertTrue( $booking_waiting, 'At least one service contender must block on the production booking row lock.' );
		$this->assertTrue( $both_blocked, 'A service contender bypassed the held booking lock.' );
		$this->assertTrue( $released, 'The fixture booking lock could not be released.' );
		$this->assertSame( array( true, true ), $statuses, 'A service contender did not exit cleanly.' );
		$this->contender = $this->connect_race_owner_session();
		return $results;
	}

	/** Replace the fixture session closed by forked mysqli shutdown. */
	private function connect_race_owner_session(): mysqli {
		$host = (string) ( getenv( 'DB_HOST' ) ?: DB_HOST );
		$port = (int) getenv( 'DB_PORT' );
		if ( 0 === $port && 1 === preg_match( '/^(.+):(\d+)$/', $host, $match ) ) {
			$host = $match[1];
			$port = (int) $match[2];
		}
		$connection = mysqli_init();
		$this->assertTrue(
			mysqli_real_connect(
				$connection,
				$host,
				(string) ( getenv( 'DB_USER' ) ?: DB_USER ),
				(string) ( getenv( 'DB_PASSWORD' ) ?: DB_PASSWORD ),
				(string) ( getenv( 'DB_NAME' ) ?: DB_NAME ),
				$port > 0 ? $port : 3306
			),
			(string) mysqli_connect_error()
		);
		$connection->set_charset( 'utf8mb4' );
		$connection->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		return $connection;
	}

	/** Fork one independent WordPress service process and persist its bounded result. */
	private function fork_service_call( callable $call, array $files, bool $commit_uncertain ): int {
		$pid = pcntl_fork();
		$this->assertGreaterThanOrEqual( 0, $pid );
		if ( 0 !== $pid ) {
			return $pid;
		}
		$this->reconnect_wordpress_database();
		if ( $commit_uncertain ) {
			global $wpdb;
			$original  = $wpdb;
			$uncertain = new BookingCommitUncertainWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$uncertain->set_prefix( $original->base_prefix );
			$uncertain->set_blog_id( $original->blogid, $original->siteid );
			$uncertain->fail_next_commit = true;
			$wpdb                        = $uncertain;
			$original->dbh->close();
		}
		global $wpdb;
		file_put_contents( $files['ready'], (string) mysqli_thread_id( $wpdb->dbh ), LOCK_EX );
		$result = $call();
		file_put_contents(
			$files['result'],
			wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'result' => $result ) ),
			LOCK_EX
		);
		exit( 0 );
	}

	/** Return deterministic outcome labels for an unordered two-process race. */
	private function race_result_kinds( array $results ): array {
		$kinds = array_map(
			static function ( array $result ): string {
				return isset( $result['result'] ) ? 'result' : (string) ( $result['error'] ?? 'missing' );
			},
			$results
		);
		sort( $kinds );
		return $kinds;
	}
}
