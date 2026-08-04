<?php
/**
 * Durable venue operator notification outbox.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Selects source events and reconciles idempotent Users delivery receipts. */
class BookingNotificationService {

	public const EMIT_HOOK       = 'extrachill_events_emit_booking_notification';
	public const RECONCILE_HOOK  = 'extrachill_events_reconcile_booking_notifications';
	public const SCHEDULER_GROUP = 'extrachill-events-booking-notifications';
	public const MAX_ATTEMPTS    = 5;

	public const TYPE_INQUIRY_SUBMITTED    = 'booking_inquiry_submitted';
	public const TYPE_INFORMATION_RECEIVED = 'booking_information_received';
	public const TYPE_HOLD_EXPIRED         = 'booking_hold_expired';
	public const TYPE_EVENT_HANDOFF_FAILED = 'booking_event_handoff_failed';

	// Future producer seams. No source activity is synthesized here.
	public const TYPE_ARTIST_REPLIED          = 'booking_artist_replied';
	public const TYPE_HOLD_EXPIRING           = 'booking_hold_expiring';
	public const TYPE_MARKETING_FAILED        = 'booking_marketing_failed';
	public const TYPE_SETTLEMENT_READY        = 'booking_settlement_ready';
	public const TYPE_OPERATOR_ACTION_OVERDUE = 'booking_operator_action_overdue';

	/** @var bool Whether plugin runtime registration completed. */
	private static $registered = false;
	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var callable|null Structured Users receipt adapter. */
	private $delivery;
	/** @var callable|null Authorized destination resolver. */
	private $destination;
	/** @var callable|null Actor resolver test seam. */
	private $actor_resolver;
	/** @var bool Whether this instance owns a transaction. */
	private $transaction_active = false;

