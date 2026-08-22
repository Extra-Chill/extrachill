<?php
/**
 * Booking notification outbox tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingNotificationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** Covers recovery, authorization, and structured receipt reconciliation. */
final class BookingNotificationTest extends BookingTestCase {
	/** Reset booking persistence. */
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id' => 7,
			'stack'   => array(),
			'uuid'    => 0,
			'terms'   => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'    => array(),
			'posts'   => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
	}

	/** Create one private booking fixture. */
	private function booking(): array {
		return ( new BookingRepository() )->create(
			array(
				'venue_term_id' => 55,
				'artist_name'   => 'Private Artist',
				'contact_name'  => 'Private Contact',
				'contact_email' => 'private@example.com',
				'contact_phone' => '555-0100',
				'intake'        => array( 'private_note' => 'not for notifications' ),
			)
		);
	}

	/** Append one source activity fixture. */
	private function source( array $booking, string $kind = 'inquiry_submitted', array $payload = array( 'status' => 'submitted' ) ): array {
		return ( new BookingActivityRepository() )->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => $kind,
				'actor_type' => 'anonymous',
				'payload'    => $payload,
			)
		);
	}

	/** Add one membership fixture. */
	private function member( int $id, int $venue, int $user, string $status, bool $owner ): void {
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][ $id ] = array(
			'id'                 => $id,
			'venue_term_id'      => $venue,
			'user_id'            => $user,
			'is_owner'           => $owner ? 1 : 0,
			'status'             => $status,
			'version'            => 1,
			'created_by_user_id' => 1,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_at'         => VenueAuthorization::STATUS_REVOKED === $status ? '2026-01-02 00:00:00' : null,
		);
	}

	/** Build an authorized destination resolver that follows canonical policy. */
	private function destination( array &$resolved ): callable {
		$authorization = new BookingTestAuthorization(
			array(
				'10:55' => true,
				'11:55' => true,
			)
		);
		return static function ( array $booking, array $recipient_ids, array $members ) use ( &$resolved, $authorization ) {
			foreach ( $recipient_ids as $recipient_id ) {
				$allowed = $authorization->authorize_locked( $recipient_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE, $members );
				if ( true !== $allowed ) {
					return $allowed;
				}
			}
			$resolved[] = array(
				'booking_id' => $booking['id'],
				'recipients' => $recipient_ids,
			);
			return 'https://events.example/manage/booking/' . rawurlencode( $booking['public_id'] );
		};
	}

	/** Move one request's persisted retry due time into the past. */
	private function make_retry_due( int $request_id ): void {
		foreach ( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] as &$row ) {
			if ( 'notification_delivery_attempted' === $row['kind'] && (string) $request_id === (string) $row['external_id'] ) {
				$row['occurred_at'] = '2020-01-01 00:00:00';
			}
		}
		unset( $row );
	}

	/** Source recovery creates an idempotent outbox request after emit failure. */
	public function test_recovery_creates_missing_request_once(): void {
		$booking = $this->booking();
		$ignored = $this->source( $booking, 'status_changed', array( 'from_status' => 'submitted', 'to_status' => 'under_review' ) );
		$source  = $this->source( $booking );
		$service = new BookingNotificationService();

		$first  = $service->reconcile_pending();
		$second = $service->reconcile_pending();

		$this->assertSame( 1, $first['recovered'] );
		$this->assertSame( 0, $second['recovered'] );
		$requests = array_values(
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ],
				static function ( $row ) {
					return 'notification_requested' === $row['kind'];
				}
			)
		);
		$this->assertCount( 1, $requests );
		$this->assertSame( (string) $source['id'], $requests[0]['external_id'] );
		$ignored_rows = array_values( array_filter( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ], static function ( $row ) { return 'notification_source_ignored' === $row['kind']; } ) );
		$this->assertSame( (string) $ignored['id'], $ignored_rows[0]['external_id'] );
	}

	/** Crash recovery recognizes every landed artist follow-through source. */
	public function test_recovery_recognizes_artist_follow_through_sources(): void {
		$booking = $this->booking();
		$sources = array(
			BookingNotificationService::TYPE_ARTIST_CORRECTION_REQUESTED   => $this->source( $booking, 'artist_correction_requested', array( 'expected_version' => 1 ) ),
			BookingNotificationService::TYPE_ARTIST_CANCELLATION_REQUESTED => $this->source( $booking, 'artist_cancellation_requested', array( 'expected_version' => 1 ) ),
			BookingNotificationService::TYPE_ARTIST_WITHDREW                => $this->source( $booking, 'artist_withdrawn', array( 'from_status' => 'submitted', 'to_status' => 'withdrawn' ) ),
		);
		$summary = ( new BookingNotificationService() )->reconcile_pending();
		$this->assertSame( 3, $summary['recovered'] );
		foreach ( $sources as $type => $source ) {
			$request = ( new BookingActivityRepository() )->find_by_external_id( $booking['id'], 'notification_requested', (string) $source['id'] );
			$this->assertSame( $type, $request['payload']['data']['notification_type'] );
		}
	}

	/** Production reconciliation calls the landed Users receipt contract. */
	public function test_production_users_receipt_payload_is_idempotent_and_complete(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$captured = array();
		$GLOBALS['ec_artist_test']['users_receipt'] = static function ( array $recipients, array $payload ) use ( &$captured ): array {
			$captured = compact( 'recipients', 'payload' );
			return array( 'recipients' => array( 10 => array( 'status' => 'inserted', 'notification_id' => 100 ) ) );
		};
		$result = ( new BookingNotificationService( null, null, null, null, static function (): int { return 99; } ) )->reconcile( $request['id'] );

		$this->assertSame( 'notification_delivered', $result['kind'] );
		$this->assertSame( array( 10 ), $captured['recipients'] );
		$this->assertSame( 'extrachill-events-booking', $captured['payload']['producer'] );
		$this->assertSame( 'notification-request:booking_inquiry_submitted:' . $source['id'], $captured['payload']['idempotency_key'] );
		$this->assertSame( 'https://events.example/venue/55', $captured['payload']['link'] );
	}

	/** Partial and zero delivery receipts remain retryable until every user resolves. */
	public function test_partial_and_zero_receipts_retry_safely_to_completion(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 2, 55, 11, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 3, 55, 12, VenueAuthorization::STATUS_INVITED, false );
		$this->member( 4, 77, 13, VenueAuthorization::STATUS_ACTIVE, true );
		$request  = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$resolved = array();
		$calls    = 0;
		$delivery = function ( array $recipients, array $payload, string $producer, string $key ) use ( &$calls ): array {
			++$calls;
			$this_call = $calls;
			if ( 1 === $this_call ) {
				return array(
					'recipients' => array(
						10 => array( 'status' => 'failed' ),
						11 => array( 'status' => 'failed' ),
					),
				);
			}
			if ( 2 === $this_call ) {
				return array(
					'recipients' => array(
						10 => array(
							'status'          => 'inserted',
							'notification_id' => 100,
						),
						11 => array( 'status' => 'failed' ),
					),
				);
			}
			$this->assertSame( array( 10, 11 ), $recipients );
			$this->assertSame( 'extrachill-events-booking', $producer );
			$this->assertStringStartsWith( 'notification-request:', $key );
			$this->assertStringNotContainsString( 'Private', wp_json_encode( $payload ) );
			return array(
				'recipients' => array(
					10 => array(
						'status'          => 'existing',
						'notification_id' => 100,
					),
					11 => array(
						'status'          => 'inserted',
						'notification_id' => 101,
					),
				),
			);
		};
		$service  = new BookingNotificationService(
			null,
			null,
			$delivery,
			$this->destination( $resolved ),
			static function (): int {
				return 99;
			}
		);

		$zero     = $service->reconcile( $request['id'] );
		$this->make_retry_due( $request['id'] );
		$partial  = $service->reconcile( $request['id'] );
		$this->make_retry_due( $request['id'] );
		$complete = $service->reconcile( $request['id'] );
		$replay   = $service->reconcile( $request['id'] );

		$this->assertSame( 'notification_delivery_attempted', $zero['kind'] );
		$this->assertSame( 2, $zero['payload']['data']['failed_count'] );
		$this->assertSame( 'notification_delivery_attempted', $partial['kind'] );
		$this->assertSame( 'notification_delivered', $complete['kind'] );
		$this->assertSame( $complete['id'], $replay['id'] );
		$this->assertSame( 3, $calls );
		$this->assertCount( 3, $resolved );
	}

	/** Every operational event selects active owners instead of other members. */
	public function test_recipient_policy_targets_active_owners(): void {
		$booking = $this->booking();
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 2, 55, 11, VenueAuthorization::STATUS_ACTIVE, false );
		$this->member( 3, 55, 12, VenueAuthorization::STATUS_ACTIVE, false );
		$deliveries = array();
		$resolved   = array();
		$service    = new BookingNotificationService(
			null,
			null,
			static function ( array $recipients ) use ( &$deliveries ): array {
				$deliveries[] = $recipients;
				$rows = array();
				foreach ( $recipients as $recipient ) {
					$rows[ $recipient ] = array( 'status' => 'inserted' );
				}
				return array( 'recipients' => $rows );
			},
			$this->destination( $resolved ),
			static function (): int { return 99; }
		);
		$inquiry     = $this->source( $booking );
		$information = $this->source( $booking, 'status_changed', array( 'from_status' => 'needs_info', 'to_status' => 'submitted' ) );
		$expired     = $this->source( $booking, 'hold_expired' );

		$service->reconcile( $service->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $inquiry['id'] )['id'] );
		$service->reconcile( $service->request( BookingNotificationService::TYPE_INFORMATION_RECEIVED, $information['id'] )['id'] );
		$service->reconcile( $service->request( BookingNotificationService::TYPE_HOLD_EXPIRED, $expired['id'] )['id'] );

		$this->assertSame( array( array( 10 ), array( 10 ), array( 10 ) ), $deliveries );
	}

	/** Permanent dependency failures become terminal so later requests can advance. */
	public function test_permanent_failure_is_poisoned_after_bounded_attempts(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$service = new BookingNotificationService( null, null, null, static function () { return new WP_Error( 'permanent_route_failure' ); } );
		for ( $attempt = 0; $attempt < BookingNotificationService::MAX_ATTEMPTS; ++$attempt ) {
			$service->reconcile_pending();
			$this->make_retry_due( $request['id'] );
		}
		$terminal = ( new BookingActivityRepository() )->notification_terminal( $request['id'] );
		$this->assertSame( 'notification_suppressed', $terminal['kind'] );
		$this->assertSame( 'delivery_poisoned', $terminal['payload']['data']['reason'] );
		$this->assertSame( BookingNotificationService::MAX_ATTEMPTS, $terminal['payload']['data']['attempt'] );
	}

	/** A deferred poison request does not block a newer valid notification. */
	public function test_deferred_poison_request_does_not_starve_newer_work(): void {
		$booking = $this->booking();
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$old_source  = $this->source( $booking );
		$old_request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $old_source['id'] );
		$failed      = new BookingNotificationService( null, null, null, static function () { return new WP_Error( 'permanent_route_failure' ); } );
		$failed->reconcile_pending();

		$new_source  = $this->source( $booking, 'status_changed', array( 'from_status' => 'needs_info', 'to_status' => 'submitted' ) );
		$new_request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INFORMATION_RECEIVED, $new_source['id'] );
		$resolved    = array();
		$delivered   = new BookingNotificationService(
			null,
			null,
			static function (): array { return array( 'recipients' => array( 10 => array( 'status' => 'inserted' ) ) ); },
			$this->destination( $resolved ),
			static function (): int { return 99; }
		);
		$delivered->reconcile_pending();

		$this->assertNull( ( new BookingActivityRepository() )->notification_terminal( $old_request['id'] ) );
		$this->assertSame( 'notification_delivered', ( new BookingActivityRepository() )->notification_terminal( $new_request['id'] )['kind'] );
	}

	/** Overlapping workers cannot create duplicate delivery attempts. */
	public function test_request_lock_rejects_overlapping_reconciliation(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request  = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$overlap  = null;
		$service  = null;
		$resolved = array();
		$delivery = function () use ( &$overlap, &$service, $request ): array {
			$GLOBALS['wpdb']->get_lock_result = 0;
			$overlap = $service->reconcile( $request['id'] );
			$GLOBALS['wpdb']->get_lock_result = 1;
			return array( 'recipients' => array( 10 => array( 'status' => 'inserted' ) ) );
		};
		$service = new BookingNotificationService( null, null, $delivery, $this->destination( $resolved ), static function (): int { return 99; } );
		$result  = $service->reconcile( $request['id'] );

		$this->assertSame( 'booking_notification_request_busy', $overlap->get_error_code() );
		$this->assertSame( 'notification_delivered', $result['kind'] );
		$this->assertSame( 0, ( new BookingActivityRepository() )->notification_attempt_count( $request['id'] ) );
	}

	/** Two failing workers produce one monotonic durable attempt. */
	public function test_two_worker_failure_race_records_once_under_lock(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request  = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$overlap  = null;
		$service  = null;
		$resolved = array();
		$delivery = function () use ( &$overlap, &$service, $request ) {
			$GLOBALS['wpdb']->get_lock_result = 0;
			$overlap = $service->reconcile( $request['id'] );
			$GLOBALS['wpdb']->get_lock_result = 1;
			return new WP_Error( 'dependency_failed' );
		};
		$service = new BookingNotificationService( null, null, $delivery, $this->destination( $resolved ), static function (): int { return 99; } );
		$result  = $service->reconcile( $request['id'] );

		$this->assertSame( 'booking_notification_request_busy', $overlap->get_error_code() );
		$this->assertSame( 'notification_delivery_attempted', $result['kind'] );
		$this->assertSame( 1, ( new BookingActivityRepository() )->notification_attempt_count( $request['id'] ) );
	}

	/** A failed owner durably defers before a later worker can succeed. */
	public function test_failure_then_success_race_rechecks_due_and_terminal_state(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request  = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$resolved = array();
		$failure  = new BookingNotificationService( null, null, static function () { return new WP_Error( 'dependency_failed' ); }, $this->destination( $resolved ), static function (): int { return 99; } );
		$attempt  = $failure->reconcile( $request['id'] );
		$too_soon = ( new BookingNotificationService( null, null, static function (): array { return array( 'recipients' => array( 10 => array( 'status' => 'inserted' ) ) ); }, $this->destination( $resolved ), static function (): int { return 99; } ) )->reconcile( $request['id'] );
		$this->make_retry_due( $request['id'] );
		$success = ( new BookingNotificationService( null, null, static function (): array { return array( 'recipients' => array( 10 => array( 'status' => 'inserted' ) ) ); }, $this->destination( $resolved ), static function (): int { return 99; } ) )->reconcile( $request['id'] );
		$replay  = $failure->reconcile( $request['id'] );

		$this->assertSame( 'notification_delivery_attempted', $attempt['kind'] );
		$this->assertSame( 'booking_notification_retry_not_due', $too_soon->get_error_code() );
		$this->assertSame( 'notification_delivered', $success['kind'] );
		$this->assertSame( $success['id'], $replay['id'] );
		$this->assertSame( 1, ( new BookingActivityRepository() )->notification_attempt_count( $request['id'] ) );
	}

	/** Revocation between attempts suppresses the former owner before retry delivery. */
	public function test_revocation_race_reselects_exact_active_recipients(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request    = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$deliveries = array();
		$resolved   = array();
		$service    = new BookingNotificationService(
			null,
			null,
			static function ( array $recipients ) use ( &$deliveries ): array {
				$deliveries[] = $recipients;
				$rows         = array();
				foreach ( $recipients as $recipient ) {
					$rows[ $recipient ] = array( 'status' => 1 === count( $deliveries ) ? 'failed' : 'existing' );
				}
				return array( 'recipients' => $rows );
			},
			$this->destination( $resolved ),
			static function (): int {
				return 99; }
		);

		$service->reconcile( $request['id'] );
		$this->make_retry_due( $request['id'] );
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][1]['status'] = VenueAuthorization::STATUS_REVOKED;
		$terminal = $service->reconcile( $request['id'] );

		$this->assertSame( array( array( 10 ) ), $deliveries );
		$this->assertSame( 'notification_suppressed', $terminal['kind'] );
		$this->assertSame( 'no_active_recipients', $terminal['payload']['data']['reason'] );
	}

	/** Recovery emits only the four current landed event mappings. */
	public function test_landed_and_future_source_seams_remain_explicit(): void {
		$booking = $this->booking();
		$events  = array(
			array( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, 'inquiry_submitted', array() ),
			array(
				BookingNotificationService::TYPE_INFORMATION_RECEIVED,
				'status_changed',
				array(
					'from_status' => 'needs_info',
					'to_status'   => 'submitted',
				),
			),
			array( BookingNotificationService::TYPE_HOLD_EXPIRED, 'hold_expired', array() ),
			array( BookingNotificationService::TYPE_EVENT_HANDOFF_FAILED, 'event_conversion_failed', array() ),
		);
		$service = new BookingNotificationService();
		foreach ( $events as $event ) {
			$source = $this->source( $booking, $event[1], $event[2] );
			$this->assertSame( $event[0], $service->request( $event[0], $source['id'] )['payload']['data']['notification_type'] );
		}
		$future = $this->source( $booking, 'settlement_ready', array() );
		$this->assertSame( BookingNotificationService::TYPE_SETTLEMENT_READY, $service->request( BookingNotificationService::TYPE_SETTLEMENT_READY, $future['id'] )['payload']['data']['notification_type'] );
	}

	/** Retired assignment outbox rows become terminal without delivery. */
	public function test_retired_assignment_notification_state_is_suppressed(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'assignment_changed' );
		$summary = ( new BookingNotificationService() )->reconcile_pending();
		$ignored = ( new BookingActivityRepository() )->find_by_external_id( $booking['id'], 'notification_source_ignored', (string) $source['id'] );
		$this->assertSame( 0, $summary['recovered'] );
		$this->assertIsArray( $ignored );
		$request = ( new BookingActivityRepository() )->append(
			array(
				'booking_id'  => $booking['id'],
				'kind'        => 'notification_requested',
				'external_id' => (string) $source['id'],
				'payload'     => array(
					'notification_type'  => 'booking_assignment_changed',
					'source_activity_id' => $source['id'],
				),
			)
		);

		$terminal = ( new BookingNotificationService() )->reconcile( $request['id'] );
		$this->assertSame( 'notification_suppressed', $terminal['kind'] );
		$this->assertSame( 'retired_notification_type', $terminal['payload']['data']['reason'] );
	}
}
