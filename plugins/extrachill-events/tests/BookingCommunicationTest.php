<?php
/**
 * Booking communication and reminder tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueBookingCommunicationAbilities;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
use ExtraChillEvents\Abilities\VenueBookingMutationAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingCommunicationService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingMutationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingCommunicationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
			'abilities'     => array(),
			'ability_objects' => array(),
			'actions'       => array(),
			'fired_actions' => array(),
			'scheduled'     => array(),
			'cache_deletes' => array(),
			'terms'         => array(
				7 => array(
					55 => (object) array( 'term_id' => 55, 'taxonomy' => 'venue', 'name' => 'The Room' ),
				),
			),
			'meta'          => array(
				7 => array(
					55 => array(
						'_venue_timezone' => 'America/New_York',
						VenueBookingConfig::META_KEY => array( 'enabled' => true ),
					),
				),
			),
			'posts'         => array(),
			'post_meta'     => array(),
		);
		$GLOBALS['wpdb'] = new BookingWpdb();
	}

	private function booking( string $email = 'artist@example.com', string $status = 'under_review' ): array {
		$booking = ( new BookingRepository() )->create(
			array(
				'venue_term_id' => 55,
				'artist_name'   => 'Test Band',
				'contact_name'  => 'Artist Agent',
				'contact_email' => $email,
				'intake'        => array(),
			)
		);
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = $status;
		$booking['status'] = $status;
		return $booking;
	}

	private function input( int $booking_id, array $overrides = array() ): array {
		return array_merge(
			array(
				'booking_id'        => $booking_id,
				'idempotency_key'   => 'message-1',
				'template'          => 'operator_message',
				'recipient'         => 'artist@example.com',
				'subject'           => 'Booking update',
				'message'           => 'We would like to discuss the date.',
				'reply_to'          => 'bookings@extrachill.com',
				'expected_statuses' => array(),
			),
			$overrides
		);
	}

	private function service( array &$queued, array &$scheduled, array &$cancelled, ?BookingTestAuthorization $authorization = null, bool $queue_success = true ): BookingCommunicationService {
		return new BookingCommunicationService(
			null,
			null,
			$authorization ? $authorization : new BookingTestAuthorization(),
			static function ( array $input ) use ( &$queued, $queue_success ) {
				$queued[] = $input;
				return $queue_success ? array( 'success' => true, 'action_id' => count( $queued ) + 100 ) : array( 'success' => false, 'error' => 'simulated failure' );
			},
			static function ( int $timestamp, string $hook, array $args, string $group ) use ( &$scheduled ) {
				$scheduled[] = compact( 'timestamp', 'hook', 'args', 'group' );
				return count( $scheduled ) + 200;
			},
			static function ( int $action_id ) use ( &$cancelled ) {
				$cancelled[] = $action_id;
			}
		);
	}

	public function test_schema_indexes_durable_communication_state_and_audit_reads(): void {
		$this->assertTrue( BookingSchema::install() );
		$activity_indexes = $GLOBALS['wpdb']->schemas[ BookingSchema::activity_table() ]['indexes'];
		$state_indexes    = $GLOBALS['wpdb']->schemas[ BookingSchema::communication_state_table() ]['indexes'];
		$this->assertSame( array( 'booking_id', 'is_communication', 'occurred_at', 'id' ), $activity_indexes['booking_communication_occurred']['columns'] );
		$this->assertSame( array( 'communication_intent_id', 'kind' ), $activity_indexes['communication_intent_kind']['columns'] );
		$this->assertSame( array( 'booking_id', 'status', 'intent_id' ), $state_indexes['booking_status_intent']['columns'] );
	}

	public function test_immediate_request_is_durable_before_safe_data_machine_delegation(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$result  = $this->service( $queued, $scheduled, $cancelled )->request( $this->input( $booking['id'] ), 12 );

		$this->assertSame( 'queued', $result['status'] );
		$this->assertCount( 1, $queued );
		$this->assertSame( 'chubes@extrachill.com', $queued[0]['cc'] );
		$this->assertSame( 'Extra Chill Bot', $queued[0]['from_name'] );
		$this->assertStringContainsString( "Extra Chill Bot sending on Chris's behalf.", $queued[0]['body'] );
		$this->assertArrayNotHasKey( 'attachments', $queued[0] );
		$this->assertArrayNotHasKey( 'credentials', $queued[0] );
		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertSame( array( 'booking_message_queued', 'booking_message_dispatching', 'booking_message_requested' ), array_column( $activity, 'kind' ) );
		$this->assertArrayNotHasKey( 'message_id', $result );
		$this->assertArrayNotHasKey( 'in_reply_to', $queued[0] );
		$this->assertArrayNotHasKey( 'references', $queued[0] );
	}

	public function test_retries_are_idempotent_and_conflicting_key_reuse_is_rejected(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$first   = $service->request( $this->input( $booking['id'] ), 12 );
		$retry   = $service->request( $this->input( $booking['id'] ), 12 );

		$this->assertSame( $first, $retry );
		$this->assertCount( 1, $queued );
		$conflict = $service->request( $this->input( $booking['id'], array( 'message' => 'Different message.' ) ), 12 );
		$this->assertSame( 'booking_message_idempotency_conflict', $conflict->get_error_code() );
	}

	public function test_concurrent_retry_observes_dispatch_claim_without_duplicate_queueing(): void {
		$booking = $this->booking();
		$input   = $this->input( $booking['id'] );
		$authorization = new BookingTestAuthorization();
		$queue_calls   = 0;
		$reentrant     = null;
		$service       = null;
		$service       = new BookingCommunicationService(
			null,
			null,
			$authorization,
			static function () use ( &$queue_calls, &$reentrant, &$service, $input ) {
				++$queue_calls;
				$reentrant = $service->request( $input, 12 );
				return array( 'success' => true, 'action_id' => 501 );
			}
		);

		$result = $service->request( $input, 12 );
		$this->assertSame( 'queued', $result['status'] );
		$this->assertSame( 'reconciliation_required', $reentrant['status'] );
		$this->assertSame( 1, $queue_calls );
	}

	public function test_failed_queue_remains_visible_and_retryable(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$failed  = $this->service( $queued, $scheduled, $cancelled, null, false )->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_message_queue_failed', $failed->get_error_code() );

		$retry = $this->service( $queued, $scheduled, $cancelled )->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'queued', $retry['status'] );
		$this->assertCount( 2, $queued );
		$this->assertContains( 'booking_message_failed', array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' ) );
	}

	public function test_immediate_retry_limit_rejects_a_fourth_delivery_attempt(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled, null, false );
		for ( $attempt = 1; $attempt <= BookingCommunicationService::MAX_ATTEMPTS; ++$attempt ) {
			$this->assertSame( 'booking_message_queue_failed', $service->request( $this->input( $booking['id'] ), 12 )->get_error_code() );
		}
		$this->assertSame( 'booking_message_retry_limit_exceeded', $service->request( $this->input( $booking['id'] ), 12 )->get_error_code() );
		$this->assertCount( BookingCommunicationService::MAX_ATTEMPTS, $queued );
		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'], 100 );
		$this->assertCount( BookingCommunicationService::MAX_ATTEMPTS, array_filter( $activity, static function ( array $row ): bool { return 'booking_message_dispatching' === $row['kind']; } ) );
		$this->assertCount( BookingCommunicationService::MAX_ATTEMPTS, array_filter( $activity, static function ( array $row ): bool { return 'booking_message_failed' === $row['kind']; } ) );
	}

	public function test_reminder_retry_limit_suppresses_a_fourth_schedule_attempt(): void {
		$booking = $this->booking();
		$schedule_calls = 0;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			null,
			static function () use ( &$schedule_calls ) {
				++$schedule_calls;
				return 0;
			}
		);
		$input = $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) );
		for ( $attempt = 1; $attempt <= BookingCommunicationService::MAX_ATTEMPTS; ++$attempt ) {
			$this->assertSame( 'booking_reminder_schedule_failed', $service->request( $input, 12 )->get_error_code() );
		}
		$result = $service->request( $input, 12 );
		$this->assertSame( 'suppressed', $result['status'] );
		$this->assertSame( BookingCommunicationService::MAX_ATTEMPTS, $schedule_calls );
	}

	public function test_uncertain_queue_outcome_is_never_replayed_blindly(): void {
		$booking = $this->booking();
		$calls   = 0;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () use ( &$calls ) {
				++$calls;
				throw new RuntimeException( 'crash after an unknowable queue boundary' );
			}
		);

		$first = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_message_delivery_uncertain', $first->get_error_code() );
		$retry = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'reconciliation_required', $retry['status'] );
		$this->assertSame( 1, $calls );
	}

	public function test_crash_persisting_queue_receipt_is_reconciliation_required(): void {
		$booking = $this->booking();
		$calls   = 0;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () use ( &$calls ) {
				++$calls;
				$GLOBALS['wpdb']->fail_activity_kinds[] = 'booking_message_queued';
				return array( 'success' => true, 'action_id' => 501 );
			}
		);

		$first = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_activity_write_failed', $first->get_error_code() );
		$retry = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'reconciliation_required', $retry['status'] );
		$this->assertSame( 1, $calls );
	}

	public function test_success_without_queue_receipt_is_not_replayed(): void {
		$booking = $this->booking();
		$calls   = 0;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () use ( &$calls ) {
				++$calls;
				return array( 'success' => true );
			}
		);

		$first = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_message_delivery_uncertain', $first->get_error_code() );
		$this->assertSame( 'reconciliation_required', $service->request( $this->input( $booking['id'] ), 12 )['status'] );
		$this->assertSame( 1, $calls );
	}

	public function test_operator_reconciles_dispatch_claim_from_exact_scheduler_evidence(): void {
		$booking = $this->booking();
		$query   = null;
		$calls   = 0;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () use ( &$calls ) {
				++$calls;
				throw new RuntimeException( 'uncertain queue boundary' );
			},
			null,
			null,
			static function ( array $evidence_query ) use ( &$query ) {
				$query = $evidence_query;
				return array( array( 'action_id' => 501, 'status' => 'complete' ) );
			}
		);

		$first = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_message_delivery_uncertain', $first->get_error_code() );
		$intent = ( new BookingActivityRepository() )->find_by_idempotency( $booking['id'], 'booking-message-request:message-1' );
		$result = $service->reconcile( $intent['id'], 12 );
		$this->assertSame( 'queued', $result['status'] );
		$this->assertSame( 501, $result['action_id'] );
		$this->assertSame( 'datamachine_send_email_worker', $query['hook'] );
		$this->assertSame( 'data-machine-email', $query['group'] );
		$this->assertSame( 1, $query['args'][0]['_attempt'] );
		$this->assertSame( $intent['id'], $query['args'][0]['context']['booking_communication_intent_id'] );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'booking_message_reconciliation_not_required', $service->reconcile( $intent['id'], 12 )->get_error_code() );
	}

	public function test_operator_recovers_only_live_reminder_schedule_evidence(): void {
		$booking = $this->booking();
		$input   = $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) );
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			null,
			static function () {
				throw new RuntimeException( 'uncertain schedule boundary' );
			},
			null,
			static function ( array $query ) {
				return array( array( 'action_id' => 601, 'status' => 'pending', 'query' => $query ) );
			}
		);
		$this->assertSame( 'booking_reminder_schedule_uncertain', $service->request( $input, 12 )->get_error_code() );
		$intent = ( new BookingActivityRepository() )->find_by_idempotency( $booking['id'], 'booking-message-request:message-1' );
		$result = $service->reconcile( $intent['id'], 12 );
		$this->assertSame( 'scheduled', $result['status'] );
		$this->assertSame( 601, $result['action_id'] );
	}

	public function test_reconciliation_does_not_infer_replay_from_absent_or_terminal_evidence(): void {
		foreach ( array( array(), array( array( 'action_id' => 602, 'status' => 'complete' ) ) ) as $index => $evidence ) {
			$booking = $this->booking( 'artist' . $index . '@example.com' );
			$input   = $this->input( $booking['id'], array( 'idempotency_key' => 'reminder-' . $index, 'recipient' => 'artist' . $index . '@example.com', 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) );
			$service = new BookingCommunicationService(
				null,
				null,
				new BookingTestAuthorization(),
				null,
				static function () {
					throw new RuntimeException( 'uncertain schedule boundary' );
				},
				null,
				static function () use ( $evidence ) {
					return $evidence;
				}
			);
			$service->request( $input, 12 );
			$intent = ( new BookingActivityRepository() )->find_by_idempotency( $booking['id'], 'booking-message-request:reminder-' . $index );
			$result = $service->reconcile( $intent['id'], 12 );
			$this->assertSame( 'reconciliation_required', $result['status'] );
			$this->assertSame( 0 === $index ? 'none' : 'terminal', $result['scheduler_evidence'] );
		}
	}

	public function test_reconciliation_rejects_ambiguous_or_malformed_evidence(): void {
		$booking = $this->booking();
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () {
				throw new RuntimeException( 'uncertain queue boundary' );
			},
			null,
			null,
			static function () {
				return array( array( 'action_id' => 501, 'status' => 'pending' ), array( 'action_id' => 502, 'status' => 'pending' ) );
			}
		);
		$service->request( $this->input( $booking['id'] ), 12 );
		$intent = ( new BookingActivityRepository() )->find_by_idempotency( $booking['id'], 'booking-message-request:message-1' );
		$this->assertSame( 'booking_message_reconciliation_ambiguous', $service->reconcile( $intent['id'], 12 )->get_error_code() );
	}

	public function test_reconciliation_reauthorizes_internal_operator_under_lock(): void {
		$booking       = $this->booking();
		$authorization = new BookingTestAuthorization();
		$service       = new BookingCommunicationService(
			null,
			null,
			$authorization,
			static function () {
				throw new RuntimeException( 'uncertain queue boundary' );
			},
			null,
			null,
			static function () {
				return array( array( 'action_id' => 501, 'status' => 'pending' ) );
			}
		);
		$service->request( $this->input( $booking['id'] ), 12 );
		$intent = ( new BookingActivityRepository() )->find_by_idempotency( $booking['id'], 'booking-message-request:message-1' );
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};

		$this->assertSame( 'venue_action_forbidden', $service->reconcile( $intent['id'], 12 )->get_error_code() );
	}

	public function test_state_read_failure_fails_closed_without_duplicate_delivery(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$service->request( $this->input( $booking['id'] ), 12 );
		$GLOBALS['wpdb']->fail_activity_reads = true;
		$result = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_activity_read_failed', $result->get_error_code() );
		$this->assertCount( 1, $queued );
	}

	public function test_queued_projection_failure_rolls_back_receipt_and_preserves_claim(): void {
		$booking = $this->booking();
		$queue_calls = 0;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () use ( &$queue_calls ) {
				++$queue_calls;
				$GLOBALS['wpdb']->fail_communication_state_updates = true;
				return array( 'success' => true, 'action_id' => 501 );
			}
		);
		$result = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_communication_state_write_failed', $result->get_error_code() );
		$GLOBALS['wpdb']->fail_communication_state_updates = false;
		$this->assertSame( array( 'booking_message_dispatching', 'booking_message_requested' ), array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' ) );
		$this->assertSame( 'reconciliation_required', $service->request( $this->input( $booking['id'] ), 12 )['status'] );
		$this->assertSame( 1, $queue_calls );
	}

	public function test_failed_projection_failure_rolls_back_failure_marker_and_preserves_claim(): void {
		$booking = $this->booking();
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () {
				$GLOBALS['wpdb']->fail_communication_state_updates = true;
				return array( 'success' => false, 'error' => 'rejected' );
			}
		);
		$result = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_communication_state_write_failed', $result->get_error_code() );
		$this->assertSame( array( 'booking_message_dispatching', 'booking_message_requested' ), array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' ) );
	}

	public function test_locked_reminder_projection_failure_rolls_back_receipt_but_commits_claim(): void {
		$booking = $this->booking();
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () {
				$GLOBALS['wpdb']->fail_communication_state_updates = true;
				return array( 'success' => true, 'action_id' => 701 );
			},
			static function () {
				return 601;
			}
		);
		$state  = $service->request( $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) ), 12 );
		$result = $service->dispatch_reminder( $state['intent_id'] );
		$this->assertSame( 'booking_communication_state_write_failed', $result->get_error_code() );
		$GLOBALS['wpdb']->fail_communication_state_updates = false;
		$this->assertSame( array( 'booking_message_dispatching', 'booking_reminder_scheduled', 'booking_reminder_scheduling', 'booking_message_requested' ), array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' ) );
		$this->assertSame( 'reconciliation_required', $service->dispatch_reminder( $state['intent_id'] )['status'] );
	}

	public function test_authorization_is_rechecked_under_membership_lock(): void {
		$booking       = $this->booking();
		$authorization = new BookingTestAuthorization();
		$queued = $scheduled = $cancelled = array();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$result = $this->service( $queued, $scheduled, $cancelled, $authorization )->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertCount( 0, $queued );
		$this->assertEmpty( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] ?? array() );
	}

	public function test_recipient_is_exactly_booking_scoped(): void {
		$first  = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$this->assertSame( 'booking_message_recipient_forbidden', $service->request( $this->input( $first['id'], array( 'recipient' => 'other@example.com' ) ), 12 )->get_error_code() );
	}

	public function test_scheduled_reminder_is_idempotent_and_stale_status_is_suppressed(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$input = $this->input(
			$booking['id'],
			array(
				'template'          => 'follow_up',
				'send_at'           => '2035-01-01 12:00:00',
				'expected_statuses' => array( 'under_review' ),
			)
		);
		$state = $service->request( $input, 12 );
		$this->assertSame( 'scheduled', $state['status'] );
		$this->assertSame( $state, $service->request( $input, 12 ) );
		$this->assertCount( 1, $scheduled );
		$this->assertCount( 0, $queued );

		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'confirmed';
		$suppressed = $service->dispatch_reminder( $state['intent_id'] );
		$this->assertSame( 'suppressed', $suppressed['status'] );
		$this->assertCount( 0, $queued );
	}

	public function test_reminder_rechecks_raced_booking_change_under_lock(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$state = $service->request( $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) ), 12 );
		$GLOBALS['wpdb']->after_booking_lock = static function () use ( $booking ) {
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status']  = 'needs_info';
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['version'] = 2;
		};

		$result = $service->dispatch_reminder( $state['intent_id'] );
		$this->assertSame( 'suppressed', $result['status'] );
		$this->assertGreaterThan( 0, $GLOBALS['wpdb']->booking_lock_queries );
		$this->assertCount( 0, $queued );
	}

	public function test_reminder_history_error_under_lock_fails_closed(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$state = $service->request( $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) ), 12 );
		$GLOBALS['wpdb']->after_booking_lock = static function () {
			$GLOBALS['wpdb']->fail_reads = true;
		};

		$result = $service->dispatch_reminder( $state['intent_id'] );
		$this->assertSame( 'booking_communication_state_read_failed', $result->get_error_code() );
		$this->assertCount( 0, $queued );
		$this->assertGreaterThan( 0, $GLOBALS['wpdb']->rollback_queries );
	}

	public function test_reminder_keeps_booking_lock_through_queue_receipt(): void {
		$booking = $this->booking();
		$scheduled = array();
		$queue_saw_transaction = false;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () use ( &$queue_saw_transaction ) {
				$queue_saw_transaction = $GLOBALS['wpdb']->transaction_active;
				return array( 'success' => true, 'action_id' => 701 );
			},
			static function ( int $timestamp, string $hook, array $args, string $group ) use ( &$scheduled ) {
				$scheduled[] = compact( 'timestamp', 'hook', 'args', 'group' );
				return 601;
			}
		);
		$state = $service->request( $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) ), 12 );

		$result = $service->dispatch_reminder( $state['intent_id'] );
		$this->assertSame( 'queued', $result['status'] );
		$this->assertTrue( $queue_saw_transaction );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
	}

	public function test_schedule_failure_is_durable_and_retryable(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$input = $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) );
		$failed_service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			static function () {
				throw new RuntimeException( 'queue must not run' );
			},
			static function () {
				return 0;
			}
		);
		$result = $failed_service->request( $input, 12 );
		$this->assertSame( 'booking_reminder_schedule_failed', $result->get_error_code() );
		$this->assertContains( 'booking_message_failed', array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' ) );

		$retry = $this->service( $queued, $scheduled, $cancelled )->request( $input, 12 );
		$this->assertSame( 'scheduled', $retry['status'] );
		$this->assertCount( 1, $scheduled );
	}

	public function test_booking_change_cancels_and_suppresses_pending_reminders(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$reminder = $service->request( $this->input( $booking['id'], array( 'idempotency_key' => 'reminder', 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) ), 12 );

		$this->assertTrue( $service->suppress_pending_reminders( $booking['id'], 'booking_status_changed' ) );
		$this->assertSame( array( $reminder['action_id'] ), $cancelled );
		$this->assertSame( 'suppressed', $service->dispatch_reminder( $reminder['intent_id'] )['status'] );
	}

	public function test_suppression_append_failure_prevents_physical_cancellation(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$service->request( $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) ), 12 );
		$GLOBALS['wpdb']->fail_activity_kinds[] = 'booking_reminder_suppressed';

		$result = $service->suppress_pending_reminders( $booking['id'], 'booking_status_changed' );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$this->assertSame( array(), $cancelled );
	}

	public function test_durable_suppression_survives_physical_cancel_failure(): void {
		$booking = $this->booking();
		$cancel_saw_suppression = false;
		$service = new BookingCommunicationService(
			null,
			null,
			new BookingTestAuthorization(),
			null,
			static function () {
				return 601;
			},
			static function () use ( &$cancel_saw_suppression, $booking ) {
				$kinds = array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' );
				$cancel_saw_suppression = in_array( 'booking_reminder_suppressed', $kinds, true );
				throw new RuntimeException( 'cancel failed' );
			}
		);
		$state = $service->request( $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review' ) ) ), 12 );
		$this->assertTrue( $service->suppress_pending_reminders( $booking['id'], 'booking_status_changed' ) );
		$this->assertTrue( $cancel_saw_suppression );
		$this->assertSame( 'suppressed', $service->dispatch_reminder( $state['intent_id'] )['status'] );
	}

	public function test_suppression_uses_finite_keyset_batches_and_schedules_continuation(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$total   = BookingCommunicationService::SUPPRESSION_BATCH_SIZE + 2;
		for ( $index = 0; $index < $total; ++$index ) {
			$service->request( $this->input( $booking['id'], array( 'idempotency_key' => 'batch-' . $index, 'template' => 'follow_up', 'send_at' => sprintf( '2035-02-%02d 12:00:00', $index + 1 ), 'expected_statuses' => array( 'under_review' ) ) ), 12 );
		}

		$this->assertTrue( $service->suppress_pending_reminders( $booking['id'], 'booking_status_changed' ) );
		$this->assertCount( BookingCommunicationService::SUPPRESSION_BATCH_SIZE, $cancelled );
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['scheduled'] );
		$continuation = $GLOBALS['ec_artist_test']['scheduled'][0];
		$this->assertSame( BookingCommunicationService::SUPPRESSION_HOOK, $continuation['hook'] );
		$this->assertSame( $booking['id'], $continuation['args'][0] );
		$this->assertSame( 'booking_status_changed', $continuation['args'][1] );
		$this->assertTrue( $service->suppress_pending_reminders( $booking['id'], $continuation['args'][1], $continuation['args'][2] ) );
		$this->assertCount( $total, $cancelled );
		$this->assertCount( 1, $GLOBALS['ec_artist_test']['scheduled'] );
		$this->assertSame( array( BookingCommunicationService::SUPPRESSION_BATCH_SIZE + 1, BookingCommunicationService::SUPPRESSION_BATCH_SIZE + 1 ), $GLOBALS['wpdb']->pending_reminder_query_limits );
		$this->assertSame( array( 0, $continuation['args'][2] ), $GLOBALS['wpdb']->pending_reminder_query_cursors );
	}

	public function test_contradictory_history_fails_closed_without_requeueing(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$state = $service->request( $this->input( $booking['id'] ), 12 );
		( new BookingActivityRepository() )->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => 'booking_message_failed',
				'payload'    => array( 'intent_id' => $state['intent_id'], 'stage' => 'queue', 'status' => 'failed' ),
			)
		);
		$result = $service->request( $this->input( $booking['id'] ), 12 );
		$this->assertSame( 'booking_communication_state_invalid', $result->get_error_code() );
		$this->assertCount( 1, $queued );
	}

	public function test_receipt_reconstruction_rejects_wrong_stage_and_action_identity(): void {
		$cases = array(
			array( 'stage' => 'scheduled', 'status' => null, 'action_id' => 501, 'external_id' => '501' ),
			array( 'stage' => 'queued', 'status' => null, 'action_id' => 0, 'external_id' => '0' ),
			array( 'stage' => 'queued', 'status' => null, 'action_id' => 501, 'external_id' => '502' ),
			array( 'stage' => 'queued', 'status' => 'sent', 'action_id' => 501, 'external_id' => '501' ),
		);
		foreach ( $cases as $index => $case ) {
			$booking = $this->booking( 'malformed' . $index . '@example.com' );
			$service = new BookingCommunicationService(
				null,
				null,
				new BookingTestAuthorization(),
				static function () {
					throw new RuntimeException( 'uncertain queue boundary' );
				}
			);
			$input = $this->input( $booking['id'], array( 'idempotency_key' => 'malformed-' . $index, 'recipient' => 'malformed' . $index . '@example.com' ) );
			$service->request( $input, 12 );
			$intent  = ( new BookingActivityRepository() )->find_by_idempotency( $booking['id'], 'booking-message-request:malformed-' . $index );
			$payload = array( 'intent_id' => $intent['id'], 'stage' => $case['stage'], 'attempt' => 1, 'action_id' => $case['action_id'] );
			if ( null !== $case['status'] ) {
				$payload['status'] = $case['status'];
			}
			( new BookingActivityRepository() )->append( array( 'booking_id' => $booking['id'], 'kind' => 'booking_message_queued', 'external_id' => $case['external_id'], 'payload' => $payload ) );
			$this->assertSame( 'booking_communication_state_invalid', $service->request( $input, 12 )->get_error_code(), 'Malformed case ' . $index );
		}
	}

	public function test_lifecycle_and_contact_changes_suppress_reminders(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$lifecycle_reminder = $service->request( $this->input( $booking['id'], array( 'idempotency_key' => 'lifecycle', 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review', 'needs_info' ) ) ), 12 );
		$authorization = new BookingTestAuthorization();
		$abilities = new VenueBookingAbilities( new BookingRepository(), new BookingLifecycle( null, null, $authorization ), $authorization );
		$transitioned = $abilities->transition_booking( array( 'booking_id' => $booking['id'], 'to_status' => 'needs_info', 'expected_version' => 1 ) );
		$this->assertSame( 'needs_info', $transitioned['status'] );
		$this->assertSame( 'suppressed', $service->dispatch_reminder( $lifecycle_reminder['intent_id'] )['status'] );

		$contact_reminder = $service->request( $this->input( $booking['id'], array( 'idempotency_key' => 'contact', 'template' => 'follow_up', 'send_at' => '2035-01-02 12:00:00', 'expected_statuses' => array( 'needs_info' ) ) ), 12 );
		$mutation_abilities = new VenueBookingMutationAbilities( new BookingMutationService( null, null, $authorization ), new BookingRepository(), $authorization, $abilities );
		$corrected = $mutation_abilities->correct_intake( array( 'booking_id' => $booking['id'], 'expected_version' => 2, 'contact_email' => 'new@example.com' ) );
		$this->assertSame( 'new@example.com', $corrected['contact_email'] );
		$this->assertSame( 'suppressed', $service->dispatch_reminder( $contact_reminder['intent_id'] )['status'] );
		$this->assertCount( 0, $queued );
	}

	public function test_lifecycle_and_contact_mutations_propagate_suppression_failure(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$service->request( $this->input( $booking['id'], array( 'template' => 'follow_up', 'send_at' => '2035-01-01 12:00:00', 'expected_statuses' => array( 'under_review', 'needs_info' ) ) ), 12 );
		$GLOBALS['wpdb']->fail_activity_kinds[] = 'booking_reminder_suppressed';
		$authorization = new BookingTestAuthorization();
		$abilities = new VenueBookingAbilities( new BookingRepository(), new BookingLifecycle( null, null, $authorization ), $authorization );

		$transition = $abilities->transition_booking( array( 'booking_id' => $booking['id'], 'to_status' => 'needs_info', 'expected_version' => 1 ) );
		$this->assertSame( 'booking_activity_write_failed', $transition->get_error_code() );
		$this->assertTrue( $transition->get_error_data()['booking_committed'] );
		$this->assertSame( 'needs_info', ( new BookingRepository() )->get( $booking['id'] )['status'] );

		$mutations = new VenueBookingMutationAbilities( new BookingMutationService( null, null, $authorization ), new BookingRepository(), $authorization, $abilities );
		$contact = $mutations->correct_intake( array( 'booking_id' => $booking['id'], 'expected_version' => 2, 'contact_email' => 'changed@example.com' ) );
		$this->assertSame( 'booking_activity_write_failed', $contact->get_error_code() );
		$this->assertTrue( $contact->get_error_data()['booking_committed'] );
		$this->assertSame( 'changed@example.com', ( new BookingRepository() )->get( $booking['id'] )['contact_email'] );
	}

	public function test_large_history_uses_bounded_state_reads_and_keeps_public_page_at_200_rows(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		for ( $index = 0; $index < 70; ++$index ) {
			$result = $service->request( $this->input( $booking['id'], array( 'idempotency_key' => 'history-' . $index ) ), 12 );
			$this->assertSame( 'queued', $result['status'] );
		}
		$pending_ids = array();
		for ( $index = 0; $index < 3; ++$index ) {
			$result        = $service->request( $this->input( $booking['id'], array( 'idempotency_key' => 'pending-' . $index, 'template' => 'follow_up', 'send_at' => sprintf( '2035-01-%02d 12:00:00', $index + 1 ), 'expected_statuses' => array( 'under_review' ) ) ), 12 );
			$pending_ids[] = $result['intent_id'];
		}

		$activity_table = BookingSchema::activity_table();
		$next_id        = max( array_keys( $GLOBALS['wpdb']->rows[ $activity_table ] ) ) + 1;
		for ( $index = 0; $index < 1000; ++$index ) {
			$id = $next_id + $index;
			$GLOBALS['wpdb']->rows[ $activity_table ][ $id ] = array(
				'id'                      => $id,
				'booking_id'              => $booking['id'],
				'kind'                    => 'booking_note_added',
				'actor_type'              => 'system',
				'actor_id'                => null,
				'direction'               => null,
				'channel'                 => null,
				'communication_intent_id' => null,
				'is_communication'        => 0,
				'payload'                 => '{"version":1,"data":{}}',
				'external_id'             => null,
				'idempotency_key'         => null,
				'occurred_at'             => '2030-01-01 00:00:00',
				'created_at'              => '2030-01-01 00:00:00',
			);
		}

		$GLOBALS['wpdb']->communication_state_queries        = 0;
		$GLOBALS['wpdb']->communication_attempt_queries      = 0;
		$GLOBALS['wpdb']->pending_reminder_rows_returned     = 0;
		$GLOBALS['wpdb']->communication_public_rows_returned = 0;
		$this->assertTrue( $service->suppress_pending_reminders( $booking['id'], 'booking_status_changed' ) );
		$this->assertSame( 3, $GLOBALS['wpdb']->pending_reminder_rows_returned );
		$this->assertSame( 3, $GLOBALS['wpdb']->communication_attempt_queries );
		$this->assertLessThanOrEqual( 4, $GLOBALS['wpdb']->communication_state_queries );
		$this->assertCount( 3, $cancelled );

		$public = $service->list_for_booking( $booking['id'], 12 );
		$this->assertCount( 200, $public );
		$this->assertSame( 200, $GLOBALS['wpdb']->communication_public_rows_returned );
		$this->assertSame( $pending_ids[2], $public[0]['state']['intent_id'] );
		$this->assertNotContains( 'booking_note_added', array_column( $public, 'kind' ) );
		$this->assertSame( array( 'activity_id', 'booking_id', 'kind', 'direction', 'channel', 'occurred_at', 'message', 'state' ), array_keys( $public[0] ) );
	}

	public function test_public_presenter_has_exact_allowlisted_shape_without_private_metadata(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$service = $this->service( $queued, $scheduled, $cancelled );
		$service->request( $this->input( $booking['id'] ), 12 );
		$public    = $service->list_for_booking( $booking['id'], 12 );
		$request   = $public[2];
		$state     = $public[0];
		$top_level = array( 'activity_id', 'booking_id', 'kind', 'direction', 'channel', 'occurred_at', 'message', 'state' );
		$this->assertSame( $top_level, array_keys( $request ) );
		$this->assertSame( $top_level, array_keys( $state ) );
		$this->assertSame( array( 'template', 'recipient', 'subject', 'message', 'reply_to', 'send_at' ), array_keys( $request['message'] ) );
		$this->assertNull( $request['state'] );
		$this->assertNull( $state['message'] );
		$this->assertSame( array( 'intent_id', 'status', 'reason' ), array_keys( $state['state'] ) );
		$encoded = wp_json_encode( $public );
		foreach ( array( 'request_hash', 'booking_version', 'expected_statuses', 'idempotency_key', 'external_id', 'actor_id', 'actor_type', 'created_at', 'mail_site_id', 'from_name', 'identity', '"cc"', 'action_id', 'attempt', 'retryable', 'scheduler_status' ) as $private_key ) {
			$this->assertStringNotContainsString( $private_key, $encoded );
		}
	}

	public function test_read_model_reauthorizes_under_lock_and_abilities_are_strict(): void {
		$booking = $this->booking();
		$queued = $scheduled = $cancelled = array();
		$authorization = new BookingTestAuthorization();
		$service = $this->service( $queued, $scheduled, $cancelled, $authorization );
		$service->request( $this->input( $booking['id'] ), 12 );
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$this->assertSame( 'venue_action_forbidden', $service->list_for_booking( $booking['id'], 12 )->get_error_code() );

		$abilities = new VenueBookingCommunicationAbilities( $service, new BookingRepository(), $authorization );
		$abilities->register();
		$registered = $GLOBALS['ec_artist_test']['abilities'];
		$this->assertSame( array( 'extrachill/send-booking-message', 'extrachill/list-booking-communications', 'extrachill/reconcile-booking-message' ), array_keys( $registered ) );
		foreach ( $registered as $definition ) {
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			$this->assertTrue( $definition['meta']['show_in_rest'] );
		}
		$list_items = $registered['extrachill/list-booking-communications']['output_schema']['items'];
		$this->assertFalse( $list_items['additionalProperties'] );
		$this->assertFalse( $list_items['properties']['message']['additionalProperties'] );
		$this->assertFalse( $list_items['properties']['state']['additionalProperties'] );
		$this->assertArrayNotHasKey( 'attachments', $registered['extrachill/send-booking-message']['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'from_email', $registered['extrachill/send-booking-message']['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'in_reply_to', $registered['extrachill/send-booking-message']['input_schema']['properties'] );
	}
}
