<?php
/**
 * Artist booking inquiry follow-through tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\ArtistBookingInquiryAbilities;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
use ExtraChillEvents\Core\ArtistBookingInquiryService;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingCommunicationService;
use ExtraChillEvents\Core\BookingCorrespondenceAutomationService;
use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingNotificationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;

require_once __DIR__ . '/Support/BookingTestHarness.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/ArtistBookingInquiryAbilities.php';

/** Proves artist access never opens the venue-private booking aggregate. */
final class ArtistBookingInquiryTest extends BookingTestCase {

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'         => 7,
			'current_user_id' => 0,
			'uuid'            => 0,
			'options'         => array(),
			'abilities'       => array(),
			'actions'         => array(),
			'fired_actions'   => array(),
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
					55 => array(
						'_extrachill_booking_config' => array(
							'enabled' => true,
							'spaces'  => array(
								array(
									'key'  => 'main-room',
									'name' => 'Main Room',
								),
							),
						),
						'_venue_timezone'            => 'America/New_York',
					),
				),
			),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
	}

	public function test_anonymous_admission_returns_stable_capability_only_on_exact_replay(): void {
		$abilities = new VenueBookingAbilities();
		$input     = array(
			'idempotency_key'     => 'anonymous-stable-receipt',
			'venue_term_id'       => 55,
			'artist_name'         => 'Anonymous Band',
			'contact_email'       => 'artist@example.test',
			'requested_space_key' => 'main-room',
			'requested_start_at'  => '2026-10-10 19:00:00',
			'requested_end_at'    => '2026-10-10 22:00:00',
			'intake'              => array(),
		);
		$first     = $abilities->create_inquiry( $input );
		$retry     = $abilities->create_inquiry( $input );
		$this->assertSame( $first, $retry );
		$this->assertSame( 64, strlen( $first['capability'] ) );
		$stored = reset( $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$this->assertArrayNotHasKey( 'capability', $stored );
		$this->assertNotSame( $stored['inquiry_request_hash'], $first['capability'] );

		$changed                = $input;
		$changed['artist_name'] = 'Changed Band';
		$this->assertSame( 'booking_idempotency_conflict', $abilities->create_inquiry( $changed )->get_error_code() );

		$GLOBALS['ec_artist_test']['current_user_id'] = 12;
		$authenticated                                = $abilities->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'authenticated-receipt' ) ) );
		$this->assertArrayNotHasKey( 'capability', $authenticated );
	}

	public function test_exact_authenticated_and_anonymous_authorization_rejects_guessing(): void {
		$anonymous = $this->booking();
		$token     = ArtistBookingInquiryService::capability_for( $anonymous );
		$service   = $this->service();
		$this->assertSame( $anonymous['public_id'], $service->status( $anonymous['public_id'], 55, $token, 0 )['public_id'] );
		$this->assertSame( 'booking_inquiry_forbidden', $service->status( $anonymous['public_id'], 54, $token, 0 )->get_error_code() );
		$this->assertSame( 'booking_inquiry_forbidden', $service->request_correction( $anonymous['public_id'], 54, $token, 0, 1, 'wrong-venue-correction', 'Correction' )->get_error_code() );
		$this->assertSame( 'booking_inquiry_forbidden', $service->withdraw( $anonymous['public_id'], 54, $token, 0, 1, 'wrong-venue-withdrawal' )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->lock_names );
		$this->assertSame( array(), ( new BookingActivityRepository() )->list_for_booking( $anonymous['id'] ) );
		$this->assertSame( 'booking_inquiry_forbidden', $service->status( $anonymous['public_id'], 55, str_repeat( '0', 64 ), 0 )->get_error_code() );
		$this->assertSame( 'booking_inquiry_forbidden', $service->status( '123e4567-e89b-42d3-a456-426614174000', 55, $token, 0 )->get_error_code() );

		$authenticated = $this->booking( array( 'submitter_user_id' => 44 ) );
		$this->assertSame( $authenticated['public_id'], $service->status( $authenticated['public_id'], 55, '', 44 )['public_id'] );
		$this->assertSame( 'booking_inquiry_forbidden', $service->status( $authenticated['public_id'], 55, ArtistBookingInquiryService::capability_for( $authenticated ), 45 )->get_error_code() );
	}

	public function test_projection_is_a_strict_allowlist_without_private_or_hash_material(): void {
		$booking   = $this->booking(
			array(
				'contact_name'        => 'Private Person',
				'contact_email'       => 'private@example.test',
				'contact_phone'       => '555-0100',
				'requested_space_key' => 'main-room',
				'requested_start_at'  => '2026-10-10 19:00:00',
				'requested_end_at'    => '2026-10-10 22:00:00',
			)
		);
		$projected = $this->service()->status( $booking['public_id'], 55, ArtistBookingInquiryService::capability_for( $booking ), 0 );
		$this->assertSame( array( 'public_id', 'venue_term_id', 'venue', 'submitted_at', 'updated_at', 'status', 'status_label', 'version', 'requested_interval', 'requested_space', 'permitted_actions' ), array_keys( $projected ) );
		$this->assertSame( 55, $projected['venue_term_id'] );
		$this->assertSame( array( 'name' ), array_keys( $projected['venue'] ) );
		$this->assertSame( array( 'key', 'label' ), array_keys( $projected['requested_space'] ) );
		$this->assertSame( array( 'request_correction', 'withdraw' ), $projected['permitted_actions'] );
		$json = wp_json_encode( $projected );
		foreach ( array( 'private@example.test', 'Private Person', '555-0100', 'inquiry_request_hash', 'idempotency', 'deal', 'attachment', 'scheduler', 'recipient' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_correction_is_bounded_versioned_idempotent_and_not_a_canonical_mutation(): void {
		$booking = $this->booking( array( 'submitter_user_id' => 44 ) );
		$service = $this->service();
		$first   = $service->request_correction( $booking['public_id'], 55, '', 44, 1, 'correction-1', 'Please change the requested load-in time.' );
		$this->assertSame( 'correction_requested', $first['operation'] );
		$this->assertSame( 55, $first['venue_term_id'] );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$this->assertSame( $first, $service->request_correction( $booking['public_id'], 55, '', 44, 1, 'correction-1', 'Please change the requested load-in time.' ) );
		$this->assertSame( 'booking_artist_idempotency_conflict', $service->request_correction( $booking['public_id'], 55, '', 44, 1, 'correction-1', 'Different correction.' )->get_error_code() );
		$this->assertSame( 'booking_version_conflict', $service->request_correction( $booking['public_id'], 55, '', 44, 2, 'correction-stale', 'Still stale.' )->get_error_code() );
		$this->assertSame( 'booking_artist_correction_invalid', $service->request_correction( $booking['public_id'], 55, '', 44, 1, 'correction-empty', ' ' )->get_error_code() );

		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$kinds      = array_column( $activities, 'kind' );
		$this->assertSame( 1, count( array_filter( $kinds, static fn( $kind ) => 'artist_correction_requested' === $kind ) ) );
		$this->assertContains( 'notification_requested', $kinds );
	}

	public function test_withdrawal_limits_stale_versions_hold_release_and_exact_retry(): void {
		foreach ( array( 'submitted', 'needs_info', 'under_review', 'negotiating', 'held' ) as $status ) {
			$booking = $this->booking();
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = $status;
			if ( 'held' === $status ) {
				$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['space_key']            = 'main-room';
				$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['performance_start_at'] = '2030-08-01 20:00:00';
				$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['performance_end_at']   = '2030-08-01 23:00:00';
				$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][1] = array(
					'id'         => 1,
					'booking_id' => $booking['id'],
					'status'     => 'active',
					'version'    => 1,
					'expires_at' => '2035-01-01 00:00:00',
				);
				$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][2] = array(
					'id'         => 2,
					'booking_id' => $booking['id'],
					'status'     => 'active',
					'version'    => 1,
					'expires_at' => '2020-01-01 00:00:00',
				);
			}
			$current = ( new BookingRepository() )->get( $booking['id'] );
			$token   = ArtistBookingInquiryService::capability_for( $current );
			$result  = $this->service()->withdraw( $current['public_id'], 55, $token, 0, 1, 'withdraw-' . $status );
			$this->assertSame( 'withdrawn', $result['status'], $status );
			$this->assertSame( 55, $result['venue_term_id'] );
			$this->assertSame( 2, $result['version'] );
			$this->assertSame( $result, $this->service()->withdraw( $current['public_id'], 55, $token, 0, 1, 'withdraw-' . $status ) );
			if ( 'held' === $status ) {
				$this->assertSame( 'released', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][1]['status'] );
				$this->assertSame( 'artist_withdrawn', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][1]['release_reason'] );
				$this->assertSame( 'active', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][2]['status'] );
				$acquired = array_values( array_filter( $GLOBALS['wpdb']->lock_names, static fn( array $lock ): bool => 'get' === $lock[0] ) );
				$this->assertSame( BookingHoldRepository::venue_lock_name( 55 ), $acquired[ count( $acquired ) - 2 ][1] );
				$this->assertSame( BookingHoldRepository::venue_space_lock_name( 55, 'main-room' ), $acquired[ count( $acquired ) - 1 ][1] );
				$this->assertContains( 2, $GLOBALS['wpdb']->transaction_start_reference_lock_counts, 'The withdrawal transaction must start inside both advisory locks.' );
			}
		}

		$stale = $this->booking();
		$locks_before_stale = count( $GLOBALS['wpdb']->lock_names );
		$this->assertSame( 'booking_version_conflict', $this->service()->withdraw( $stale['public_id'], 55, ArtistBookingInquiryService::capability_for( $stale ), 0, 2, 'withdraw-stale' )->get_error_code() );
		$this->assertCount( $locks_before_stale, $GLOBALS['wpdb']->lock_names, 'A stale snapshot must fail before touching advisory locks.' );
	}

	public function test_withdrawal_reloads_non_held_snapshot_and_locks_new_current_hold(): void {
		$booking    = $this->booking();
		$booking_id = (int) $booking['id'];
		$GLOBALS['wpdb']->after_reference_lock = static function () use ( $booking_id ): void {
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking_id ]['status']               = 'held';
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking_id ]['space_key']            = 'main-room';
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking_id ]['performance_start_at'] = '2030-08-01 20:00:00';
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking_id ]['performance_end_at']   = '2030-08-01 23:00:00';
			$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][1] = array(
				'id'         => 1,
				'booking_id' => $booking_id,
				'status'     => 'active',
				'version'    => 1,
				'expires_at' => '2035-01-01 00:00:00',
			);
		};
		$token  = ArtistBookingInquiryService::capability_for( $booking );
		$result = $this->service()->withdraw( $booking['public_id'], 55, $token, 0, 1, 'withdraw-raced-hold' );

		$this->assertSame( 'withdrawn', $result['status'] );
		$this->assertSame( 'released', $GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][1]['status'] );
		$this->assertSame( BookingHoldRepository::venue_lock_name( 55 ), $GLOBALS['wpdb']->lock_names[0][1] );
		$this->assertSame( BookingHoldRepository::venue_space_lock_name( 55, 'main-room' ), $GLOBALS['wpdb']->lock_names[1][1] );
		$this->assertContains( 2, $GLOBALS['wpdb']->transaction_start_reference_lock_counts );
	}

	public function test_confirmed_requests_cancellation_and_terminal_states_are_stable(): void {
		$confirmed = $this->booking( array( 'submitter_user_id' => 44 ) );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $confirmed['id'] ]['status'] = 'confirmed';
		$service = $this->service();
		$result  = $service->withdraw( $confirmed['public_id'], 55, '', 44, 1, 'cancel-confirmed' );
		$this->assertSame( 'cancellation_requested', $result['operation'] );
		$this->assertSame( 55, $result['venue_term_id'] );
		$this->assertSame( 'confirmed', ( new BookingRepository() )->get( $confirmed['id'] )['status'] );
		$this->assertSame( $result, $service->withdraw( $confirmed['public_id'], 55, '', 44, 1, 'cancel-confirmed' ) );

		foreach ( array( 'declined', 'withdrawn', 'cancelled', 'completed' ) as $status ) {
			$booking = $this->booking();
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = $status;
			$current = ( new BookingRepository() )->get( $booking['id'] );
			$token   = ArtistBookingInquiryService::capability_for( $current );
			$this->assertSame( 'booking_inquiry_terminal', $service->withdraw( $current['public_id'], 55, $token, 0, 1, 'terminal-' . $status )->get_error_code(), $status );
			$this->assertSame( 'booking_inquiry_terminal', $service->request_correction( $current['public_id'], 55, $token, 0, 1, 'correct-terminal-' . $status, 'Correction' )->get_error_code(), $status );
		}
	}

	public function test_withdrawal_records_reporting_source_and_retries_suppression_after_commit(): void {
		$booking       = $this->booking();
		$communication = new ArtistWithdrawalCommunicationFake( true );
		$service       = $this->service( null, $communication );
		$token         = ArtistBookingInquiryService::capability_for( $booking );
		$failed        = $service->withdraw( $booking['public_id'], 55, $token, 0, 1, 'withdraw-suppression' );
		$this->assertSame( 'booking_reminder_suppression_failed', $failed->get_error_code() );
		$this->assertSame( 'withdrawn', ( new BookingRepository() )->get( $booking['id'] )['status'] );

		$retry = $service->withdraw( $booking['public_id'], 55, $token, 0, 1, 'withdraw-suppression' );
		$this->assertSame( 'withdrawn', $retry['operation'] );
		$this->assertSame( 2, $communication->calls );
		$kinds = array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' );
		$this->assertSame( 1, count( array_filter( $kinds, static fn( $kind ) => 'status_changed' === $kind ) ) );
		$this->assertSame( 1, count( array_filter( $kinds, static fn( $kind ) => 'artist_withdrawn' === $kind ) ) );
	}

	public function test_receipt_recovery_hashes_complete_keys_and_rejects_stale_copy(): void {
		$booking = $this->booking(
			array(
				'requested_space_key' => 'main-room',
				'requested_start_at'  => '2030-08-01 20:00:00',
				'requested_end_at'    => '2030-08-01 23:00:00',
			)
		);
		$activity = new BookingActivityRepository();
		$activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'inquiry_submitted',
				'idempotency_key' => 'inquiry:' . $booking['inquiry_idempotency_key'],
			)
		);
		$queued        = array();
		$communication = new BookingCommunicationService(
			new BookingRepository(),
			$activity,
			null,
			static function ( array $input ) use ( &$queued ): array {
				$queued[] = $input;
				return array( 'success' => true, 'action_id' => 100 + count( $queued ) );
			},
			null,
			null,
			null,
			null,
			static function (): array {
				return array( 'owner@example.test' );
			}
		);
		$correspondence = new BookingCorrespondenceAutomationService( new BookingRepository(), $activity, $communication );
		$service        = $this->service( $correspondence, $communication );
		$capability     = ArtistBookingInquiryService::capability_for( $booking );
		$wrong_email = $service->resend_receipt( $booking['public_id'], 55, 'wrong@example.test', 'recover-wrong' );
		$wrong_venue = $service->resend_receipt( $booking['public_id'], 54, 'artist@example.test', 'recover-wrong-venue' );
		$unknown_id  = $service->resend_receipt( '123e4567-e89b-42d3-a456-426614174000', 55, 'artist@example.test', 'recover-unknown' );
		$this->assertSame( 'booking_receipt_recovery_forbidden', $wrong_email->get_error_code() );
		$this->assertSame( $wrong_email->get_error_code(), $unknown_id->get_error_code() );
		$this->assertSame( $wrong_email->get_error_code(), $wrong_venue->get_error_code() );
		$this->assertSame( $wrong_email->get_error_message(), $unknown_id->get_error_message() );
		$first_key  = str_repeat( 'a', 119 ) . 'x';
		$second_key = str_repeat( 'a', 119 ) . 'y';
		$first      = $service->resend_receipt( $booking['public_id'], 55, 'ARTIST@example.test', $first_key );
		$second     = $service->resend_receipt( $booking['public_id'], 55, 'artist@example.test', $second_key );
		$this->assertSame( 'receipt_resend_requested', $first['operation'] );
		$this->assertSame( 55, $first['venue_term_id'] );
		$this->assertSame( 'receipt_resend_requested', $second['operation'] );
		$this->assertSame( $first, $service->resend_receipt( $booking['public_id'], 55, 'artist@example.test', $first_key ) );
		$this->assertCount( 2, $queued );
		$this->assertStringContainsString( 'Access code: ' . $capability, $queued[0]['body'] );
		$this->assertStringContainsString( 'does not indicate', $queued[0]['body'] );
		$this->assertStringNotContainsString( 'pending review', $queued[0]['body'] );
		$this->assertStringContainsString( 'private booking inquiry access', strtolower( $queued[0]['subject'] ) );
		$this->assertSame( '', $queued[0]['cc'] );
		$this->assertStringNotContainsString( $capability, $queued[0]['subject'] );
		$intents = array_values( array_filter( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ], static fn( $row ) => 'booking_message_requested' === $row['kind'] ) );
		$this->assertCount( 2, $intents );
		$this->assertNotSame( $intents[0]['idempotency_key'], $intents[1]['idempotency_key'] );
		$this->assertStringNotContainsString( $first_key, wp_json_encode( $intents ) );
		$this->assertStringNotContainsString( $capability, wp_json_encode( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] ) );
		$operator_projection = array_map( array( new VenueBookingAbilities(), 'present_activity' ), $activity->list_for_booking( $booking['id'] ) );
		$this->assertStringNotContainsString( $capability, wp_json_encode( $operator_projection ) );
		$first_identity = 'artist-recovery:' . hash_hmac( 'sha256', $first_key, wp_salt( 'auth' ) );
		$this->assertSame( 'booking_message_idempotency_conflict', $communication->request_automatic( $booking['id'], $activity->find_by_idempotency( $booking['id'], 'inquiry:' . $booking['inquiry_idempotency_key'] )['id'], 'inquiry_access_recovery', 'Different receipt copy.', $first_identity )->get_error_code() );
		$this->assertSame( 'booking_artist_idempotency_key_invalid', $service->resend_receipt( $booking['public_id'], 55, 'artist@example.test', ' recover-space ' )->get_error_code() );

		foreach ( array( 'under_review', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ) as $status ) {
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = $status;
			$recovered = $service->resend_receipt( $booking['public_id'], 55, 'artist@example.test', 'recover-' . $status );
			$this->assertSame( 'receipt_resend_requested', $recovered['operation'], $status );
		}
		$this->assertCount( 8, $queued );
	}

	public function test_operator_activity_exposes_only_explicit_correction_detail(): void {
		$booking  = $this->booking();
		$activity = new BookingActivityRepository();
		foreach (
			array(
				'artist_correction_requested'   => array( 'correction' => 'Please correct the load-in time.', 'operation_hash' => str_repeat( 'a', 64 ), 'result' => array( 'private' => true ) ),
				'artist_cancellation_requested' => array( 'correction' => 'must-not-leak', 'operation_hash' => str_repeat( 'b', 64 ) ),
				'artist_withdrawn'              => array( 'correction' => 'must-not-leak', 'actor_identity' => 44 ),
			) as $kind => $payload
		) {
			$activity->append( array( 'booking_id' => $booking['id'], 'kind' => $kind, 'payload' => $payload ) );
		}
		$GLOBALS['ec_artist_test']['current_user_id'] = 12;
		$abilities = new VenueBookingAbilities( new BookingRepository(), null, new BookingTestAuthorization() );
		$result    = $abilities->get_booking_activity( array( 'booking_id' => $booking['id'] ) );
		$rows      = array_column( $result['activity'], null, 'kind' );
		$this->assertSame( 'Please correct the load-in time.', $rows['artist_correction_requested']['artist_request_detail'] );
		$this->assertSame( array( 'id', 'kind', 'occurred_at' ), array_keys( $rows['artist_cancellation_requested'] ) );
		$this->assertSame( array( 'id', 'kind', 'occurred_at' ), array_keys( $rows['artist_withdrawn'] ) );
		$json = wp_json_encode( $result['activity'] );
		$this->assertStringNotContainsString( 'operation_hash', $json );
		$this->assertStringNotContainsString( 'must-not-leak', $json );
		$this->assertStringNotContainsString( 'actor_identity', $json );

		$abilities->register();
		$item = $GLOBALS['ec_artist_test']['abilities']['extrachill/get-venue-booking-activity']['output_schema']['properties']['activity']['items'];
		$this->assertArrayHasKey( 'artist_request_detail', $item['properties'] );
		$this->assertNotContains( 'artist_request_detail', $item['required'] );
		$this->assertFalse( $item['additionalProperties'] );
	}

	public function test_artist_abilities_are_hidden_and_recovery_has_no_forgeable_attestations(): void {
		$abilities = new ArtistBookingInquiryAbilities( $this->service() );
		$abilities->register();
		$this->assertSame( array( 'extrachill-events/get-artist-booking-inquiry', 'extrachill-events/request-artist-booking-correction', 'extrachill-events/withdraw-artist-booking-inquiry', 'extrachill-events/recover-artist-booking-inquiry-receipt' ), array_keys( $GLOBALS['ec_artist_test']['abilities'] ) );
		foreach ( $GLOBALS['ec_artist_test']['abilities'] as $name => $definition ) {
			$this->assertFalse( $definition['meta']['show_in_rest'] );
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			$this->assertFalse( $definition['output_schema']['additionalProperties'] );
			$this->assertArrayNotHasKey( 'contact_verified', $definition['input_schema']['properties'] );
			$this->assertArrayNotHasKey( 'rate_limit_consumed', $definition['input_schema']['properties'] );
			$this->assertContains( 'venue_term_id', $definition['input_schema']['required'], $name );
		}
		$status_schema = $GLOBALS['ec_artist_test']['abilities']['extrachill-events/get-artist-booking-inquiry']['output_schema'];
		$this->assertSame( BookingRepository::STATUSES, $status_schema['properties']['status']['enum'] );
		$this->assertContains( 'venue_term_id', $status_schema['required'] );
		$correction_schema = $GLOBALS['ec_artist_test']['abilities']['extrachill-events/request-artist-booking-correction']['output_schema'];
		$this->assertSame( array( 'public_id', 'venue_term_id', 'operation', 'version' ), $correction_schema['required'] );
		$this->assertSame( array( 'correction_requested' ), $correction_schema['properties']['operation']['enum'] );
		$withdrawal_schema = $GLOBALS['ec_artist_test']['abilities']['extrachill-events/withdraw-artist-booking-inquiry']['output_schema'];
		$this->assertSame( array( 'public_id', 'venue_term_id', 'operation', 'version' ), $withdrawal_schema['required'] );
		$this->assertSame( array( 'withdrawn', 'cancellation_requested' ), $withdrawal_schema['properties']['operation']['enum'] );
		$recovery = $GLOBALS['ec_artist_test']['abilities']['extrachill-events/recover-artist-booking-inquiry-receipt'];
		$this->assertSame( array( 'public_id', 'venue_term_id', 'contact_email', 'idempotency_key' ), $recovery['input_schema']['required'] );
		$this->assertSame( '__return_true', $recovery['permission_callback'] );
		$this->assertSame( array( 'public_id', 'venue_term_id', 'operation' ), $recovery['output_schema']['required'] );
		$this->assertSame( array( 'receipt_resend_requested' ), $recovery['output_schema']['properties']['operation']['enum'] );
	}

	private function booking( array $overrides = array() ): array {
		return ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id'           => 55,
					'artist_name'             => 'Test Artist',
					'contact_email'           => 'artist@example.test',
					'inquiry_idempotency_key' => 'inquiry-' . wp_generate_uuid4(),
					'inquiry_request_hash'    => hash( 'sha256', wp_generate_uuid4() ),
					'intake'                  => array( 'message' => 'Booking request' ),
				),
				$overrides
			)
		);
	}

	private function service( ?BookingCorrespondenceAutomationService $correspondence = null, ?BookingCommunicationService $communication = null ): ArtistBookingInquiryService {
		$bookings = new BookingRepository();
		$activity = new BookingActivityRepository();
		return new ArtistBookingInquiryService( $bookings, new BookingLifecycle( $bookings, $activity ), $activity, null, new BookingNotificationService( $bookings, $activity ), $correspondence, null, $communication );
	}
}

/** Simulates one uncertain post-commit suppression result. */
final class ArtistWithdrawalCommunicationFake extends BookingCommunicationService {
	public $calls = 0;
	private $fail_once;

	public function __construct( bool $fail_once ) {
		$this->fail_once = $fail_once;
	}

	public function suppress_pending_reminders( int $booking_id, string $reason, int $after_intent_id = 0 ) {
		unset( $booking_id, $reason, $after_intent_id );
		++$this->calls;
		if ( $this->fail_once ) {
			$this->fail_once = false;
			return new WP_Error( 'booking_reminder_suppression_failed' );
		}
		return true;
	}
}
