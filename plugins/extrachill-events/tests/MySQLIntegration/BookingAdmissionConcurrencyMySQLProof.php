<?php
/** Genuine two-process booking-admission MySQL integration proof. */

require_once __DIR__ . '/BookingAttachmentMySQLIntegrationTest.php';

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentReadiness;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\ShowSettlementService;
use ExtraChillEvents\Core\TicketReconciliationService;
use ExtraChillEvents\Core\TicketSettlementService;
use ExtraChillEvents\Core\VenueBookingConfig;

/** Serve immutable test bytes without bypassing the show-settlement stream contract. */
final class ShowSettlementMySQLAttachmentService extends BookingAttachmentService {
	/** @var string */
	private $bytes;

	public function __construct( string $bytes ) {
		$this->bytes = $bytes;
	}

	public function download_descriptor( int $booking_id, int $attachment_id, ?int $actor_id = null ) {
		unset( $booking_id, $attachment_id, $actor_id );
		return array(
			'stream_token'   => 'mysql-show-evidence',
			'correlation_id' => '00000000-0000-4000-8000-000000000002',
		);
	}

	public function open_download_stream( int $booking_id, int $attachment_id, string $stream_token, int $actor_id, string $correlation_id ) {
		unset( $booking_id, $attachment_id, $stream_token, $actor_id, $correlation_id );
		$stream = fopen( 'php://temp', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory concurrency evidence.
		fwrite( $stream, $this->bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory concurrency evidence.
		rewind( $stream );
		return $stream;
	}

	public function record_delivery_outcome( int $booking_id, int $attachment_id, string $correlation_id, string $outcome, int $bytes_sent, int $actor_id ) {
		unset( $booking_id, $attachment_id, $correlation_id, $outcome, $bytes_sent, $actor_id );
		return true;
	}
}

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

	/** Prove exact/conflicting revisions and lifecycle transitions serialize across sessions. */
	public function test_show_settlement_revision_finalize_dispute_and_payment_races_serialize(): void {
		global $wpdb;
		$bookings = new BookingRepository();
		$event_id = self::factory()->post->create( array( 'post_type' => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events', 'post_status' => 'publish' ) );
		$booking  = $bookings->create( array( 'venue_term_id' => $this->venue_id, 'artist_name' => 'Concurrent Show Artist', 'intake' => array() ) );
		$booking  = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$source   = ( new TicketReconciliationService() )->register_source( array( 'booking_id' => $booking['id'], 'provider' => 'manual-certified', 'source_key' => 'show-concurrency', 'ticket_url' => 'https://tickets.example.test/show-concurrency' ), $this->actor_id );
		$commission_service = new TicketSettlementService();
		$this->assertIsArray( $commission_service->record_sales( $this->settlement_report_input( $booking['id'], 'show-concurrency-report', $source['id'] ), $this->actor_id ) );
		$preview = $commission_service->calculate( array( 'booking_id' => $booking['id'], 'basis' => 'gross_ticket_sales', 'basis_points' => 2000, 'currency' => 'USD', 'adjustment_minor' => 0 ), $this->actor_id );
		$commission = $commission_service->finalize( array( 'booking_id' => $booking['id'], 'expected_booking_version' => $preview['booking_version'], 'expected_report_ids' => $preview['included_report_ids'], 'expected_evidence_hash' => $preview['evidence_hash'], 'basis' => $preview['basis'], 'basis_points' => $preview['basis_points'], 'currency' => $preview['currency'], 'formula_version' => $preview['formula_version'], 'adjustment_minor' => 0 ), $this->actor_id );
		$this->assertIsArray( $commission, is_wp_error( $commission ) ? $commission->get_error_code() : '' );
		$this->assertNotFalse( $wpdb->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publishes the cross-process fixture.

		$draft_input = $this->show_revision_input( $booking['id'], $commission['id'], 'show-exact' );
		$exact = $this->race_booking_services( $booking['id'], fn() => ( new ShowSettlementService() )->draft( $draft_input, $this->actor_id ), fn() => ( new ShowSettlementService() )->draft( $draft_input, $this->actor_id ) );
		$this->assertSame( array( 'result', 'result' ), $this->race_result_kinds( $exact ) );
		$draft = ( new ShowSettlementService() )->get( $booking['id'], $this->actor_id );
		$this->assertSame( 1, $draft['revision'] );

		$conflict_a = $this->show_revision_input( $booking['id'], $commission['id'], 'show-conflict' );
		$conflict_a['expected_revision_id'] = $draft['id'];
		$conflict_b = $conflict_a;
		$conflict_b['fees_minor'] = 1;
		$conflict = $this->race_booking_services( $booking['id'], fn() => ( new ShowSettlementService() )->revise( $conflict_a, $this->actor_id ), fn() => ( new ShowSettlementService() )->revise( $conflict_b, $this->actor_id ) );
		$this->assertSame( array( 'result', 'show_settlement_idempotency_conflict' ), $this->race_result_kinds( $conflict ) );
		$current = ( new ShowSettlementService() )->get( $booking['id'], $this->actor_id );

		$finalize = array( 'booking_id' => $booking['id'], 'revision_id' => $current['id'], 'expected_version' => $current['version'], 'idempotency_key' => 'show-finalize-race' );
		$revise = $this->show_revision_input( $booking['id'], $commission['id'], 'show-revise-race' );
		$revise['expected_revision_id'] = $current['id'];
		$transition_race = $this->race_booking_services( $booking['id'], fn() => ( new ShowSettlementService() )->finalize( $finalize, $this->actor_id ), fn() => ( new ShowSettlementService() )->revise( $revise, $this->actor_id ) );
		$kinds = $this->race_result_kinds( $transition_race );
		$this->assertContains( 'result', $kinds );
		$this->assertTrue( in_array( 'show_settlement_revision_conflict', $kinds, true ) || in_array( 'show_settlement_status_conflict', $kinds, true ) );
		$current = ( new ShowSettlementService() )->get( $booking['id'], $this->actor_id );
		if ( 'draft' === $current['status'] ) {
			$current = ( new ShowSettlementService() )->finalize( array( 'booking_id' => $booking['id'], 'revision_id' => $current['id'], 'expected_version' => $current['version'], 'idempotency_key' => 'show-finalize-after-race' ), $this->actor_id );
		}

		$bytes       = 'immutable payout evidence';
		$attachments = BookingSchema::attachments_table();
		$wpdb->insert( $attachments, array( 'public_id' => wp_generate_uuid4(), 'booking_id' => $booking['id'], 'uploader_type' => 'user', 'uploader_user_id' => $this->actor_id, 'uploader_reference' => null, 'artist_term_id' => null, 'artist_profile_id' => null, 'purpose' => 'other_private_evidence', 'original_filename' => 'payout.txt', 'mime_type' => 'text/plain', 'byte_size' => strlen( $bytes ), 'content_hash' => hash( 'sha256', $bytes ), 'storage_reference' => 'mysql-show-payout', 'state' => 'active', 'idempotency_key' => 'mysql-show-payout', 'request_hash' => hash( 'sha256', 'mysql-show-payout' ), 'replaces_attachment_id' => null, 'retired_at' => null, 'purged_at' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeds immutable private evidence in the disposable database.
		$attachment_id = (int) $wpdb->insert_id;
		$wpdb->update( BookingSchema::bookings_table(), array( 'status' => 'completed', 'version' => $booking['version'] + 1 ), array( 'id' => $booking['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes the disposable booking for payment contention.
		$this->assertNotFalse( $wpdb->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publishes payment fixtures.
		$dispute = array( 'booking_id' => $booking['id'], 'revision_id' => $current['id'], 'expected_version' => $current['version'], 'idempotency_key' => 'show-dispute-race', 'reason' => 'Concurrent dispute.' );
		$payment = array( 'booking_id' => $booking['id'], 'revision_id' => $current['id'], 'expected_version' => $current['version'], 'idempotency_key' => 'show-payment-race', 'payment_reference' => 'race-payment', 'payment_date' => gmdate( 'Y-m-d' ), 'payout_evidence_attachment_ids' => array( $attachment_id ) );
		$payment_race = $this->race_booking_services( $booking['id'], fn() => ( new ShowSettlementService() )->dispute( $dispute, $this->actor_id ), fn() => ( new ShowSettlementService( null, null, null, null, new BookingAttachmentRepository(), new ShowSettlementMySQLAttachmentService( $bytes ) ) )->mark_paid( $payment, $this->actor_id ) );
		$this->assertSame( 1, count( array_filter( $this->race_result_kinds( $payment_race ), static fn( string $kind ): bool => 'result' === $kind ) ) );
		$this->assertContains( 'show_settlement_version_conflict', $this->race_result_kinds( $payment_race ) );
	}

	/** Prove commission void serializes before or after every dependent show write. */
	public function test_commission_void_races_show_create_finalize_and_payment(): void {
		global $wpdb;
		$create      = $this->show_commission_race_fixture( 'void-create' );
		$draft_input = $this->show_revision_input( $create['booking']['id'], $create['commission']['id'], 'void-create-draft' );
		$this->assert_void_show_race(
			$create,
			fn() => ( new ShowSettlementService() )->draft( $draft_input, $this->actor_id )
		);

		$finalize = $this->show_commission_race_fixture( 'void-finalize' );
		$draft    = ( new ShowSettlementService() )->draft( $this->show_revision_input( $finalize['booking']['id'], $finalize['commission']['id'], 'void-finalize-draft' ), $this->actor_id );
		$this->assertIsArray( $draft, is_wp_error( $draft ) ? $draft->get_error_code() : '' );
		$this->assertNotFalse( $GLOBALS['wpdb']->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publishes the draft before contention.
		$this->assert_void_show_race(
			$finalize,
			fn() => ( new ShowSettlementService() )->finalize( array( 'booking_id' => $finalize['booking']['id'], 'revision_id' => $draft['id'], 'expected_version' => $draft['version'], 'idempotency_key' => 'void-finalize-action' ), $this->actor_id )
		);

		$payment   = $this->show_commission_race_fixture( 'void-payment' );
		$service   = new ShowSettlementService();
		$draft     = $service->draft( $this->show_revision_input( $payment['booking']['id'], $payment['commission']['id'], 'void-payment-draft' ), $this->actor_id );
		$finalized = $service->finalize(
			array(
				'booking_id'       => $payment['booking']['id'],
				'revision_id'      => $draft['id'],
				'expected_version' => $draft['version'],
				'idempotency_key'  => 'void-payment-finalize',
			),
			$this->actor_id
		);
		$bytes = 'void race payout evidence';
		$wpdb->insert( BookingSchema::attachments_table(), array( 'public_id' => wp_generate_uuid4(), 'booking_id' => $payment['booking']['id'], 'uploader_type' => 'user', 'uploader_user_id' => $this->actor_id, 'uploader_reference' => null, 'artist_term_id' => null, 'artist_profile_id' => null, 'purpose' => 'other_private_evidence', 'original_filename' => 'void-race-payout.txt', 'mime_type' => 'text/plain', 'byte_size' => strlen( $bytes ), 'content_hash' => hash( 'sha256', $bytes ), 'storage_reference' => 'void-race-payout', 'state' => 'active', 'idempotency_key' => 'void-race-payout', 'request_hash' => hash( 'sha256', 'void-race-payout' ), 'replaces_attachment_id' => null, 'retired_at' => null, 'purged_at' => null, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeds disposable payout evidence.
		$attachment_id = (int) $wpdb->insert_id;
		$wpdb->update( BookingSchema::bookings_table(), array( 'status' => 'completed', 'version' => $payment['booking']['version'] + 1 ), array( 'id' => $payment['booking']['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes the disposable booking.
		++$payment['booking']['version'];
		$this->assertNotFalse( $wpdb->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publishes payout fixtures.
		$this->assert_void_show_race(
			$payment,
			fn() => ( new ShowSettlementService( null, null, null, null, new BookingAttachmentRepository(), new ShowSettlementMySQLAttachmentService( $bytes ) ) )->mark_paid(
				array(
					'booking_id'                     => $payment['booking']['id'],
					'revision_id'                    => $finalized['id'],
					'expected_version'               => $finalized['version'],
					'idempotency_key'                => 'void-payment-action',
					'payment_reference'              => 'void-race',
					'payment_date'                   => gmdate( 'Y-m-d' ),
					'payout_evidence_attachment_ids' => array( $attachment_id ),
				),
				$this->actor_id
			)
		);
	}

	/** Prove the loser replays the completed exact winner without duplicate effects. */
	public function test_concurrent_exact_inquiry_retry_reuses_one_complete_winner(): void {
		global $wpdb;
		$this->assertTrue( function_exists( 'pcntl_fork' ), 'The MySQL concurrency proof requires pcntl_fork().' );
		$this->assertNotFalse( update_term_meta( $this->venue_id, '_venue_timezone', 'America/New_York' ) );
		$config_service    = new VenueBookingConfig();
		$config            = $config_service->get( $this->venue_id );
		$config['enabled'] = true;
		$config['spaces']  = array(
			array(
				'key'        => 'main-room',
				'name'       => 'Main Room',
				'is_default' => true,
			),
		);
		$config['attachment_policy'] = array(
			'version'  => 1,
			'enabled'  => true,
			'purposes' => array( array( 'key' => 'press_release', 'requirement' => 'invited' ) ),
		);
		$config            = $config_service->update( $this->venue_id, $config, 0, $this->actor_id );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_code() : 'booking config was not committed' );
		$this->assertNotFalse( $wpdb->query( 'COMMIT' ), 'The inquiry fixture must be visible after the winner reconnects.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publishes the fixture before genuine cross-process contention.
		add_filter( 'extrachill_events_allow_test_booking_file', '__return_true' );
		$path        = wp_tempnam( 'booking-inquiry.txt' );
		$event_log   = wp_tempnam( 'booking-inquiry-events.txt' );
		$stage_held  = $path . '.stage-held';
		$release     = $path . '.release';
		$winner_file = $path . '.winner';
		file_put_contents( $path, 'concurrent inquiry' );
		$input = array(
			'idempotency_key'     => 'mysql-concurrent-inquiry',
			'venue_term_id'       => $this->venue_id,
			'artist_name'         => 'Concurrent Artist',
			'requested_space_key' => 'main-room',
			'requested_start_at'  => '2030-08-01 20:00:00',
			'requested_end_at'    => '2030-08-01 23:00:00',
			'intake'              => array(
				'config_revision' => $config['revision'],
				'message'         => 'Please consider us.',
				'fields'          => array(),
				'consent'         => array(
					'id'       => 'booking-privacy',
					'version'  => 1,
					'accepted' => true,
				),
			),
			'attachments'         => array(
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
			$winner_service  = new BookingInquiryAdmissionService( new BookingLifecycle( $winner_bookings ), new BookingAttachmentRepository(), null, $winner_provider, null, $winner_bookings, new BookingActivityRepository(), null, null, new BookingAttachmentReadiness( static function (): bool { return true; } ) );
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
		if ( ! file_exists( $stage_held ) && file_exists( $winner_file ) ) {
			$this->fail( 'The winner failed before provider stage: ' . (string) file_get_contents( $winner_file ) );
		}
		$this->assertFileExists( $stage_held, 'The winner never entered provider stage while holding the saga lock.' );
		$bookings    = new BookingRepository();
		$attachments = new BookingAttachmentRepository();
		$service     = new BookingInquiryAdmissionService( new BookingLifecycle( $bookings ), $attachments, null, $this->provider, null, $bookings, new BookingActivityRepository(), null, null, new BookingAttachmentReadiness( static function (): bool { return true; } ) );
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

	/** Build a complete evidence-free door revision for MySQL races. */
	private function show_revision_input( int $booking_id, int $commission_id, string $key ): array {
		return array(
			'booking_id'                     => $booking_id,
			'commission_settlement_id'       => $commission_id,
			'currency'                       => 'USD',
			'ticket_gross_minor'              => 100,
			'door_gross_minor'                => 0,
			'fees_minor'                      => 0,
			'taxes_minor'                     => 0,
			'refunds_minor'                   => 0,
			'venue_expenses_minor'            => 0,
			'production_expenses_minor'       => 0,
			'artist_guarantee_minor'          => 50,
			'artist_split_basis_points'       => 5000,
			'adjustments'                     => array(),
			'door_report_attachment_ids'      => array(),
			'idempotency_key'                 => $key,
		);
	}

	/** Build and publish one independent booking plus finalized commission. */
	private function show_commission_race_fixture( string $key ): array {
		global $wpdb;
		$bookings = new BookingRepository();
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$booking  = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Void Race Artist',
				'intake'        => array(),
			)
		);
		$booking  = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$source   = ( new TicketReconciliationService() )->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'manual-certified',
				'source_key' => $key,
				'ticket_url' => 'https://tickets.example.test/' . $key,
			),
			$this->actor_id
		);
		$service  = new TicketSettlementService();
		$service->record_sales( $this->settlement_report_input( $booking['id'], $key . '-report', $source['id'] ), $this->actor_id );
		$preview    = $service->calculate(
			array(
				'booking_id'       => $booking['id'],
				'basis'            => 'gross_ticket_sales',
				'basis_points'     => 2000,
				'currency'         => 'USD',
				'adjustment_minor' => 0,
			),
			$this->actor_id
		);
		$commission = $service->finalize(
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
		$this->assertIsArray( $commission, is_wp_error( $commission ) ? $commission->get_error_code() : '' );
		$this->assertNotFalse( $wpdb->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Publishes the independent race fixture.
		return compact( 'booking', 'commission' );
	}

	/** Race commission void against one dependent operation and verify fail-closed state. */
	private function assert_void_show_race( array $fixture, callable $show_operation ): void {
		$void    = array(
			'booking_id'               => $fixture['booking']['id'],
			'expected_booking_version' => $fixture['booking']['version'],
			'expected_version'         => $fixture['commission']['version'],
			'reason'                   => 'Concurrent commission void.',
		);
		$results = $this->race_booking_services( $fixture['booking']['id'], fn() => ( new TicketSettlementService() )->void( $void, $this->actor_id ), $show_operation, false, true );
		$kinds   = $this->race_result_kinds( $results );
		$this->assertSame( array( 'result', 'show_settlement_commission_invalid' ), $kinds, 'The queued void must commit before the dependent show write revalidates the commission.' );
		$commission = ( new TicketSettlementService() )->get( $fixture['booking']['id'], $this->actor_id );
		$this->assertSame( 'void', $commission['status'] );
		$show = ( new ShowSettlementService() )->get( $fixture['booking']['id'], $this->actor_id );
		$this->assertTrue( in_array( $show->get_error_code(), array( 'show_settlement_not_found', 'show_settlement_commission_invalid' ), true ) );
	}

	/** Run two service calls while both are blocked behind the same booking lock. */
	private function race_booking_services( int $booking_id, callable $first, callable $second, bool $first_commit_uncertain = false, bool $order_first = false ): array {
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
		$first_pid    = $this->fork_service_call( $first, $files[0], $first_commit_uncertain );
		$first_queued = ! $order_first;
		if ( $order_first ) {
			$deadline = microtime( true ) + 5;
			while ( ! file_exists( $files[0]['ready'] ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
			if ( file_exists( $files[0]['ready'] ) ) {
				$thread_id = (int) file_get_contents( $files[0]['ready'] );
				$deadline  = microtime( true ) + 5;
				do {
					$process = $this->contender->query( "SELECT INFO FROM information_schema.PROCESSLIST WHERE COMMAND = 'Query' AND ID = {$thread_id}" );
					if ( $process instanceof mysqli_result ) {
						$query = (string) ( $process->fetch_row()[0] ?? '' );
						$process->free();
						$first_queued = false !== stripos( $query, $bookings ) && false !== stripos( $query, 'FOR UPDATE' );
					}
					if ( ! $first_queued ) {
						usleep( 10000 );
					}
				} while ( ! $first_queued && microtime( true ) < $deadline );
			}
		}
		$pids = array(
			$first_pid,
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
		$this->assertTrue( $first_queued, 'The ordered first contender must enter the production booking lock queue before the second contender starts.' );
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