	/**
	 * Build the outbox coordinator.
	 *
	 * @param BookingRepository|null         $bookings      Booking persistence.
	 * @param BookingActivityRepository|null $activity      Activity persistence.
	 * @param callable|null                  $delivery      Structured receipt adapter.
	 * @param callable|null                  $destination   Authorized route resolver.
	 * @param callable|null                  $actor_resolver Actor resolver.
	 */
	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, $delivery = null, $destination = null, $actor_resolver = null ) {
		$this->bookings       = $bookings ? $bookings : new BookingRepository();
		$this->activity       = $activity ? $activity : new BookingActivityRepository();
		$this->delivery       = $delivery;
		$this->destination    = $destination;
		$this->actor_resolver = $actor_resolver;
	}

	/** Register producer and recovery hooks. */
	public static function register(): void {
		self::$registered = true;
		add_action( self::EMIT_HOOK, array( self::class, 'emit' ), 10, 2 );
		add_action( self::RECONCILE_HOOK, array( self::class, 'reconcile_scheduled' ) );
		add_action( 'init', array( self::class, 'ensure_reconciliation_schedule' ) );
	}

	/** Ensure source recovery runs even if a request crashes after domain commit. */
	public static function ensure_reconciliation_schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) || as_next_scheduled_action( self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP, true );
	}

	/**
	 * Persist one source-backed outbox request after its domain commit.
	 *
	 * @param string $type               Notification type.
	 * @param int    $source_activity_id Source activity ID.
	 */
	public static function emit( string $type, int $source_activity_id ): void {
		if ( ! self::$registered ) {
			return;
		}
		$service = new self();
		$service->schedule_reconciliation();
		$service->request( $type, $source_activity_id );
	}

	/** Run the bounded recovery and reconciliation pass. */
	public static function reconcile_scheduled(): void {
		$result = ( new self() )->reconcile_pending();
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Scheduler-only diagnostic.
		}
	}

	/**
	 * Append an idempotent outbox request for a valid source event.
	 *
	 * @param string $type               Notification type.
	 * @param int    $source_activity_id Source activity ID.
	 * @return array|\WP_Error Request activity or error.
	 */
	public function request( string $type, int $source_activity_id ) {
		$source = $this->validated_source( $type, $source_activity_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		return $this->activity->append(
			array(
				'booking_id'      => $source['booking_id'],
				'kind'            => 'notification_requested',
				'actor_type'      => 'system',
				'external_id'     => (string) $source_activity_id,
				'idempotency_key' => $this->request_key( $type, $source_activity_id ),
				'payload'         => array(
					'notification_type'  => $type,
					'source_activity_id' => $source_activity_id,
				),
			)
		);
	}

	/**
	 * Recover missing requests, then reconcile a bounded pending page.
	 *
	 * @param int $limit Maximum source and request rows per pass.
	 * @return array|\WP_Error Pass summary or error.
	 */
	public function reconcile_pending( int $limit = 50 ) {
		$limit   = max( 1, min( 100, $limit ) );
		$sources = $this->activity->notification_sources_without_requests( $limit );
		if ( is_wp_error( $sources ) ) {
			return $sources;
		}
		$recovered = 0;
		foreach ( $sources as $source ) {
			$type = $this->source_type( $source );
			if ( null === $type ) {
				$ignored = $this->ignore_source( $source );
				if ( is_wp_error( $ignored ) ) {
					return $ignored;
				}
				continue;
			}
			$request = $this->request( $type, (int) $source['id'] );
			if ( is_wp_error( $request ) ) {
				return $request;
			}
			++$recovered;
		}
		$requests = $this->activity->pending_notification_requests( $limit );
		if ( is_wp_error( $requests ) ) {
			return $requests;
		}
		$completed = 0;
		$retry     = false;
		foreach ( $requests as $request ) {
			$result = $this->reconcile( (int) $request['id'] );
			if ( is_wp_error( $result ) ) {
				if ( in_array( $result->get_error_code(), array( 'booking_notification_request_busy', 'booking_notification_retry_not_due' ), true ) ) {
					$retry = true;
					continue;
				}
				return $result;
			}
			if ( in_array( $result['kind'] ?? '', array( 'notification_delivered', 'notification_suppressed' ), true ) ) {
				++$completed;
			} elseif ( 'notification_delivery_attempted' === ( $result['kind'] ?? '' ) ) {
				$retry = true;
			}
		}
		if ( $retry || count( $sources ) === $limit || count( $requests ) === $limit ) {
			$this->schedule_reconciliation();
		}
		return compact( 'recovered', 'completed' );
	}

	/**
	 * Reconcile one outbox request using structured idempotent receipts.
	 *
	 * @param int $request_activity_id Outbox request activity ID.
	 * @return array|\WP_Error Terminal/attempt activity or dependency error.
	 */
	public function reconcile( int $request_activity_id ) {
		$lock = $this->acquire_request_lock( $request_activity_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$result = $this->reconcile_locked( $request_activity_id );
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'booking_notification_request_invalid', 'booking_notification_retry_not_due', 'booking_notification_retry_schedule_read_failed' ), true ) ) {
			$request = $this->activity->get( $request_activity_id );
			if ( is_array( $request ) && 'notification_requested' === ( $request['kind'] ?? '' ) ) {
				$result = $this->record_reconcile_failure( $request, $result );
			}
		}
		$released = $this->release_request_lock( $lock );
		return is_wp_error( $released ) ? $released : $result;
	}

	/** Re-read terminal and due state while the exact request lock is owned. */
	private function reconcile_locked( int $request_activity_id ) {
		$request = $this->activity->get( $request_activity_id );
		$data    = is_array( $request ) ? ( $request['payload']['data'] ?? array() ) : array();
		if ( ! is_array( $request ) || 'notification_requested' !== $request['kind'] || empty( $data['notification_type'] ) || empty( $data['source_activity_id'] ) ) {
			return new \WP_Error( 'booking_notification_request_invalid', __( 'The booking notification request is invalid.', 'extrachill-events' ) );
		}
		$terminal = $this->activity->notification_terminal( $request_activity_id );
		if ( is_wp_error( $terminal ) || is_array( $terminal ) ) {
			return $terminal;
		}
		$due = $this->activity->notification_retry_is_due( $request_activity_id );
		if ( is_wp_error( $due ) || ! $due ) {
			return is_wp_error( $due ) ? $due : new \WP_Error( 'booking_notification_retry_not_due', __( 'The booking notification retry is not due yet.', 'extrachill-events' ), array( 'status' => 425 ) );
		}
		if ( 'booking_assignment_changed' === (string) $data['notification_type'] ) {
			$started = $this->begin();
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			$record = $this->terminal_record( $request, 'notification_suppressed', 'retired_notification_type', array() );
			return is_wp_error( $record ) ? $this->rollback( $record ) : $this->finish( $record );
		}
		$source = $this->validated_source( (string) $data['notification_type'], (int) $data['source_activity_id'] );
		if ( is_wp_error( $source ) || (int) $source['booking_id'] !== (int) $request['booking_id'] ) {
			return is_wp_error( $source ) ? $source : new \WP_Error( 'booking_notification_source_invalid', __( 'The booking notification source activity is invalid.', 'extrachill-events' ) );
		}
		$booking = $this->bookings->get( $request['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		global $wpdb;
		$table   = BookingSchema::memberships_table();
		$members = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes recipients with membership changes.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_notification_membership_read_failed', __( 'Venue notification recipients could not be locked.', 'extrachill-events' ) ) );
		}
		$locked = $this->bookings->get_for_update( $booking['id'] );
		if ( ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return $this->rollback( new \WP_Error( 'booking_notification_booking_changed', __( 'The booking notification target changed.', 'extrachill-events' ) ) );
		}
		$definition    = $this->definition( (string) $data['notification_type'] );
		$recipient_ids = $this->recipient_ids( (array) $members, $locked );
		if ( empty( $recipient_ids ) ) {
			$record = $this->terminal_record( $request, 'notification_suppressed', 'no_active_recipients', array() );
			return is_wp_error( $record ) ? $this->rollback( $record ) : $this->finish( $record );
		}
		try {
			$destination = $this->resolve_destination( $locked, $recipient_ids, (array) $members );
		} catch ( \Throwable $throwable ) {
			$this->schedule_reconciliation();
			return $this->rollback( new \WP_Error( 'booking_notification_destination_uncertain', __( 'The booking management destination could not be resolved.', 'extrachill-events' ) ) );
		}
		if ( is_wp_error( $destination ) ) {
			return $this->rollback( $destination );
		}
		if ( ! is_string( $destination ) || ! preg_match( '#^https?://#i', $destination ) ) {
			return $this->rollback( new \WP_Error( 'booking_notification_destination_invalid', __( 'The booking management destination is invalid.', 'extrachill-events' ) ) );
		}
		$actor_id = $this->actor_id( $source );
		if ( $actor_id < 1 ) {
			return $this->rollback( new \WP_Error( 'booking_notification_actor_unavailable', __( 'A bounded notification actor is unavailable.', 'extrachill-events' ) ) );
		}
		$attempt_count = $this->activity->notification_attempt_count( $request_activity_id );
		if ( is_wp_error( $attempt_count ) ) {
			return $this->rollback( $attempt_count );
		}
		$attempt = $attempt_count + 1;
		try {
			$receipt = $this->deliver_structured(
				$recipient_ids,
				array(
					'actor_id' => $actor_id,
					'type'     => (string) $data['notification_type'],
					'link'     => $destination,
					'title'    => $definition['title'],
					'item_id'  => (int) $locked['id'],
				),
				$this->request_key( (string) $data['notification_type'], (int) $data['source_activity_id'] )
			);
		} catch ( \Throwable $throwable ) {
			$this->schedule_reconciliation();
			return $this->rollback( new \WP_Error( 'booking_notification_delivery_uncertain', __( 'The idempotent notification delivery outcome is uncertain.', 'extrachill-events' ) ) );
		}
		if ( is_wp_error( $receipt ) ) {
			return $this->rollback( $receipt );
		}
		$summary  = $this->receipt_summary( $recipient_ids, $receipt );
		$poisoned = ! $summary['complete'] && $attempt >= self::MAX_ATTEMPTS;
		$due_at   = $poisoned || $summary['complete'] ? null : $this->retry_due_at( $attempt );
		$record   = $this->activity->append(
			array(
				'booking_id'      => $request['booking_id'],
				'kind'            => $summary['complete'] ? 'notification_delivered' : ( $poisoned ? 'notification_suppressed' : 'notification_delivery_attempted' ),
				'actor_type'      => 'system',
				'external_id'     => (string) $request_activity_id,
				'idempotency_key' => $summary['complete'] ? 'notification-terminal:' . $request_activity_id : sprintf( 'notification-attempt:%d:%d', $request_activity_id, $attempt ),
				'payload'         => array(
					'request_activity_id' => $request_activity_id,
					'notification_type'   => $data['notification_type'],
					'attempt'             => $attempt,
					'complete'            => $summary['complete'],
					'inserted_count'      => $summary['inserted'],
					'existing_count'      => $summary['existing'],
					'failed_count'        => $summary['failed'],
					'recipient_count'     => count( $recipient_ids ),
					'reason'              => $poisoned ? 'delivery_poisoned' : null,
					'due_at'              => $due_at,
				),
				'occurred_at'     => $due_at ? $due_at : gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( is_wp_error( $record ) ) {
			return $this->rollback( $record );
		}
		$finished = $this->finish( $record );
		if ( ! $summary['complete'] && ! $poisoned ) {
			$this->schedule_reconciliation( strtotime( $due_at . ' UTC' ) );
		}
		return $finished;
	}

	/** Resolve the type represented by one landed source activity. */
	private function source_type( array $source ): ?string {
		foreach ( $this->definitions() as $type => $definition ) {
			if ( ! empty( $definition['landed'] ) && $this->source_matches( $definition, $source ) ) {
				return $type;
			}
		}
		return null;
	}

	/** Mark an irrelevant landed-kind activity so recovery can advance. */
	private function ignore_source( array $source ) {
		return $this->activity->append(
			array(
				'booking_id'      => $source['booking_id'],
				'kind'            => 'notification_source_ignored',
				'actor_type'      => 'system',
				'external_id'     => (string) $source['id'],
				'idempotency_key' => 'notification-source-ignored:' . $source['id'],
				'payload'         => array( 'source_activity_id' => $source['id'] ),
			)
		);
	}

	/** Validate a type/source pair. */
	private function validated_source( string $type, int $source_activity_id ) {
		$definition = $this->definition( $type );
		$source     = $source_activity_id > 0 ? $this->activity->get( $source_activity_id ) : null;
		return null !== $definition && is_array( $source ) && $this->source_matches( $definition, $source )
			? $source
			: new \WP_Error( 'booking_notification_source_invalid', __( 'The booking notification source activity is invalid.', 'extrachill-events' ) );
	}

	/** Return one notification definition. */
	private function definition( string $type ): ?array {
		$definitions = $this->definitions();
		return $definitions[ $type ] ?? null;
	}

	/** Return landed mappings and future source seams. */
	private function definitions(): array {
		return array(
			self::TYPE_INQUIRY_SUBMITTED       => array(
				'kind'       => 'inquiry_submitted',
				'title'      => __( 'New booking inquiry', 'extrachill-events' ),
				'recipients' => 'owners',
				'landed'     => true,
			),
			self::TYPE_INFORMATION_RECEIVED    => array(
				'kind'       => 'status_changed',
				'title'      => __( 'Booking information received', 'extrachill-events' ),
				'from'       => 'needs_info',
				'to'         => 'submitted',
				'recipients' => 'owners',
				'landed'     => true,
			),
			self::TYPE_HOLD_EXPIRED            => array(
				'kind'       => 'hold_expired',
				'title'      => __( 'Booking hold expired', 'extrachill-events' ),
				'recipients' => 'owners',
				'landed'     => true,
			),
			self::TYPE_EVENT_HANDOFF_FAILED    => array(
				'kind'       => 'event_conversion_failed',
				'title'      => __( 'Booking event handoff failed', 'extrachill-events' ),
				'recipients' => 'owners',
				'landed'     => true,
			),
			self::TYPE_ARTIST_REPLIED          => array(
				'kind'  => 'artist_replied',
				'title' => __( 'Artist replied about a booking', 'extrachill-events' ),
			),
			self::TYPE_HOLD_EXPIRING           => array(
				'kind'  => 'hold_expiring',
				'title' => __( 'Booking hold expiring', 'extrachill-events' ),
			),
			self::TYPE_MARKETING_FAILED        => array(
				'kind'  => 'marketing_action_failed',
				'title' => __( 'Booking marketing action failed', 'extrachill-events' ),
			),
			self::TYPE_SETTLEMENT_READY        => array(
				'kind'  => 'settlement_ready',
				'title' => __( 'Booking settlement ready', 'extrachill-events' ),
			),
			self::TYPE_OPERATOR_ACTION_OVERDUE => array(
				'kind'  => 'operator_action_overdue',
				'title' => __( 'Booking action overdue', 'extrachill-events' ),
			),
		);
	}

	/** Match the complete source contract. */
	private function source_matches( array $definition, array $source ): bool {
		$data = $source['payload']['data'] ?? array();
		return ( $source['kind'] ?? '' ) === $definition['kind']
			&& ( ! isset( $definition['from'] ) || ( ( $data['from_status'] ?? null ) === $definition['from'] && ( $data['to_status'] ?? null ) === $definition['to'] ) );
	}

	/** Resolve active venue owners under membership lock. */
	private function recipient_ids( array $rows, array $booking ): array {
		$owners     = array();
		$repository = new VenueMembershipRepository();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['venue_term_id'] ?? 0 ) !== (int) $booking['venue_term_id'] || VenueAuthorization::STATUS_ACTIVE !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			$member = $repository->hydrate( $row );
			if ( ! is_wp_error( $member ) && get_userdata( $member['user_id'] ) ) {
				if ( $member['is_owner'] ) {
					$owners[] = (int) $member['user_id'];
				}
			}
		}
		return array_values( array_unique( $owners ) );
	}

	/** Resolve an injected destination or the authorized booking console route. */
	private function resolve_destination( array $booking, array $recipient_ids, array $locked_members ) {
		if ( $this->destination ) {
			return call_user_func( $this->destination, $booking, $recipient_ids, $locked_members );
		}
		return function_exists( 'ec_events_resolve_booking_console_destination' )
			? ec_events_resolve_booking_console_destination( $booking, $recipient_ids, $locked_members )
			: new \WP_Error( 'booking_notification_destination_unavailable', __( 'The booking management destination is unavailable.', 'extrachill-events' ) );
	}

	/** Delegate to the landed Users receipt primitive with its exact payload contract. */
	private function deliver_structured( array $recipient_ids, array $payload, string $idempotency_key ) {
		$payload['producer']        = 'extrachill-events-booking';
		$payload['idempotency_key'] = $idempotency_key;
		if ( $this->delivery ) {
			return call_user_func( $this->delivery, $recipient_ids, $payload, $payload['producer'], $idempotency_key );
		}
		return function_exists( 'ec_users_notify_with_receipts' )
			? ec_users_notify_with_receipts( $recipient_ids, $payload )
			: new \WP_Error( 'booking_notification_receipts_unavailable', __( 'Idempotent notification receipts are unavailable.', 'extrachill-events' ) );
	}

	/** Persist dependency failures and poison a request after a bounded attempt count. */
	private function record_reconcile_failure( array $request, \WP_Error $error ) {
		$count = $this->activity->notification_attempt_count( (int) $request['id'] );
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		$attempt = $count + 1;
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			return $this->terminal_record(
				$request,
				'notification_suppressed',
				'delivery_poisoned',
				array(
					'attempt'    => $attempt,
					'error_code' => $error->get_error_code(),
				)
			);
		}
		$due_at = $this->retry_due_at( $attempt );
		$this->schedule_reconciliation( strtotime( $due_at . ' UTC' ) );
		return $this->activity->append(
			array(
				'booking_id'      => $request['booking_id'],
				'kind'            => 'notification_delivery_attempted',
				'actor_type'      => 'system',
				'external_id'     => (string) $request['id'],
				'idempotency_key' => sprintf( 'notification-attempt:%d:%d', $request['id'], $attempt ),
				'payload'         => array(
					'request_activity_id' => $request['id'],
					'attempt'             => $attempt,
					'error_code'          => $error->get_error_code(),
					'due_at'              => $due_at,
				),
				'occurred_at'     => $due_at,
			)
		);
	}

	/** Return the bounded exponential retry due time. */
	private function retry_due_at( int $attempt ): string {
		$delay = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempt - 1 ) ) );
		return gmdate( 'Y-m-d H:i:s', time() + $delay );
	}

	/** Summarize a strict per-recipient Users receipt. */
	private function receipt_summary( array $recipient_ids, $receipt ): array {
		$summary = array(
			'complete' => true,
			'inserted' => 0,
			'existing' => 0,
			'failed'   => 0,
		);
		$rows    = is_array( $receipt ) && is_array( $receipt['recipients'] ?? null ) ? $receipt['recipients'] : array();
		foreach ( $recipient_ids as $user_id ) {
			$status = $rows[ $user_id ]['status'] ?? null;
			if ( 'inserted' === $status ) {
				++$summary['inserted'];
			} elseif ( 'existing' === $status ) {
				++$summary['existing'];
			} else {
				++$summary['failed'];
				$summary['complete'] = false;
			}
		}
		return $summary;
	}

	/** Resolve the source actor or bounded network bot. */
	private function actor_id( array $source ): int {
		if ( $this->actor_resolver ) {
			return absint( call_user_func( $this->actor_resolver, $source ) );
		}
		$actor = absint( $source['actor_id'] ?? 0 );
		if ( 0 < $actor && get_userdata( $actor ) ) {
			return $actor;
		}
		$actor = function_exists( 'ec_get_network_bot_user_id' ) ? absint( ec_get_network_bot_user_id() ) : 0;
		return 0 < $actor && get_userdata( $actor ) ? $actor : 0;
	}

	/** Append one terminal suppression record. */
	private function terminal_record( array $request, string $kind, string $reason, array $details ) {
		return $this->activity->append(
			array(
				'booking_id'      => $request['booking_id'],
				'kind'            => $kind,
				'actor_type'      => 'system',
				'external_id'     => (string) $request['id'],
				'idempotency_key' => 'notification-terminal:' . $request['id'],
				'payload'         => array_merge(
					array(
						'request_activity_id' => $request['id'],
						'reason'              => $reason,
					),
					$details
				),
			)
		);
	}

	/** Stable source-request idempotency key. */
	private function request_key( string $type, int $source_activity_id ): string {
		return sprintf( 'notification-request:%s:%d', $type, $source_activity_id );
	}

	/** Best-effort single-action reconciliation scheduling. */
	private function schedule_reconciliation( ?int $timestamp = null ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		try {
			as_schedule_single_action( $timestamp ? max( time() + 1, $timestamp ) : time() + MINUTE_IN_SECONDS, self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP, true );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/** Acquire exclusive ownership of one notification request attempt. */
	private function acquire_request_lock( int $request_activity_id ) {
		global $wpdb;
		$name     = 'ec_booking_notice_' . substr( hash( 'sha256', get_current_blog_id() . "\0" . $request_activity_id ), 0, 40 );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes one outbox request attempt.
		return 1 === (int) $acquired ? $name : new \WP_Error( 'booking_notification_request_busy', __( 'The booking notification request is already being reconciled.', 'extrachill-events' ) );
	}

	/** Release one notification request lock without hiding uncertainty. */
	private function release_request_lock( string $name ) {
		global $wpdb;
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases one outbox request attempt.
		return 1 === (int) $released ? true : new \WP_Error( 'booking_notification_request_unlock_uncertain', __( 'The booking notification request lock release is uncertain.', 'extrachill-events' ) );
	}

	/** Start a recipient authorization transaction. */
	private function begin() {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reconciliation transaction.
			return new \WP_Error( 'booking_notification_transaction_failed', __( 'The booking notification transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		return true;
	}

	/** Commit the reconciliation transaction. */
	private function finish( array $record ) {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reconciliation transaction.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'booking_notification_commit_uncertain', __( 'The booking notification outcome could not be confirmed.', 'extrachill-events' ) ) : $record;
	}

	/** Roll back while preserving the cause. */
	private function rollback( \WP_Error $cause ): \WP_Error {
		global $wpdb;
		if ( $this->transaction_active ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reconciliation transaction.
			$this->transaction_active = false;
		}
		return $cause;
	}
}
