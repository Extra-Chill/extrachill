<?php
/**
 * Inquiry attachment admission contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingNotificationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** Verifies the hidden inquiry ability's complete attachment saga. */
final class BookingInquiryAdmissionTest extends TestCase {

	/**
	 * Fake private provider.
	 *
	 * @var BookingTestPrivateFileProvider
	 */
	private $provider;
	/**
	 * Temporary upload fixture paths.
	 *
	 * @var string[]
	 */
	private $temporary_files = array();

	/** Initialize one Events-site admission fixture. */
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'         => 7,
			'stack'           => array(),
			'uuid'            => 0,
			'options'         => array(),
			'dbdelta'         => array(),
			'abilities'       => array(),
			'actions'         => array(),
			'max_upload_size' => 20 * 1024 * 1024,
			'terms'           => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'            => array(
				7 => array(
					55 => array( '_extrachill_booking_config' => array( 'enabled' => true ) ),
				),
			),
			'posts'           => array(),
			'post_meta'       => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$GLOBALS['extrachill_events_booking_reference_lock_uncertainty']         = array();
		$GLOBALS['extrachill_events_booking_database_connection_quarantined']    = false;
		$GLOBALS['ec_test_filters']['extrachill_events_allow_test_booking_file'] = array(
			10 => array(
				array(
					static function (): bool {
						return true;
					},
					1,
				),
			),
		);
		$this->provider = new BookingTestPrivateFileProvider();
	}

	/** Remove temporary upload fixtures. */
	protected function tearDown(): void {
		foreach ( $this->temporary_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}
		}
	}

	/**
	 * Build a coordinator sharing repositories and the fake provider.
	 *
	 * @param BookingTestAuthorization|null $authorization Authorization spy.
	 */
	private function service( ?BookingTestAuthorization $authorization = null ): BookingInquiryAdmissionService {
		$bookings           = new BookingRepository();
		$attachments        = new BookingAttachmentRepository();
		$lifecycle          = new BookingLifecycle( $bookings );
		$attachment_service = new BookingAttachmentService( $attachments, $bookings, null, null, $this->provider, $authorization ? $authorization : new BookingTestAuthorization() );
		return new BookingInquiryAdmissionService( $lifecycle, $attachments, $attachment_service, $this->provider );
	}

	/**
	 * Build valid scalar inquiry input.
	 *
	 * @param array  $files Staged files.
	 * @param string $key   Inquiry idempotency key.
	 */
	private function input( array $files = array(), string $key = 'inquiry-admission' ): array {
		return array(
			'idempotency_key' => $key,
			'venue_term_id'   => 55,
			'artist_name'     => 'New Band',
			'intake'          => array( 'message' => 'Please consider us.' ),
			'attachments'     => $files,
		);
	}

	/**
	 * Create one trusted staged-upload fixture.
	 *
	 * @param string $name     Original filename.
	 * @param string $contents File contents.
	 * @param string $purpose  Booking purpose.
	 */
	private function upload( string $name, string $contents, string $purpose ): array {
		$path = tempnam( sys_get_temp_dir(), 'ec-inquiry-' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->temporary_files[] = $path;
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $path ),
			'purpose'  => $purpose,
		);
	}

	/**
	 * Create a sparse staged-upload fixture with an exact byte size.
	 *
	 * @param string $name    Original filename.
	 * @param int    $size    File size.
	 * @param string $purpose Booking purpose.
	 */
	private function sized_upload( string $name, int $size, string $purpose ): array {
		$path   = tempnam( sys_get_temp_dir(), 'ec-inquiry-' );
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test fixture.
		ftruncate( $handle, $size );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test fixture.
		$this->temporary_files[] = $path;
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => $size,
			'purpose'  => $purpose,
		);
	}

	/** Verify caller attribution and completed replay. */
	public function test_anonymous_and_authenticated_attribution_are_actual_and_replay_safe(): void {
		$authorization = new BookingTestAuthorization();
		$service       = $this->service( $authorization );
		$anonymous     = $service->admit( $this->input( array( $this->upload( 'press.txt', 'press', 'press_release' ) ) ), null );
		$this->assertSame( array( 'public_id', 'venue_term_id', 'submitted_at' ), array_keys( $anonymous ) );
		$this->assertSame( $anonymous, $service->admit( $this->input( array( $this->temporary_upload( 0, 'press.txt', 'press_release' ) ) ), null ) );
		$this->assertSame( 1, $this->provider->stage_count );

		$authenticated = $service->admit( $this->input( array( $this->upload( 'rider.txt', 'rider', 'technical_rider' ) ), 'authenticated-admission' ), 42 );
		$this->assertIsArray( $authenticated );
		$rows = array_values( $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
		$this->assertSame( 'anonymous', $rows[0]['uploader_type'] );
		$this->assertNull( $rows[0]['uploader_user_id'] );
		$this->assertSame( 'user', $rows[1]['uploader_type'] );
		$this->assertSame( 42, $rows[1]['uploader_user_id'] );
		$this->assertSame( array(), $authorization->calls, 'Inquiry submitters are attributed but are not venue operators.' );
	}

	/** Verify admission replay produces one lifecycle source and one outbox request. */
	public function test_exact_replay_has_one_inquiry_and_notification_side_effect(): void {
		$file    = $this->upload( 'press.txt', 'press', 'press_release' );
		$service = $this->service();
		$first   = $service->admit( $this->input( array( $file ), 'combined-side-effects' ) );
		$replay  = $service->admit( $this->input( array( $file ), 'combined-side-effects' ) );

		$this->assertSame( $first, $replay );
		$this->assertSame( 1, $this->provider->stage_count );
		$activities = array_values( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertCount( 1, array_filter( $activities, static function ( array $row ): bool { return 'inquiry_submitted' === $row['kind']; } ) );

		$notifications = new BookingNotificationService( null, new BookingActivityRepository() );
		$notifications->reconcile_pending();
		$notifications->reconcile_pending();
		$activities = array_values( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertCount( 1, array_filter( $activities, static function ( array $row ): bool { return 'notification_requested' === $row['kind']; } ) );
	}

	/** Verify overlapping exact attempts reuse the lock owner's complete result. */
	public function test_concurrent_exact_attempt_stages_only_one_winner(): void {
		$file    = $this->upload( 'press.txt', 'press', 'press_release' );
		$input   = $this->input( array( $file ), 'concurrent-exact' );
		$service = $this->service();
		$winner  = null;
		$GLOBALS['wpdb']->after_reference_lock = function () use ( $service, $input, &$winner ): void {
			$winner = $service->admit( $input );
		};

		$loser = $service->admit( $input );

		$this->assertSame( $winner, $loser );
		$this->assertSame( 1, $this->provider->stage_count );
		$this->assertCount( 1, $this->provider->objects );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
		$activities = array_values( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertCount( 1, array_filter( $activities, static function ( array $row ): bool { return 'inquiry_submitted' === $row['kind']; } ) );
	}

	/** A bounded lock loser replays a completed exact winner instead of failing. */
	public function test_lock_timeout_rechecks_and_replays_completed_exact_winner(): void {
		$input   = $this->input( array(), 'lock-timeout-replay' );
		$service = $this->service();
		$winner  = $service->admit( $input );

		$GLOBALS['wpdb']->get_lock_result = 0;
		$this->assertSame( $winner, $service->admit( $input ) );
	}

	/** An unfinished exact winner always produces explicit retry guidance. */
	public function test_lock_timeout_for_pending_exact_winner_is_retryable(): void {
		$input     = $this->input( array(), 'lock-timeout-pending' );
		$lifecycle = new BookingLifecycle();
		$reserved  = $lifecycle->reserve_inquiry( $input, null, array(), wp_generate_uuid4() );
		$this->assertIsArray( $reserved );

		$GLOBALS['wpdb']->get_lock_result = 0;
		$error                            = $this->service()->admit( $input );

		$this->assertSame( 'booking_inquiry_processing', $error->get_error_code() );
		$this->assertSame( 423, $error->get_error_data()['status'] );
		$this->assertTrue( $error->get_error_data()['retryable'] );
		$this->assertSame( 1, $error->get_error_data()['retry_after'] );
	}

	/** Verify reconciliation cannot observe an inquiry while its bytes are staging. */
	public function test_reconciliation_during_staging_sees_no_notification_source(): void {
		$observed = null;
		$this->provider->after_stage = static function () use ( &$observed ): void {
			$observed = ( new BookingNotificationService() )->reconcile_pending();
		};
		$result = $this->service()->admit( $this->input( array( $this->upload( 'press.txt', 'press', 'press_release' ) ), 'staging-overlap' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $observed['recovered'] );
		$activities = array_values( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertCount( 1, array_filter( $activities, static function ( array $row ): bool { return 'inquiry_submitted' === $row['kind']; } ) );
		$this->assertCount( 1, array_filter( $activities, static function ( array $row ): bool { return 'notification_requested' === $row['kind']; } ) );
	}

	/** Lossy aliases and truncation never enter a second lock domain. */
	public function test_ambiguous_idempotency_aliases_are_rejected_before_locking(): void {
		$service = $this->service();
		$winner  = $service->admit( $this->input( array(), 'foo' ) );
		$locks   = $GLOBALS['wpdb']->reference_lock_queries;

		$markup = $service->admit( $this->input( array(), '<b>foo</b>' ) );
		$long   = $service->admit( $this->input( array(), str_repeat( 'x', 192 ) ) );

		$this->assertIsArray( $winner );
		$this->assertSame( 'booking_idempotency_key_invalid', $markup->get_error_code() );
		$this->assertSame( 'booking_idempotency_key_invalid', $long->get_error_code() );
		$this->assertSame( $locks, $GLOBALS['wpdb']->reference_lock_queries );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
	}

	/** Operators cannot observe or mutate a reservation while staging overlaps. */
	public function test_operator_overlap_cannot_observe_or_mutate_reservation(): void {
		$observed = array();
		$this->provider->after_stage = static function () use ( &$observed ): void {
			$rows                = $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ];
			$reservation         = reset( $rows );
			$repository          = new BookingRepository();
			$observed['get']     = $repository->get( $reservation['id'] );
			$observed['list']    = $repository->list( array( 'venue_term_id' => 55 ) );
			$observed['mutate']  = ( new BookingLifecycle() )->assign( $reservation['id'], null, $reservation['version'], 10 );
			$observed['status']  = $reservation['status'];
		};

		$result = $this->service()->admit( $this->input( array( $this->upload( 'press.txt', 'press', 'press_release' ) ), 'hidden-reservation' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'admission_pending', $observed['status'] );
		$this->assertNull( $observed['get'] );
		$this->assertSame( array(), $observed['list'] );
		$this->assertSame( 'booking_not_found', $observed['mutate']->get_error_code() );
		$booking = reset( $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$this->assertSame( 'submitted', $booking['status'] );
		$this->assertNull( $booking['admission_owner_token'] );
	}

	/** Verify attachment bytes are part of exact inquiry idempotency. */
	public function test_changed_attachment_manifest_conflicts_without_restaging(): void {
		$service = $this->service();
		$this->assertIsArray( $service->admit( $this->input( array( $this->upload( 'press.txt', 'first', 'press_release' ) ), 'manifest-conflict' ) ) );
		$changed = $service->admit( $this->input( array( $this->upload( 'press.txt', 'changed', 'press_release' ) ), 'manifest-conflict' ) );

		$this->assertSame( 'booking_idempotency_conflict', $changed->get_error_code() );
		$this->assertSame( 1, $this->provider->stage_count );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
	}

	/** Verify unavailable private storage never falls back to another store. */
	public function test_disabled_private_storage_fails_closed_without_attachment_rows(): void {
		$bookings    = new BookingRepository();
		$attachments = new BookingAttachmentRepository();
		$service     = new BookingInquiryAdmissionService(
			new BookingLifecycle( $bookings ),
			$attachments,
			null,
			new WP_Error( 'booking_private_storage_unavailable' )
		);
		$error       = $service->admit( $this->input( array( $this->upload( 'press.txt', 'press', 'press_release' ) ), 'storage-disabled' ) );

		$this->assertSame( 'booking_inquiry_unavailable', $error->get_error_code() );
		$this->assertNoInquirySideEffects();
	}

	/**
	 * Rebuild an upload record for an existing temporary file.
	 *
	 * @param int    $index   Fixture index.
	 * @param string $name    Original filename.
	 * @param string $purpose Booking purpose.
	 */
	private function temporary_upload( int $index, string $name, string $purpose ): array {
		$path = $this->temporary_files[ $index ];
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $path ),
			'purpose'  => $purpose,
		);
	}

	/** Verify staging failure removes the complete inquiry before retry. */
	public function test_partial_multi_file_failure_resumes_only_missing_slots(): void {
		$files                         = array(
			$this->upload( 'press.txt', 'press', 'press_release' ),
			$this->upload( 'rider.txt', 'rider', 'technical_rider' ),
		);
		$this->provider->fail_stage_at = 2;
		$service                       = $this->service();
		$failed                        = $service->admit( $this->input( $files ) );
		$this->assertSame( 'booking_inquiry_unavailable', $failed->get_error_code() );
		$this->assertNoInquirySideEffects();
		$this->assertCount( 1, $this->provider->retired );

		$this->provider->fail_stage_at = 0;
		$replayed                      = $service->admit( $this->input( $files ) );
		$this->assertIsArray( $replayed );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
		$this->assertSame( 4, $this->provider->stage_count );
	}

	/** Verify a later claim failure compensates every committed side effect. */
	public function test_partial_attachment_failure_compensates_uncommitted_slots_and_resumes(): void {
		$files = array(
			$this->upload( 'press.txt', 'press', 'press_release' ),
			$this->upload( 'rider.txt', 'rider', 'technical_rider' ),
		);
		$this->provider->fail_claim_at = 2;
		$service                       = $this->service();
		$error                         = $service->admit( $this->input( $files, 'partial-claim' ) );
		$this->assertSame( 'booking_inquiry_unavailable', $error->get_error_code() );
		$this->assertNoInquirySideEffects();
		$this->assertCount( 2, $this->provider->retired );

		$this->provider->fail_claim_at = 0;
		$this->assertIsArray( $service->admit( $this->input( $files, 'partial-claim' ) ) );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
		$this->assertSame( 4, $this->provider->stage_count );
	}

	/** Verify confirmed database failures compensate both stores. */
	public function test_known_database_failure_releases_claim_and_retires_staged_object(): void {
		$this->provider->after_stage = static function () {
			$GLOBALS['wpdb']->fail_activity_inserts = true;
		};
		$error                       = $this->service()->admit( $this->input( array( $this->upload( 'press.txt', 'press', 'press_release' ) ) ) );
		$this->assertSame( 'booking_inquiry_unavailable', $error->get_error_code() );
		$this->assertCount( 1, $this->provider->released );
		$this->assertCount( 1, $this->provider->retired );
		$this->assertNoInquirySideEffects();
	}

	/** Verify uncertain commits never trigger destructive guesses. */
	public function test_booking_and_attachment_commit_uncertainty_never_guess_or_cleanup(): void {
		$files                                    = array( $this->upload( 'press.txt', 'press', 'press_release' ) );
		$GLOBALS['wpdb']->fail_transaction_commit = true;
		$booking_error                            = $this->service()->admit( $this->input( $files, 'booking-commit-uncertain' ) );
		$this->assertReconciliationErrorWithoutLeaks( $booking_error, $files[0]['tmp_name'] );
		$this->assertSame( 0, $this->provider->stage_count, 'Booking uncertainty occurs before staging.' );

		$GLOBALS['wpdb']             = new BookingWpdb();
		$this->provider              = new BookingTestPrivateFileProvider();
		$this->provider->after_stage = static function () {
			$GLOBALS['wpdb']->fail_transaction_commit = true;
		};
		$attachment_error            = $this->service()->admit( $this->input( $files, 'attachment-commit-uncertain' ) );
		$this->assertReconciliationErrorWithoutLeaks( $attachment_error, $files[0]['tmp_name'] );
		$this->assertSame( array(), $this->provider->retired );
		$this->assertCount( 1, $this->provider->claims );
	}

	/** Verify a committed result survives advisory unlock uncertainty and replay. */
	public function test_committed_unlock_uncertainty_replays_without_duplicate_or_cleanup(): void {
		$files                       = array( $this->upload( 'press.txt', 'press', 'press_release' ) );
		$this->provider->after_stage = static function () {
			$GLOBALS['wpdb']->fail_reference_unlock = true;
		};
		$service                     = $this->service();
		$error                       = $service->admit( $this->input( $files, 'unlock-uncertain' ) );
		$this->assertReconciliationErrorWithoutLeaks( $error, $files[0]['tmp_name'] );
		$this->assertSame( array(), $this->provider->retired );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );

		$GLOBALS['wpdb']->fail_reference_unlock                          = false;
		$GLOBALS['wpdb']->last_error                                     = '';
		$GLOBALS['wpdb']->ready                                          = true;
		$GLOBALS['wpdb']->reference_locks                                = array();
		$GLOBALS['extrachill_events_booking_reference_lock_uncertainty'] = array();
		$GLOBALS['extrachill_events_booking_database_connection_quarantined'] = false;
		$this->provider->after_stage = null;
		$this->assertIsArray( $this->service()->admit( $this->input( $files, 'unlock-uncertain' ) ) );
		$this->assertSame( 1, $this->provider->stage_count );
	}

	/** Verify failed cleanup exposes only a reconciliation marker. */
	public function test_cleanup_uncertainty_is_safe_and_does_not_leak_internal_data(): void {
		$this->provider->fail_retire = true;
		$this->provider->after_stage = static function () {
			$GLOBALS['wpdb']->fail_activity_inserts = true;
		};
		$file                        = $this->upload( 'press.txt', 'press', 'press_release' );
		$error                       = $this->service()->admit( $this->input( array( $file ), 'cleanup-uncertain' ) );
		$this->assertReconciliationErrorWithoutLeaks( $error, $file['tmp_name'] );
	}

	/** Verify all cheap bounds run before persistence or staging. */
	public function test_preflight_rejects_partial_unsupported_oversized_and_excess_files_before_work(): void {
		$partial          = $this->upload( 'press.txt', 'press', 'press_release' );
		$partial['error'] = UPLOAD_ERR_PARTIAL;
		$this->assertSame( 'booking_attachment_upload_failed', $this->service()->admit( $this->input( array( $partial ), 'partial' ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_attachment_type', $this->service()->admit( $this->input( array( $this->upload( 'press.exe', 'press', 'press_release' ) ), 'type' ) )->get_error_code() );
		$oversized = $this->sized_upload( 'press.txt', 21 * 1024 * 1024, 'press_release' );
		$this->assertSame( 'invalid_booking_attachment_size', $this->service()->admit( $this->input( array( $oversized ), 'size' ) )->get_error_code() );
		$aggregate = array_fill( 0, BookingInquiryAdmissionService::MAX_FILES, $this->sized_upload( 'press.txt', 11 * 1024 * 1024, 'press_release' ) );
		$this->assertSame( 'invalid_booking_attachment_aggregate_size', $this->service()->admit( $this->input( $aggregate, 'aggregate' ) )->get_error_code() );
		$too_many = array_fill( 0, BookingInquiryAdmissionService::MAX_FILES + 1, $this->upload( 'press.txt', 'press', 'press_release' ) );
		$this->assertSame( 'invalid_booking_attachment_count', $this->service()->admit( $this->input( $too_many, 'count' ) )->get_error_code() );
		$this->assertSame( 0, $this->provider->stage_count );
		$this->assertArrayNotHasKey( BookingSchema::bookings_table(), $GLOBALS['wpdb']->rows );
	}

	/** Verify current-prefix writes cannot run on the main site. */
	public function test_wrong_site_fails_before_main_prefix_write(): void {
		$GLOBALS['ec_artist_test']['blog_id'] = 1;
		$GLOBALS['wpdb']->prefix              = 'wp_';
		$error                                = $this->service()->admit( $this->input( array( $this->upload( 'press.txt', 'press', 'press_release' ) ) ) );
		$this->assertSame( 'booking_inquiry_unavailable', $error->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
		$this->assertSame( 0, $this->provider->stage_count );
	}

	/**
	 * Assert uncertainty remains actionable without leaking private internals.
	 *
	 * @param WP_Error $error Facade error.
	 * @param string   $path  Private source path.
	 */
	private function assertReconciliationErrorWithoutLeaks( WP_Error $error, string $path ): void {
		$this->assertSame( 'booking_inquiry_reconciliation_required', $error->get_error_code() );
		$this->assertSame(
			array(
				'status'                  => 503,
				'retryable'               => true,
				'reconciliation_required' => true,
			),
			$error->get_error_data()
		);
		$encoded = wp_json_encode( array( $error->get_error_code(), $error->get_error_message(), $error->get_error_data() ) );
		$this->assertStringNotContainsString( $path, $encoded );
		$this->assertStringNotContainsString( 'private_staged_object', $encoded );
		$this->assertStringNotContainsString( 'database_error', $encoded );
		$this->assertStringNotContainsString( 'lock_name', $encoded );
		$this->assertStringNotContainsString( 'sha256', $encoded );
		$this->assertStringNotContainsString( 'handoff', $encoded );
	}

	/** Assert known admission failures leave no domain or byte-store residue. */
	private function assertNoInquirySideEffects(): void {
		foreach ( array( BookingSchema::bookings_table(), BookingSchema::activity_table(), BookingSchema::attachments_table() ) as $table ) {
			$this->assertSame( array(), array_values( $GLOBALS['wpdb']->rows[ $table ] ?? array() ) );
		}
		$this->assertSame( array(), $this->provider->objects );
	}
}
