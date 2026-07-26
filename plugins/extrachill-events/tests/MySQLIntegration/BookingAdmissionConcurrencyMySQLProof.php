<?php
/** Genuine two-process booking-admission MySQL integration proof. */

require_once __DIR__ . '/BookingAttachmentMySQLIntegrationTest.php';

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;

/** Prove overlapping application processes converge on one complete winner. */
final class BookingAdmissionConcurrencyMySQLProof extends BookingAttachmentMySQLIntegrationTest {
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
}
