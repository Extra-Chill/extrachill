<?php
/**
 * Booking-linked communication policy and durable correspondence state.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Records booking correspondence before delegating delivery to Data Machine. */
class BookingCommunicationService {

	public const REMINDER_HOOK          = 'extrachill_events_dispatch_booking_reminder';
	public const SUPPRESSION_HOOK       = 'extrachill_events_continue_booking_reminder_suppression';
	public const SCHEDULER_GROUP        = 'extrachill-events-booking-reminders';
	public const SUPPRESSION_BATCH_SIZE = 25;
	public const MAX_ATTEMPTS           = 3;
	public const TEMPLATES              = array( 'operator_message', 'follow_up', 'hold_expiring' );
	public const TERMINAL_STATUSES      = array( 'declined', 'withdrawn', 'cancelled', 'completed' );

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var callable|null */
	private $queue;
	/** @var callable|null */
	private $schedule;
	/** @var callable|null */
	private $cancel;
	/** @var callable|null */
	private $find_actions;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, $queue = null, $schedule = null, $cancel = null, $find_actions = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->queue         = $queue;
		$this->schedule      = $schedule;
		$this->cancel        = $cancel;
		$this->find_actions  = $find_actions;
	}

	/** Register the policy preflight that runs before a delayed reminder is queued. */
	public static function register(): void {
		add_action( self::REMINDER_HOOK, array( self::class, 'dispatch_scheduled' ), 10, 1 );
		add_action( self::SUPPRESSION_HOOK, array( self::class, 'continue_suppression' ), 10, 3 );
	}

	/** Action Scheduler callback. */
	public static function dispatch_scheduled( int $activity_id ): void {
		$result = ( new self() )->dispatch_reminder( $activity_id );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is consumed by Action Scheduler, not rendered.
		}
	}

	/** Continue a bounded suppression pass outside the lifecycle request. */
	public static function continue_suppression( int $booking_id, string $reason, int $after_intent_id ): void {
		$result = ( new self() )->suppress_pending_reminders( $booking_id, $reason, $after_intent_id );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is consumed by Action Scheduler, not rendered.
		}
	}

	/** Persist an outbound request, then queue now or schedule a policy preflight. */
	public function request( array $input, int $actor_id ) {
		$normalized = $this->normalize_request( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$booking = $this->bookings->get( $normalized['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : $this->forbidden();
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : $this->forbidden();
		}

		$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->bookings->get_for_update( $normalized['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : $this->forbidden() );
		}
		if ( empty( $booking['contact_email'] ) || strtolower( $booking['contact_email'] ) !== strtolower( $normalized['recipient'] ) ) {
			return $this->rollback( new \WP_Error( 'booking_message_recipient_forbidden', __( 'The recipient must be the current contact for this booking.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( in_array( $booking['status'], self::TERMINAL_STATUSES, true ) ) {
			return $this->rollback( new \WP_Error( 'booking_message_status_forbidden', __( 'Messages cannot be sent for a terminal booking.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$key      = 'booking-message-request:' . $normalized['idempotency_key'];
		$existing = $this->activity->find_by_idempotency( $booking['id'], $key );
		if ( is_wp_error( $existing ) ) {
			return $this->rollback( $existing );
		}
		$hash = $this->request_hash( $normalized, $actor_id );
		if ( is_array( $existing ) ) {
			$committed = $this->commit();
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
			if ( ! hash_equals( (string) ( $existing['payload']['data']['request_hash'] ?? '' ), $hash ) ) {
				return new \WP_Error( 'booking_message_idempotency_conflict', __( 'The idempotency key was already used for a different message.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			return $this->resume( $existing );
		}

		$intent = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'booking_message_requested',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'direction'       => 'outbound',
				'channel'         => 'email',
				'idempotency_key' => $key,
				'payload'         => array_merge(
					$normalized,
					array(
						'request_hash'    => $hash,
						'booking_version' => (int) $booking['version'],
						'cc'              => 'chubes@extrachill.com',
						'from_name'       => 'Extra Chill Bot',
						'identity'        => 'Extra Chill Bot sending on Chris\'s behalf.',
						'mail_site_id'    => function_exists( 'extrachill_mail_site_id' ) ? extrachill_mail_site_id() : get_current_blog_id(),
					)
				),
			)
		);
		if ( is_wp_error( $intent ) ) {
			return $this->rollback( $intent );
		}
		$initialized = $this->activity->create_communication_state( $intent );
		if ( is_wp_error( $initialized ) ) {
			return $this->rollback( $initialized );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->resume( $intent );
	}

	/** Retry the incomplete side effect for one durable intent. */
	private function resume( array $intent ) {
		$state = $this->state_for_intent( $intent );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( in_array( $state['status'], array( 'scheduled', 'queued', 'suppressed', 'cancelled', 'reconciliation_required' ), true ) ) {
			return $state;
		}
		$data = $intent['payload']['data'];
		if ( ! empty( $data['send_at'] ) ) {
			return $this->schedule_intent( $intent );
		}
		return $this->queue_intent( $intent );
	}

	/** Schedule only the booking-policy preflight; email payload stays in the ledger. */
	private function schedule_intent( array $intent ) {
		$claimed = $this->claim_side_effect( $intent, 'scheduling', array( 'requested', 'failed' ) );
		if ( true !== $claimed ) {
			return $claimed;
		}
		$timestamp = strtotime( $intent['payload']['data']['send_at'] . ' UTC' );
		try {
			$action_id = $this->schedule
				? call_user_func( $this->schedule, $timestamp, self::REMINDER_HOOK, array( $intent['id'] ), self::SCHEDULER_GROUP )
				: as_schedule_single_action( $timestamp, self::REMINDER_HOOK, array( $intent['id'] ), self::SCHEDULER_GROUP, true );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'booking_reminder_schedule_uncertain', __( 'The booking reminder schedule outcome requires manual reconciliation.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 0 === $action_id ) {
			$event = $this->append_state( $intent, 'booking_message_failed', 'schedule', array( 'retryable' => true ) );
			return is_wp_error( $event ) ? $event : new \WP_Error( 'booking_reminder_schedule_failed', __( 'The booking reminder could not be scheduled.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( ! is_int( $action_id ) || $action_id < 1 ) {
			return new \WP_Error( 'booking_reminder_schedule_uncertain', __( 'The booking reminder schedule outcome requires manual reconciliation.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$event = $this->append_state( $intent, 'booking_reminder_scheduled', 'scheduled', array( 'action_id' => (int) $action_id ), (string) $action_id );
		return is_wp_error( $event ) ? $event : $this->state_for_intent( $intent );
	}

	/** Recheck the complete reminder policy while holding the booking row lock. */
	public function dispatch_reminder( int $activity_id ) {
		$intent = $this->activity->get( $activity_id );
		if ( ! is_array( $intent ) || 'booking_message_requested' !== $intent['kind'] || empty( $intent['payload']['data']['send_at'] ) ) {
			return new \WP_Error( 'booking_reminder_invalid', __( 'The booking reminder intent is invalid.', 'extrachill-events' ) );
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->bookings->get_for_update( $intent['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		$state = $this->state_for_intent( $intent );
		if ( is_wp_error( $state ) ) {
			return $this->rollback( $state );
		}
		if ( in_array( $state['status'], array( 'queued', 'suppressed', 'cancelled', 'reconciliation_required' ), true ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $state;
		}
		$data   = $intent['payload']['data'];
		$reason = null;
		if ( (int) $booking['version'] !== (int) $data['booking_version'] || in_array( $booking['status'], self::TERMINAL_STATUSES, true ) || ! in_array( $booking['status'], $data['expected_statuses'], true ) ) {
			$reason = 'booking_status_changed';
		} elseif ( strtolower( (string) $booking['contact_email'] ) !== strtolower( $data['recipient'] ) ) {
			$reason = 'booking_recipient_changed';
		}
		if ( $reason ) {
			$event = $this->append_state( $intent, 'booking_reminder_suppressed', 'suppressed', array( 'reason' => $reason ) );
			if ( is_wp_error( $event ) ) {
				return $this->rollback( $event );
			}
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $this->state_for_intent( $intent );
		}
		$claim = $this->append_state( $intent, 'booking_message_dispatching', 'dispatching', array() );
		if ( is_wp_error( $claim ) ) {
			return $this->rollback( $claim );
		}
		return $this->deliver_claimed( $intent, true );
	}

	/** Delegate one prepared request to Data Machine's queued email ability. */
	private function queue_intent( array $intent ) {
		$claimed = $this->claim_side_effect( $intent, 'dispatching', array( 'requested', 'failed', 'scheduled' ) );
		if ( true !== $claimed ) {
			return $claimed;
		}
		return $this->deliver_claimed( $intent );
	}

	/** Call the non-idempotent queue only after a durable claim. */
	private function deliver_claimed( array $intent, bool $commit_transaction = false ) {
		$input = $this->queue_input( $intent );
		try {
			if ( $this->queue ) {
				$result = call_user_func( $this->queue, $input );
			} else {
				$ability = wp_get_ability( 'datamachine/send-email-queued' );
				$result  = $ability ? $ability->execute( $input ) : new \WP_Error( 'booking_email_ability_unavailable' );
			}
		} catch ( \Throwable $throwable ) {
			if ( $commit_transaction ) {
				$committed = $this->commit();
				if ( is_wp_error( $committed ) ) {
					return $committed;
				}
			}
			return new \WP_Error( 'booking_message_delivery_uncertain', __( 'The booking message queue outcome requires manual reconciliation.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			if ( $commit_transaction ) {
				$committed = $this->commit();
				if ( is_wp_error( $committed ) ) {
					return $committed;
				}
			}
			return new \WP_Error( 'booking_message_delivery_uncertain', __( 'The booking message queue outcome requires manual reconciliation.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( ! empty( $result['success'] ) && ( ! is_int( $result['action_id'] ?? null ) || $result['action_id'] < 1 ) ) {
			if ( $commit_transaction ) {
				$committed = $this->commit();
				if ( is_wp_error( $committed ) ) {
					return $committed;
				}
			}
			return new \WP_Error( 'booking_message_delivery_uncertain', __( 'The booking message queue outcome requires manual reconciliation.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( empty( $result['success'] ) ) {
			$event = $this->append_state(
				$intent,
				'booking_message_failed',
				'queue',
				array(
					'retryable' => true,
					'error'     => is_wp_error( $result ) ? $result->get_error_code() : ( $result['error'] ?? 'invalid_result' ),
				),
				null,
				null,
				$commit_transaction
			);
			if ( is_wp_error( $event ) ) {
				if ( $commit_transaction && $this->transaction_active ) {
					$committed = $this->commit();
					return is_wp_error( $committed ) ? $committed : $event;
				}
				return $event;
			}
			if ( $commit_transaction ) {
				$committed = $this->commit();
				if ( is_wp_error( $committed ) ) {
					return $committed;
				}
			}
			return new \WP_Error( 'booking_message_queue_failed', __( 'Data Machine did not accept the booking message.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$event = $this->append_state( $intent, 'booking_message_queued', 'queued', array( 'action_id' => (int) $result['action_id'] ), (string) $result['action_id'], null, $commit_transaction );
		if ( is_wp_error( $event ) ) {
			if ( $commit_transaction && $this->transaction_active ) {
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $event;
			}
			return $event;
		}
		if ( $commit_transaction ) {
			$committed = $this->commit();
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
		}
		return $this->state_for_intent( $intent );
	}

	/** Recover a missing scheduler receipt from exact Action Scheduler evidence. */
	public function reconcile( int $intent_id, int $actor_id ) {
		$intent = $this->activity->get( $intent_id );
		if ( ! is_array( $intent ) || 'booking_message_requested' !== $intent['kind'] ) {
			return $this->forbidden();
		}
		$booking = $this->bookings->get( $intent['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return $this->forbidden();
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : $this->forbidden();
		}
		$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( $intent['booking_id'] );
		if ( ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return $this->rollback( $this->forbidden() );
		}
		$state = $this->state_for_intent( $intent );
		if ( is_wp_error( $state ) ) {
			return $this->rollback( $state );
		}
		if ( 'reconciliation_required' !== $state['status'] || ! in_array( $state['claim_stage'] ?? '', array( 'scheduling', 'dispatching' ), true ) ) {
			return $this->rollback( new \WP_Error( 'booking_message_reconciliation_not_required', __( 'This booking message does not require reconciliation.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$evidence = $this->scheduler_evidence( $intent, $state['claim_stage'] );
		if ( is_wp_error( $evidence ) ) {
			return $this->rollback( $evidence );
		}
		if ( count( $evidence ) > 1 ) {
			return $this->rollback( new \WP_Error( 'booking_message_reconciliation_ambiguous', __( 'Multiple scheduler actions match this booking message claim.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( empty( $evidence ) ) {
			$committed                   = $this->commit();
			$state['scheduler_evidence'] = 'none';
			return is_wp_error( $committed ) ? $committed : $state;
		}
		$action_id = $evidence[0]['action_id'] ?? null;
		$status    = $evidence[0]['status'] ?? null;
		if ( ! is_int( $action_id ) || $action_id < 1 || ! in_array( $status, array( 'pending', 'in-progress', 'complete', 'failed', 'canceled' ), true ) ) {
			return $this->rollback( new \WP_Error( 'booking_message_reconciliation_evidence_invalid', __( 'Scheduler evidence for this booking message is invalid.', 'extrachill-events' ) ) );
		}
		$is_schedule = 'scheduling' === $state['claim_stage'];
		if ( $is_schedule && ! in_array( $status, array( 'pending', 'in-progress' ), true ) ) {
			$committed                   = $this->commit();
			$state['scheduler_evidence'] = 'terminal';
			$state['evidence_action_id'] = $action_id;
			$state['evidence_status']    = $status;
			return is_wp_error( $committed ) ? $committed : $state;
		}
		$event = $this->append_state(
			$intent,
			$is_schedule ? 'booking_reminder_scheduled' : 'booking_message_queued',
			$is_schedule ? 'scheduled' : 'queued',
			array(
				'action_id'        => $action_id,
				'reconciled'       => true,
				'scheduler_status' => $status,
			),
			(string) $action_id
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->state_for_intent( $intent );
	}

	/** Authorized correspondence read model for later Roadie and UI consumers. */
	public function list_for_booking( int $booking_id, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return $this->forbidden();
		}
		$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return $this->rollback( $this->forbidden() );
		}
		$rows = $this->activity->list_communications( $booking_id, 200 );
		if ( is_wp_error( $rows ) ) {
			return $this->rollback( $rows );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		return array_map( array( $this, 'present_communication' ), $rows );
	}

	/** Durably suppress reminders, then best-effort cancel their physical actions. */
	public function suppress_pending_reminders( int $booking_id, string $reason, int $after_intent_id = 0 ) {
		$reason          = mb_substr( sanitize_key( $reason ), 0, 64 );
		$after_intent_id = max( 0, $after_intent_id );
		if ( '' === $reason ) {
			return new \WP_Error( 'booking_reminder_suppression_reason_invalid', __( 'A reminder suppression reason is required.', 'extrachill-events' ) );
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		$pending = $this->activity->pending_reminders( $booking_id, $after_intent_id, self::SUPPRESSION_BATCH_SIZE + 1 );
		if ( is_wp_error( $pending ) ) {
			return $this->rollback( $pending );
		}
		$has_more = count( $pending ) > self::SUPPRESSION_BATCH_SIZE;
		$pending  = array_slice( $pending, 0, self::SUPPRESSION_BATCH_SIZE );
		$actions  = array();
		foreach ( $pending as $item ) {
			$event = $this->append_state( $item['intent'], 'booking_reminder_suppressed', 'suppressed', array( 'reason' => $reason ), null, $item['state'] );
			if ( is_wp_error( $event ) ) {
				return $this->rollback( $event );
			}
			if ( ! empty( $item['state']['action_id'] ) ) {
				$actions[] = $item['state']['action_id'];
			}
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		foreach ( $actions as $action_id ) {
			try {
				if ( $this->cancel ) {
					call_user_func( $this->cancel, $action_id );
				} elseif ( class_exists( '\ActionScheduler' ) ) {
					\ActionScheduler::store()->cancel_action( $action_id );
				}
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				// The durable suppression remains authoritative if physical cancellation fails.
			}
		}
		if ( $has_more ) {
			$cursor    = (int) $pending[ count( $pending ) - 1 ]['intent']['id'];
			$continued = $this->schedule_suppression_continuation( $booking_id, $reason, $cursor );
			if ( is_wp_error( $continued ) ) {
				return $continued;
			}
		}
		return true;
	}

	/** Present only correspondence fields intentionally exposed to authorized consumers. */
	public function present_communication( array $activity ): array {
		$data       = is_array( $activity['payload']['data'] ?? null ) ? $activity['payload']['data'] : array();
		$is_request = 'booking_message_requested' === $activity['kind'];
		return array(
			'activity_id' => (int) $activity['id'],
			'booking_id'  => (int) $activity['booking_id'],
			'kind'        => (string) $activity['kind'],
			'direction'   => (string) $activity['direction'],
			'channel'     => (string) $activity['channel'],
			'occurred_at' => (string) $activity['occurred_at'],
			'message'     => $is_request ? array(
				'template'  => (string) ( $data['template'] ?? '' ),
				'recipient' => (string) ( $data['recipient'] ?? '' ),
				'subject'   => (string) ( $data['subject'] ?? '' ),
				'message'   => (string) ( $data['message'] ?? '' ),
				'reply_to'  => (string) ( $data['reply_to'] ?? '' ),
				'send_at'   => is_string( $data['send_at'] ?? null ) ? $data['send_at'] : null,
			) : null,
			'state'       => $is_request ? null : array(
				'intent_id' => (int) ( $data['intent_id'] ?? 0 ),
				'status'    => (string) ( $data['status'] ?? $data['stage'] ?? '' ),
				'reason'    => is_string( $data['reason'] ?? null ) ? $data['reason'] : null,
			),
		);
	}

	private function append_state( array $intent, string $kind, string $stage, array $payload, ?string $external_id = null, ?array $known_state = null, bool $commit_inherited_claim_on_projection_failure = false ) {
		$owns_transaction = false;
		if ( ! $this->transaction_active ) {
			$started = $this->begin();
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			$owns_transaction = true;
			$booking          = $this->bookings->get_for_update( $intent['booking_id'] );
			if ( ! is_array( $booking ) ) {
				return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
			}
		}
		$state = null === $known_state ? $this->state_for_intent( $intent ) : $this->validate_state( $intent, $known_state );
		if ( is_wp_error( $state ) ) {
			return $owns_transaction ? $this->rollback( $state ) : $state;
		}
		if ( 'booking_message_failed' === $kind ) {
			$payload['status'] = 'failed';
		}
		$count = $this->activity->communication_attempt_count( $intent['id'], $kind );
		if ( is_wp_error( $count ) ) {
			return $owns_transaction ? $this->rollback( $count ) : $count;
		}
		if ( $count >= self::MAX_ATTEMPTS ) {
			$error = $this->retry_limit_error();
			return $owns_transaction ? $this->rollback( $error ) : $error;
		}
		$attempt = $count + 1;
		$marker  = $this->state_marker(
			array(
				'id'          => 0,
				'kind'        => $kind,
				'external_id' => $external_id,
				'payload'     => array(
					'data' => array_merge(
						array(
							'stage'   => $stage,
							'attempt' => $attempt,
						),
						$payload
					),
				),
			),
			$state['raw_status']
		);
		if ( is_wp_error( $marker ) || ! $this->transition_allowed( $state['raw_status'], is_wp_error( $marker ) ? '' : $marker['status'] ) ) {
			$error = is_wp_error( $marker ) ? $marker : $this->invalid_state( array( 'id' => $state['updated_activity_id'] ) );
			return $owns_transaction ? $this->rollback( $error ) : $error;
		}
		$savepoint = false;
		if ( ! $owns_transaction ) {
			$savepoint = $this->begin_projection_savepoint();
			if ( is_wp_error( $savepoint ) ) {
				return $savepoint;
			}
		}
		$event = $this->activity->append(
			array(
				'booking_id'      => $intent['booking_id'],
				'kind'            => $kind,
				'actor_type'      => 'system',
				'direction'       => 'outbound',
				'channel'         => 'email',
				'external_id'     => $external_id,
				'idempotency_key' => sprintf( 'booking-message:%d:%s:%d', $intent['id'], $stage, $attempt ),
				'payload'         => array_merge(
					array(
						'intent_id' => $intent['id'],
						'stage'     => $stage,
						'attempt'   => $attempt,
					),
					$payload
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			if ( true === $savepoint ) {
				$this->release_projection_savepoint();
			}
			return $owns_transaction ? $this->rollback( $event ) : $event;
		}
		$claim_stage = in_array( $marker['status'], array( 'dispatching', 'scheduling' ), true ) ? $marker['status'] : null;
		$updated     = $this->activity->update_communication_state( $state, $marker['status'], $claim_stage, $marker['action_id'], $event['id'] );
		if ( is_wp_error( $updated ) ) {
			if ( true === $savepoint ) {
				$rolled_back = $this->rollback_projection_savepoint();
				if ( is_wp_error( $rolled_back ) ) {
					return $this->rollback( $rolled_back );
				}
				if ( $commit_inherited_claim_on_projection_failure ) {
					$committed = $this->commit();
					return is_wp_error( $committed ) ? $committed : $updated;
				}
				return $updated;
			}
			return $this->rollback( $updated );
		}
		if ( true === $savepoint ) {
			$released = $this->release_projection_savepoint();
			if ( is_wp_error( $released ) ) {
				return $this->rollback( $released );
			}
		}
		if ( $owns_transaction ) {
			$committed = $this->commit();
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
		}
		return $event;
	}

	private function state_for_intent( array $intent ) {
		$state = $this->activity->communication_state( $intent['id'] );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		return is_array( $state ) ? $this->validate_state( $intent, $state ) : $this->invalid_state( $intent );
	}

	/** Validate the durable projection against its latest indexed audit marker. */
	private function validate_state( array $intent, array $state ) {
		$raw_status = $state['raw_status'] ?? $state['status'] ?? null;
		$claim      = $state['claim_stage'] ?? null;
		$action_id  = $state['action_id'] ?? null;
		$updated_id = (int) ( $state['updated_activity_id'] ?? 0 );
		if ( (int) ( $state['intent_id'] ?? 0 ) !== (int) $intent['id'] || (int) ( $state['booking_id'] ?? 0 ) !== (int) $intent['booking_id'] ) {
			return $this->invalid_state( $intent );
		}
		$allowed = array(
			'requested'   => array( 'scheduling', 'dispatching', 'suppressed', 'cancelled' ),
			'scheduling'  => array( 'scheduled', 'failed' ),
			'scheduled'   => array( 'dispatching', 'suppressed', 'cancelled' ),
			'dispatching' => array( 'queued', 'failed' ),
			'failed'      => array( 'scheduling', 'dispatching', 'suppressed' ),
			'queued'      => array(),
			'suppressed'  => array(),
			'cancelled'   => array(),
		);
		if ( ! is_string( $raw_status ) || ! isset( $allowed[ $raw_status ] ) || ( in_array( $raw_status, array( 'scheduling', 'dispatching' ), true ) ? $claim !== $raw_status : null !== $claim ) || ( in_array( $raw_status, array( 'scheduled', 'queued' ), true ) ? ! is_int( $action_id ) || $action_id < 1 : null !== $action_id ) ) {
			return $this->invalid_state( array( 'id' => $updated_id ) );
		}
		$latest = $this->activity->latest_communication_marker( $intent['id'] );
		if ( is_wp_error( $latest ) ) {
			return $latest;
		}
		if ( 'requested' === $raw_status ) {
			if ( null !== $latest || $updated_id !== (int) $intent['id'] ) {
				return $this->invalid_state( null === $latest ? $intent : $latest );
			}
		} elseif ( ! is_array( $latest ) || (int) $latest['id'] !== $updated_id ) {
			return $this->invalid_state( is_array( $latest ) ? $latest : $intent );
		} else {
			$marker = $this->state_marker( $latest, $raw_status );
			if ( is_wp_error( $marker ) || $marker['status'] !== $raw_status || $marker['action_id'] !== $action_id ) {
				return is_wp_error( $marker ) ? $marker : $this->invalid_state( $latest );
			}
		}
		$state['raw_status'] = $raw_status;
		$state['status']     = in_array( $raw_status, array( 'dispatching', 'scheduling' ), true ) ? 'reconciliation_required' : $raw_status;
		return $state;
	}

	private function transition_allowed( string $current, string $next ): bool {
		$allowed = array(
			'requested'   => array( 'scheduling', 'dispatching', 'suppressed', 'cancelled' ),
			'scheduling'  => array( 'scheduled', 'failed' ),
			'scheduled'   => array( 'dispatching', 'suppressed', 'cancelled' ),
			'dispatching' => array( 'queued', 'failed' ),
			'failed'      => array( 'scheduling', 'dispatching', 'suppressed' ),
			'queued'      => array(),
			'suppressed'  => array(),
			'cancelled'   => array(),
		);
		return isset( $allowed[ $current ] ) && in_array( $next, $allowed[ $current ], true );
	}

	/** Validate one state marker before it can affect reconstructed status. */
	private function state_marker( array $row, string $current ) {
		$data = $row['payload']['data'];
		if ( ! is_array( $data ) ) {
			return $this->invalid_state( $row );
		}
		$kind     = $row['kind'];
		$external = $row['external_id'];
		$stage    = $data['stage'] ?? null;
		$status   = $data['status'] ?? $stage;
		$expected = array(
			'booking_reminder_scheduling' => array( 'scheduling', 'scheduling', false ),
			'booking_reminder_scheduled'  => array( 'scheduled', 'scheduled', true ),
			'booking_message_dispatching' => array( 'dispatching', 'dispatching', false ),
			'booking_message_queued'      => array( 'queued', 'queued', true ),
			'booking_reminder_suppressed' => array( 'suppressed', 'suppressed', false ),
		);
		if ( 'booking_message_failed' === $kind ) {
			$expected_stage = 'scheduling' === $current ? 'schedule' : ( 'dispatching' === $current ? 'queue' : $stage );
			if ( ! in_array( $expected_stage, array( 'schedule', 'queue' ), true ) || $stage !== $expected_stage || 'failed' !== $status || null !== $external || isset( $data['action_id'] ) || ! is_int( $data['attempt'] ?? null ) || $data['attempt'] < 1 || $data['attempt'] > self::MAX_ATTEMPTS ) {
				return $this->invalid_state( $row );
			}
			return array(
				'status'    => 'failed',
				'action_id' => null,
			);
		}
		if ( ! isset( $expected[ $kind ] ) || $stage !== $expected[ $kind ][0] || $status !== $expected[ $kind ][1] || ! is_int( $data['attempt'] ?? null ) || $data['attempt'] < 1 || $data['attempt'] > self::MAX_ATTEMPTS ) {
			return $this->invalid_state( $row );
		}
		$action_id = $data['action_id'] ?? null;
		if ( $expected[ $kind ][2] ) {
			if ( ! is_int( $action_id ) || $action_id < 1 || (string) $action_id !== (string) $external ) {
				return $this->invalid_state( $row );
			}
		} elseif ( null !== $external || null !== $action_id ) {
			return $this->invalid_state( $row );
		}
		return array(
			'status'    => $status,
			'action_id' => $action_id,
		);
	}

	private function invalid_state( array $row ): \WP_Error {
		return new \WP_Error( 'booking_communication_state_invalid', __( 'Booking communication history is contradictory.', 'extrachill-events' ), array( 'activity_id' => $row['id'] ) );
	}

	/** Find exact scheduler actions for the unresolved claim stage. */
	private function scheduler_evidence( array $intent, string $stage ) {
		if ( 'scheduling' === $stage ) {
			$query = array(
				'hook'  => self::REMINDER_HOOK,
				'group' => self::SCHEDULER_GROUP,
				'args'  => array( $intent['id'] ),
			);
		} elseif ( 'dispatching' === $stage ) {
			$payload             = $this->queue_input( $intent );
			$payload['_attempt'] = 1;
			$query               = array(
				'hook'  => class_exists( '\DataMachine\Abilities\Publish\SendEmailQueuedAbility' ) ? \DataMachine\Abilities\Publish\SendEmailQueuedAbility::WORKER_HOOK : 'datamachine_send_email_worker',
				'group' => class_exists( '\DataMachine\Abilities\Publish\SendEmailQueuedAbility' ) ? \DataMachine\Abilities\Publish\SendEmailQueuedAbility::GROUP : 'data-machine-email',
				'args'  => array( $payload ),
			);
		} else {
			return new \WP_Error( 'booking_message_reconciliation_unavailable', __( 'The scheduler evidence contract is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( $this->find_actions ) {
			try {
				$result = call_user_func( $this->find_actions, $query, $intent, $stage );
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				return new \WP_Error( 'booking_message_reconciliation_query_failed', __( 'Action Scheduler evidence could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
			return is_array( $result ) || is_wp_error( $result ) ? $result : new \WP_Error( 'booking_message_reconciliation_evidence_invalid', __( 'Scheduler evidence for this booking message is invalid.', 'extrachill-events' ) );
		}
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return new \WP_Error( 'booking_message_reconciliation_unavailable', __( 'Action Scheduler evidence is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$evidence = array();
		foreach ( array( 'pending', 'in-progress', 'complete', 'failed', 'canceled' ) as $status ) {
			try {
				$ids = as_get_scheduled_actions(
					array_merge(
						$query,
						array(
							'status'   => $status,
							'per_page' => 2,
						)
					),
					'ids'
				);
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				return new \WP_Error( 'booking_message_reconciliation_query_failed', __( 'Action Scheduler evidence could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
			if ( ! is_array( $ids ) ) {
				return new \WP_Error( 'booking_message_reconciliation_query_failed', __( 'Action Scheduler evidence could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
			foreach ( $ids as $action_id ) {
				if ( ! is_numeric( $action_id ) || (int) $action_id < 1 ) {
					return new \WP_Error( 'booking_message_reconciliation_evidence_invalid', __( 'Scheduler evidence for this booking message is invalid.', 'extrachill-events' ) );
				}
				$evidence[ (int) $action_id ] = array(
					'action_id' => (int) $action_id,
					'status'    => $status,
				);
			}
		}
		return array_values( $evidence );
	}

	private function queue_input( array $intent ): array {
		$data = $intent['payload']['data'];
		return array(
			'to'           => $data['recipient'],
			'cc'           => $data['cc'],
			'subject'      => $data['subject'],
			'body'         => $this->render_body( $data['template'], $data['message'] ),
			'context'      => array( 'booking_communication_intent_id' => (int) $intent['id'] ),
			'content_type' => 'text/plain',
			'from_name'    => $data['from_name'],
			'reply_to'     => $data['reply_to'],
			'mail_site_id' => (int) $data['mail_site_id'],
		);
	}

	private function schedule_suppression_continuation( int $booking_id, string $reason, int $after_intent_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new \WP_Error( 'booking_reminder_suppression_continuation_unavailable', __( 'Pending reminder suppression could not be continued.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		try {
			$action_id = as_schedule_single_action( time() + 1, self::SUPPRESSION_HOOK, array( $booking_id, $reason, $after_intent_id ), self::SCHEDULER_GROUP, true );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return new \WP_Error( 'booking_reminder_suppression_continuation_failed', __( 'Pending reminder suppression could not be continued.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return is_int( $action_id ) && $action_id > 0 ? true : new \WP_Error( 'booking_reminder_suppression_continuation_failed', __( 'Pending reminder suppression could not be continued.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	private function retry_limit_error(): \WP_Error {
		return new \WP_Error( 'booking_message_retry_limit_exceeded', __( 'This booking message reached its retry limit.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Serialize the external side effect and leave a durable retry boundary. */
	private function claim_side_effect( array $intent, string $stage, array $allowed_statuses ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Side-effect claim transaction boundary.
			return new \WP_Error( 'booking_communication_transaction_start_failed', __( 'The booking communication transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		$booking                  = $this->bookings->get_for_update( $intent['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		$state = $this->state_for_intent( $intent );
		if ( is_wp_error( $state ) ) {
			return $this->rollback( $state );
		}
		if ( ! in_array( $state['status'], $allowed_statuses, true ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $state;
		}
		$claim_kind = 'scheduling' === $stage ? 'booking_reminder_scheduling' : 'booking_message_dispatching';
		$attempts   = $this->activity->communication_attempt_count( $intent['id'], $claim_kind );
		if ( is_wp_error( $attempts ) ) {
			return $this->rollback( $attempts );
		}
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			if ( ! empty( $intent['payload']['data']['send_at'] ) ) {
				$event = $this->append_state( $intent, 'booking_reminder_suppressed', 'suppressed', array( 'reason' => 'retry_limit_exceeded' ) );
				if ( is_wp_error( $event ) ) {
					return $this->rollback( $event );
				}
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $this->state_for_intent( $intent );
			}
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $this->retry_limit_error();
		}
		$data   = $intent['payload']['data'];
		$reason = null;
		if ( (int) $booking['version'] !== (int) $data['booking_version'] || in_array( $booking['status'], self::TERMINAL_STATUSES, true ) ) {
			$reason = 'booking_status_changed';
		} elseif ( strtolower( (string) $booking['contact_email'] ) !== strtolower( $data['recipient'] ) ) {
			$reason = 'booking_recipient_changed';
		}
		if ( $reason ) {
			$event = $this->append_state( $intent, 'booking_reminder_suppressed', 'suppressed', array( 'reason' => $reason ) );
			if ( is_wp_error( $event ) ) {
				return $this->rollback( $event );
			}
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $this->state_for_intent( $intent );
		}
		$event = $this->append_state( $intent, $claim_kind, $stage, array() );
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : true;
	}

	private function normalize_request( array $input ) {
		$booking_id = absint( $input['booking_id'] ?? 0 );
		$key        = mb_substr( sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) ), 0, 120 );
		$template   = sanitize_key( (string) ( $input['template'] ?? '' ) );
		$recipient  = sanitize_email( (string) ( $input['recipient'] ?? '' ) );
		$subject    = mb_substr( sanitize_text_field( (string) ( $input['subject'] ?? '' ) ), 0, 200 );
		$message    = mb_substr( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), 0, 10000 );
		$reply_to   = sanitize_email( (string) ( $input['reply_to'] ?? '' ) );
		if ( $booking_id < 1 || '' === $key || ! in_array( $template, self::TEMPLATES, true ) || '' === $recipient || '' === $subject || '' === $message || '' === $reply_to ) {
			return new \WP_Error( 'booking_message_invalid', __( 'The booking message request is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$send_at = $input['send_at'] ?? null;
		if ( null !== $send_at && ( ! is_string( $send_at ) || ! $this->valid_datetime( $send_at ) || strtotime( $send_at . ' UTC' ) <= time() ) ) {
			return new \WP_Error( 'booking_reminder_datetime_invalid', __( 'Reminder time must be a future UTC datetime.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$statuses = array_values( array_unique( array_map( 'sanitize_key', (array) ( $input['expected_statuses'] ?? array() ) ) ) );
		if ( null !== $send_at && ( empty( $statuses ) || array_diff( $statuses, BookingRepository::STATUSES ) ) ) {
			return new \WP_Error( 'booking_reminder_statuses_invalid', __( 'Scheduled reminders require valid expected booking statuses.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'booking_id'        => $booking_id,
			'idempotency_key'   => $key,
			'template'          => $template,
			'recipient'         => $recipient,
			'subject'           => $subject,
			'message'           => $message,
			'reply_to'          => $reply_to,
			'send_at'           => $send_at,
			'expected_statuses' => $statuses,
		);
	}

	private function render_body( string $template, string $message ): string {
		$lead = array(
			'operator_message' => 'A message from the Extra Chill booking team:',
			'follow_up'        => 'Following up on your booking inquiry:',
			'hold_expiring'    => 'A reminder about your booking hold:',
		)[ $template ];
		return $lead . "\n\n" . $message . "\n\nExtra Chill Bot sending on Chris's behalf.";
	}

	private function request_hash( array $request, int $actor_id ): string {
		ksort( $request );
		return hash_hmac(
			'sha256',
			wp_json_encode(
				array(
					'actor_id' => $actor_id,
					'request'  => $request,
				)
			),
			wp_salt( 'auth' )
		);
	}

	private function valid_datetime( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function begin_authorized( int $venue_id, int $actor_id ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorization transaction boundary.
			return new \WP_Error( 'booking_communication_transaction_start_failed', __( 'The booking communication transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		$table                    = BookingSchema::memberships_table();
		$locked                   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact venue authority lock.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_communication_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ) ) );
		}
		$allowed = $this->authorization->authorize_locked( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE, (array) $locked );
		return true === $allowed ? true : $this->rollback( is_wp_error( $allowed ) ? $allowed : $this->forbidden() );
	}

	private function begin() {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reminder policy transaction boundary.
			return new \WP_Error( 'booking_communication_transaction_start_failed', __( 'The booking communication transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		return true;
	}

	private function begin_projection_savepoint() {
		global $wpdb;
		$result = $wpdb->query( 'SAVEPOINT booking_communication_projection' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keeps an inherited delivery claim while pairing its marker and projection.
		return false === $result ? new \WP_Error( 'booking_communication_savepoint_failed', __( 'Booking communication state could not be protected.', 'extrachill-events' ) ) : true;
	}

	private function rollback_projection_savepoint() {
		global $wpdb;
		$rolled_back = $wpdb->query( 'ROLLBACK TO SAVEPOINT booking_communication_projection' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes an unprojected marker while retaining the prior durable claim.
		$released    = false === $rolled_back ? false : $wpdb->query( 'RELEASE SAVEPOINT booking_communication_projection' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ends the marker/projection savepoint.
		return false === $rolled_back || false === $released ? new \WP_Error( 'booking_communication_savepoint_rollback_failed', __( 'Booking communication state rollback is uncertain.', 'extrachill-events' ) ) : true;
	}

	private function release_projection_savepoint() {
		global $wpdb;
		$result = $wpdb->query( 'RELEASE SAVEPOINT booking_communication_projection' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ends the successful marker/projection savepoint.
		return false === $result ? new \WP_Error( 'booking_communication_savepoint_release_failed', __( 'Booking communication state commit is uncertain.', 'extrachill-events' ) ) : true;
	}

	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'booking_communication_commit_uncertain', __( 'The booking communication transaction outcome is uncertain.', 'extrachill-events' ) ) : true;
	}

	private function rollback( \WP_Error $error ) {
		global $wpdb;
		if ( $this->transaction_active ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
			$this->transaction_active = false;
		}
		return $error;
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
