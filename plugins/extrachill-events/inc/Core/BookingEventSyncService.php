<?php
/**
 * Post-conversion booking/event synchronization policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Synchronizes only the public fields which remain booking-authoritative. */
class BookingEventSyncService {
	/** @var array|null Exact DME input currently delegated by this service. */
	private static $active_source_update_input;

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var BookingLifecycle */
	private $lifecycle;
	/** @var BookingCommunicationService */
	private $communication;
	/** @var string[] */
	private $acquired_locks = array();
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?BookingLifecycle $lifecycle = null, ?BookingCommunicationService $communication = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->lifecycle     = $lifecycle ? $lifecycle : new BookingLifecycle( $this->bookings, $this->activity, $this->authorization );
		$this->communication = $communication ? $communication : new BookingCommunicationService( $this->bookings, $this->activity, $this->authorization );
	}

	/** Hold all affected venue publication locks through booking and DME commits. */
	public function reconcile( int $booking_id, int $expected_version, int $actor_id, array $changes = array() ) {
		$request = array(
			'expected_version' => $expected_version,
			'changes'          => $this->canonical_request_changes( $changes ),
		);
		$locked  = $this->acquire_venue_locks( $booking_id, $changes );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			if ( ! empty( $changes['cancelled'] ) ) {
				$cancelled = $this->canonical_cancellation( $booking_id, $expected_version, $actor_id );
				if ( is_wp_error( $cancelled ) ) {
					$result = $cancelled;
				} else {
					$expected_version = (int) $cancelled['version'];
					unset( $changes['cancelled'] );
				}
			}
			if ( ! isset( $result ) ) {
				$result = $this->reconcile_locked( $booking_id, $expected_version, $actor_id, $changes, $request );
			}
		} finally {
			$released = $this->release_venue_locks();
		}
		return is_wp_error( $released ) ? $released : $result;
	}

	/** Apply an approved correction, then reconcile its already-linked event. */
	private function reconcile_locked( int $booking_id, int $expected_version, int $actor_id, array $changes, array $request ) {
		$prepared = $this->prepare( $booking_id, $expected_version, $actor_id, $changes, $request );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( isset( $prepared['status'] ) ) {
			return $this->repair_marketing_signal( $prepared );
		}

		$booking = $prepared['booking'];
		$start   = $prepared['start'];
		$desired = $prepared['authority'];
		$current = $this->read_event_authority( $booking );
		if ( is_wp_error( $current ) ) {
			return $this->finish_error( $booking, $start, $current, 'event_sync_failed' );
		}
		$baseline = $prepared['baseline'];
		if ( 'EventCancelled' !== $desired['eventStatus'] ) {
			$dates_changed          = ( $baseline['startDate'] ?? null ) !== $desired['startDate']
				|| ( $baseline['startTime'] ?? null ) !== $desired['startTime']
				|| ( $baseline['endDate'] ?? null ) !== $desired['endDate']
				|| ( $baseline['endTime'] ?? null ) !== $desired['endTime'];
			$desired['eventStatus'] = $dates_changed ? 'EventRescheduled' : (string) ( $baseline['eventStatus'] ?? 'EventScheduled' );
		}

		$conflicts = $this->authority_conflicts( $baseline, $current, $desired );
		if ( $conflicts ) {
			return $this->finish_error(
				$booking,
				$start,
				new \WP_Error(
					'booking_event_manual_divergence',
					__( 'Manual changes conflict with booking-authoritative event fields.', 'extrachill-events' ),
					array(
						'status'    => 409,
						'conflicts' => $conflicts,
					)
				),
				'event_sync_conflict'
			);
		}
		if ( $current === $desired ) {
			return $this->finish_success( $booking, $start, $desired, 'event_sync_noop', 'no_change', $baseline, $prepared['fingerprint'] );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'data-machine-events/update-source-event' ) : null;
		if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) ) {
			return $this->finish_error(
				$booking,
				$start,
				new \WP_Error(
					'booking_event_update_ability_unavailable',
					__( 'Canonical event updates are temporarily unavailable.', 'extrachill-events' ),
					array(
						'status'    => 503,
						'retryable' => true,
					)
				),
				'event_sync_failed'
			);
		}

		$content = array(
			'event'                => (int) $booking['event_id'],
			'source'               => BookingEventConversionService::SOURCE,
			'source_id'            => $booking['public_id'],
			'source_identity'      => hash( 'sha256', BookingEventConversionService::SOURCE . "\0" . $booking['public_id'] ),
			'expected_fingerprint' => $prepared['fingerprint'],
		);
		foreach ( array( 'startDate', 'startTime', 'endDate', 'endTime', 'ticketUrl', 'performer', 'performerType', 'eventStatus' ) as $field ) {
			if ( ( $current[ $field ] ?? null ) !== $desired[ $field ] ) {
				$content[ $field ] = $desired[ $field ];
			}
		}
		if ( isset( $content['startDate'] ) && 'EventRescheduled' === $desired['eventStatus'] ) {
			$content['previousStartDate'] = (string) ( $baseline['startDate'] ?? '' );
		}
		if ( (int) $current['venue_id'] !== (int) $desired['venue_id'] ) {
			$content['venue'] = (int) $desired['venue_id'];
		}
		$result = $this->execute_update( $ability, $content );
		if ( is_wp_error( $result ) && 'source_event_fingerprint_conflict' === $result->get_error_code() && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $result->get_error_data()['fingerprint'] ?? '' ) ) ) {
			$refreshed = $this->read_event_authority( $booking );
			if ( is_wp_error( $refreshed ) ) {
				return $this->finish_error( $booking, $start, $refreshed, 'event_sync_failed' );
			}
			$conflicts = $this->authority_conflicts( $baseline, $refreshed, $desired );
			if ( $conflicts ) {
				return $this->finish_error( $booking, $start, $this->conflict_error( $conflicts ), 'event_sync_conflict' );
			}
			$content['expected_fingerprint'] = (string) $result->get_error_data()['fingerprint'];
			$result                          = $this->execute_update( $ability, $content );
		}
		if ( is_wp_error( $result ) ) {
			return $this->finish_error( $booking, $start, $result, 'event_sync_failed' );
		}

		$verified = $this->read_event_authority( $booking );
		if ( is_wp_error( $verified ) || $verified !== $desired ) {
			$error = is_wp_error( $verified ) ? $verified : new \WP_Error(
				'booking_event_sync_verification_failed',
				__( 'The canonical event did not retain the requested booking fields.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
					'actual'    => $verified,
				)
			);
			$error->add_data(
				array_merge(
					(array) $error->get_error_data(),
					array(
						'fingerprint' => (string) $result['fingerprint'],
						'retryable'   => true,
					)
				)
			);
			return $this->finish_error( $booking, $start, $error, 'event_sync_failed' );
		}
		return $this->finish_success( $booking, $start, $desired, 'event_sync_succeeded', (string) $result['action'], $baseline, (string) $result['fingerprint'] );
	}

	/** Build the canonical field-level authority snapshot used at conversion and sync. */
	public static function authority_from_event( array $event, int $venue_id ): array {
		return array(
			'startDate'     => (string) ( $event['startDate'] ?? '' ),
			'startTime'     => (string) ( $event['startTime'] ?? '' ),
			'endDate'       => (string) ( $event['endDate'] ?? '' ),
			'endTime'       => (string) ( $event['endTime'] ?? '' ),
			'venue_id'      => $venue_id,
			'performer'     => (string) ( $event['performer'] ?? '' ),
			'performerType' => (string) ( $event['performerType'] ?? 'PerformingGroup' ),
			'ticketUrl'     => (string) ( $event['ticketUrl'] ?? '' ),
			'eventStatus'   => (string) ( $event['eventStatus'] ?? 'EventScheduled' ),
		);
	}

	/** Return only manual changes that diverge from both accepted and desired state. */
	private function authority_conflicts( array $baseline, array $current, array $desired ): array {
		$conflicts = array();
		foreach ( $desired as $field => $value ) {
			$previous = $baseline[ $field ] ?? null;
			$actual   = $current[ $field ] ?? null;
			if ( $actual !== $previous && $actual !== $value ) {
				$conflicts[ $field ] = array(
					'previous' => $previous,
					'current'  => $actual,
					'booking'  => $value,
				);
			}
		}
		return $conflicts;
	}

	private function conflict_error( array $conflicts ): \WP_Error {
		return new \WP_Error(
			'booking_event_manual_divergence',
			__( 'Manual changes conflict with booking-authoritative event fields.', 'extrachill-events' ),
			array(
				'status'    => 409,
				'conflicts' => $conflicts,
			)
		);
	}

	/** Lock authorization and aggregate state, apply correction, and persist an outbox intent. */
	private function prepare( int $booking_id, int $expected_version, int $actor_id, array $changes, array $request ) {
		$initial = $this->bookings->get( $booking_id );
		if ( ! is_array( $initial ) ) {
			return is_wp_error( $initial ) ? $initial : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$allowed = $this->authorize( $actor_id, (int) $initial['venue_term_id'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( false === $GLOBALS['wpdb']->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'booking_event_sync_transaction_start_failed', __( 'Booking event synchronization could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		$table                    = BookingSchema::memberships_table();
		$venue_ids                = array( (int) $initial['venue_term_id'] );
		if ( ! empty( $changes['venue_term_id'] ) ) {
			$venue_ids[] = absint( $changes['venue_term_id'] );
		}
		$venue_ids = array_values( array_unique( array_filter( $venue_ids ) ) );
		sort( $venue_ids, SORT_NUMERIC );
		foreach ( $venue_ids as $venue_id ) {
			$GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stable exact-venue authority lock order.
		}
		$booking = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		$allowed = $this->authorize( $actor_id, (int) $booking['venue_term_id'] );
		if ( is_wp_error( $allowed ) ) {
			return $this->rollback( $allowed );
		}
		if ( null === $booking['event_id'] ) {
			return $this->rollback( new \WP_Error( 'booking_event_not_converted', __( 'The booking does not have a canonical event.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( ! in_array( $booking['status'], array( 'confirmed', 'cancelled' ), true ) ) {
			return $this->rollback(
				new \WP_Error(
					'booking_event_sync_status_forbidden',
					__( 'Only confirmed or cancelled converted bookings can synchronize events.', 'extrachill-events' ),
					array(
						'status'         => 409,
						'booking_status' => $booking['status'],
					)
				)
			);
		}
		$state = $this->activity->event_sync_state( $booking_id );
		if ( is_wp_error( $state ) ) {
			return $this->rollback( $state );
		}
		$request_fingerprint = hash( 'sha256', (string) wp_json_encode( $request ) );
		if ( $state['pending'] ) {
			if ( ! hash_equals( (string) ( $state['start']['payload']['data']['request_fingerprint'] ?? '' ), $request_fingerprint ) ) {
				return $this->rollback(
					new \WP_Error(
						'booking_event_sync_pending',
						__( 'A different booking event correction is already pending reconciliation.', 'extrachill-events' ),
						array(
							'status'    => 409,
							'retryable' => true,
						)
					)
				);
			}
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : array(
				'booking'     => $booking,
				'start'       => $state['start'],
				'authority'   => $state['start']['payload']['data']['authority'],
				'baseline'    => $state['start']['payload']['data']['baseline'],
				'fingerprint' => (string) ( $state['retry']['payload']['data']['fingerprint'] ?? $state['start']['payload']['data']['fingerprint'] ),
			);
		}
		$terminal = $state['terminal'];
		if ( is_array( $terminal ) && in_array( $terminal['kind'], array( 'event_sync_succeeded', 'event_sync_noop' ), true ) && hash_equals( (string) ( $state['start']['payload']['data']['request_fingerprint'] ?? '' ), $request_fingerprint ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : array(
				'booking_id'      => $booking['id'],
				'booking_version' => $booking['version'],
				'event_id'        => $booking['event_id'],
				'status'          => 'event_sync_noop' === $terminal['kind'] ? 'no_change' : 'succeeded',
				'code'            => (string) ( $terminal['payload']['data']['code'] ?? 'replayed' ),
				'retryable'       => false,
				'conflicts'       => array(),
				'_sync_terminal'  => $terminal,
			);
		}
		$normalized = $this->normalize_changes( $booking, $changes, $actor_id );
		if ( is_wp_error( $normalized ) ) {
			return $this->rollback( $normalized );
		}
		if ( (int) $booking['version'] !== $expected_version ) {
			return $this->rollback(
				new \WP_Error(
					'booking_version_conflict',
					__( 'The booking changed since it was read.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $booking['version'],
					)
				)
			);
		}
		if ( $normalized ) {
			$updated = $this->persist_changes( $booking, $normalized );
			if ( is_wp_error( $updated ) ) {
				return $this->rollback( $updated );
			}
			$booking = $updated;
		}
		$authority = $this->authority_from_booking( $booking );
		if ( is_wp_error( $authority ) ) {
			return $this->rollback( $authority );
		}
		$snapshot = $this->activity->latest_event_snapshot( $booking_id );
		if ( is_wp_error( $snapshot ) || null === $snapshot ) {
			return $this->rollback(
				is_wp_error( $snapshot ) ? $snapshot : new \WP_Error(
					'booking_event_sync_baseline_missing',
					__( 'The converted event has no authoritative synchronization baseline.', 'extrachill-events' ),
					array(
						'status'     => 409,
						'repairable' => true,
					)
				)
			);
		}
		$attempt = (int) ( $state['start']['payload']['data']['attempt'] ?? 0 ) + 1;
		$start   = $this->activity->append(
			array(
				'booking_id'      => $booking_id,
				'kind'            => 'event_sync_started',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'idempotency_key' => sprintf( 'event-sync:%s:%d:%d', $booking['public_id'], $booking['version'], $attempt ),
				'payload'         => array(
					'attempt'             => $attempt,
					'booking_version'     => $booking['version'],
					'event_id'            => $booking['event_id'],
					'authority'           => $authority,
					'baseline'            => $snapshot['authority'],
					'fingerprint'         => $snapshot['fingerprint'],
					'request'             => $request,
					'request_fingerprint' => $request_fingerprint,
				),
			)
		);
		if ( is_wp_error( $start ) ) {
			return $this->rollback( $start );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : array(
			'booking'     => $booking,
			'start'       => $start,
			'authority'   => $authority,
			'baseline'    => $snapshot['authority'],
			'fingerprint' => $snapshot['fingerprint'],
		);
	}

	/** Normalize the intentionally narrow post-conversion correction surface. */
	private function normalize_changes( array $booking, array $changes, int $actor_id ) {
		$allowed = array( 'venue_term_id', 'space_key', 'performance_start_at', 'performance_end_at', 'performer', 'ticket_url', 'cancelled' );
		if ( array_diff( array_keys( $changes ), $allowed ) ) {
			return new \WP_Error( 'booking_event_sync_changes_invalid', __( 'An unsupported booking-authoritative field was supplied.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$output = array();
		if ( isset( $changes['venue_term_id'] ) && (int) $changes['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			$venue_id = absint( $changes['venue_term_id'] );
			$venue    = get_term( $venue_id, 'venue' );
			$access   = $this->authorize( $actor_id, $venue_id );
			if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy || is_wp_error( $access ) ) {
				return is_wp_error( $access ) ? $access : new \WP_Error( 'booking_event_venue_invalid', __( 'The corrected canonical venue is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$output['venue_term_id'] = $venue_id;
		}
		if ( array_key_exists( 'space_key', $changes ) ) {
			$space = mb_substr( sanitize_key( (string) $changes['space_key'] ), 0, 64 );
			if ( '' === $space ) {
				return new \WP_Error( 'booking_event_selection_incomplete', __( 'A corrected performance space is required.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$output['space_key'] = $space;
		}
		foreach ( array( 'performance_start_at', 'performance_end_at' ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$value = (string) $changes[ $field ];
				$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
				if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
					return new \WP_Error(
						'invalid_booking_datetime',
						__( 'Booking datetimes must use strict UTC format.', 'extrachill-events' ),
						array(
							'status' => 400,
							'field'  => $field,
						)
					);
				}
				$output[ $field ] = $value;
			}
		}
		$start = $output['performance_start_at'] ?? $booking['performance_start_at'];
		$end   = $output['performance_end_at'] ?? $booking['performance_end_at'];
		if ( $end <= $start ) {
			return new \WP_Error( 'invalid_booking_date_range', __( 'The performance end must be later than its start.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'performer', $changes ) ) {
			$performer = mb_substr( sanitize_text_field( (string) $changes['performer'] ), 0, 255 );
			if ( '' === $performer ) {
				return new \WP_Error( 'booking_event_performer_invalid', __( 'The corrected lineup is required.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$output['artist_name'] = $performer;
		}
		if ( array_key_exists( 'ticket_url', $changes ) ) {
			$url = null === $changes['ticket_url'] || '' === $changes['ticket_url'] ? null : esc_url_raw( (string) $changes['ticket_url'] );
			if ( null !== $url && ! preg_match( '#^https?://#i', $url ) ) {
				return new \WP_Error( 'invalid_booking_deal', __( 'The ticket URL must be HTTP or HTTPS.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$deal = $booking['confirmed_deal']['data'];
			if ( ( $deal['ticket_url'] ?? null ) !== $url ) {
				$deal['ticket_url']               = $url;
				$output['confirmed_deal_payload'] = wp_json_encode(
					array(
						'version' => 1,
						'data'    => $deal,
					)
				);
			}
		}
		if ( ! empty( $changes['cancelled'] ) ) {
			if ( ! in_array( $booking['status'], array( 'confirmed', 'cancelled' ), true ) ) {
				return new \WP_Error( 'booking_transition_forbidden', __( 'Only a confirmed booking can be cancelled through event synchronization.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$output['status'] = 'cancelled';
		}
		return array_filter(
			$output,
			static function ( $value, $key ) use ( $booking ) {
				$current_key = 'confirmed_deal_payload' === $key ? null : $key;
				return null === $current_key || ( $booking[ $current_key ] ?? null ) !== $value;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/** Persist booking corrections and keep the converted hold aligned. */
	private function persist_changes( array $booking, array $changes ) {
		global $wpdb;
		$table  = BookingSchema::bookings_table();
		$set    = array();
		$values = array();
		foreach ( $changes as $column => $value ) {
			$set[]    = null === $value ? "{$column} = NULL" : "{$column} = %s";
			$values[] = $value;
		}
		$set[]    = 'version = version + 1';
		$set[]    = 'updated_at = %s';
		$values[] = gmdate( 'Y-m-d H:i:s' );
		$values[] = $booking['id'];
		$values[] = $booking['version'];
		$result   = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND version = %d', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal columns with prepared values.
		if ( 1 !== $result ) {
			return new \WP_Error(
				'booking_event_sync_booking_update_failed',
				__( 'The approved booking correction could not be saved.', 'extrachill-events' ),
				array(
					'status'         => 409,
					'database_error' => $wpdb->last_error,
				)
			);
		}
		if ( array_intersect( array_keys( $changes ), array( 'venue_term_id', 'space_key', 'performance_start_at', 'performance_end_at' ) ) ) {
			$holds = BookingSchema::holds_table();
			$next  = $this->bookings->get_for_update( (int) $booking['id'] );
			$hold  = $wpdb->query( $wpdb->prepare( "UPDATE {$holds} SET venue_term_id = %d, space_key = %s, start_at = %s, end_at = %s, version = version + 1, updated_at = %s WHERE booking_id = %d AND status = 'converted'", $next['venue_term_id'], $next['space_key'], $next['performance_start_at'], $next['performance_end_at'], gmdate( 'Y-m-d H:i:s' ), $booking['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Converted selection belongs to this aggregate.
			if ( 1 !== $hold ) {
				return new \WP_Error(
					'booking_event_sync_hold_update_failed',
					__( 'The converted booking selection could not be corrected.', 'extrachill-events' ),
					array(
						'status'         => 409,
						'database_error' => $wpdb->last_error,
					)
				);
			}
		}
		return $this->bookings->get_for_update( (int) $booking['id'] );
	}

	/** Map the current booking through the same public conversion rules. */
	private function authority_from_booking( array $booking ) {
		$venue = get_term( $booking['venue_term_id'], 'venue' );
		if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
			return new \WP_Error(
				'booking_event_venue_contract_unavailable',
				__( 'Canonical venue data is temporarily unavailable.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}
		$venue_data = data_machine_events_get_venue_data( (int) $booking['venue_term_id'] );
		if ( ! is_array( $venue_data ) ) {
			return new \WP_Error(
				'booking_event_venue_contract_invalid',
				__( 'Canonical venue data returned an invalid result.', 'extrachill-events' ),
				array(
					'status'    => 502,
					'retryable' => true,
				)
			);
		}
		$timezone = (string) ( $venue_data['timezone'] ?? '' );
		try {
			$zone = new \DateTimeZone( $timezone );
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'booking_event_venue_timezone_invalid', __( 'The canonical venue timezone is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( ! $venue || is_wp_error( $venue ) ) {
			return new \WP_Error( 'booking_event_venue_invalid', __( 'The canonical venue is unavailable.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$utc   = new \DateTimeZone( 'UTC' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $booking['performance_start_at'], $utc );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $booking['performance_end_at'], $utc );
		$deal  = $booking['confirmed_deal']['data'] ?? array();
		return self::authority_from_event(
			array(
				'startDate'     => $start->setTimezone( $zone )->format( 'Y-m-d' ),
				'startTime'     => $start->setTimezone( $zone )->format( 'H:i' ),
				'endDate'       => $end->setTimezone( $zone )->format( 'Y-m-d' ),
				'endTime'       => $end->setTimezone( $zone )->format( 'H:i' ),
				'performer'     => $booking['artist_name'],
				'performerType' => 'PerformingGroup',
				'ticketUrl'     => (string) ( $deal['ticket_url'] ?? '' ),
				'eventStatus'   => 'cancelled' === $booking['status'] ? 'EventCancelled' : 'EventScheduled',
			),
			(int) $booking['venue_term_id']
		);
	}

	/** Read only the public event fields governed by this policy. */
	private function read_event_authority( array $booking ) {
		$post = get_post( $booking['event_id'] );
		$type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		if ( ! $post || ( $post->post_type ?? '' ) !== $type ) {
			return new \WP_Error( 'booking_event_existing_invalid', __( 'The linked site-local event is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$snapshot = $this->activity->latest_event_snapshot( (int) $booking['id'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$activity = is_array( $snapshot ) ? ( $snapshot['activity'] ?? null ) : null;
		$data     = is_array( $activity ) ? ( $activity['payload']['data'] ?? null ) : null;
		$linked   = is_array( $data ) && (int) ( $data['event_id'] ?? 0 ) === (int) $booking['event_id'];
		if ( $linked && 'event_converted' === ( $activity['kind'] ?? '' ) ) {
			$identity = hash( 'sha256', BookingEventConversionService::SOURCE . "\0" . $booking['public_id'] );
			$linked   = ( $data['source'] ?? null ) === BookingEventConversionService::SOURCE
				&& ( $data['source_id'] ?? null ) === $booking['public_id']
				&& ( $data['source_identity'] ?? null ) === $identity;
		}
		if ( ! $linked ) {
			return new \WP_Error( 'booking_event_identity_mismatch', __( 'The linked event does not belong to this immutable booking handoff.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$attrs = array();
		foreach ( parse_blocks( (string) ( $post->post_content ?? '' ) ) as $block ) {
			if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
				$attrs = (array) ( $block['attrs'] ?? array() );
				break;
			}
		}
		$venues = wp_get_object_terms( $booking['event_id'], 'venue', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $venues ) || 1 !== count( (array) $venues ) ) {
			return new \WP_Error( 'booking_event_venue_invalid', __( 'The linked event must have exactly one canonical venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return self::authority_from_event( $attrs, (int) reset( $venues ) );
	}

	/** Normalize the DME source-owned result into one stable error boundary. */
	private function execute_update( $ability, array $input ) {
		self::$active_source_update_input = $input;
		try {
			$result = $ability->execute( $input );
		} finally {
			self::$active_source_update_input = null;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result )
			|| true !== ( $result['success'] ?? null )
			|| ! in_array( $result['action'] ?? '', array( 'updated', 'no_change' ), true )
			|| ! is_int( $result['event_id'] ?? null )
			|| $result['event_id'] !== $input['event']
			|| ( $result['previous_fingerprint'] ?? null ) !== $input['expected_fingerprint']
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['fingerprint'] ?? '' ) ) ) {
			return new \WP_Error(
				'booking_event_update_failed',
				__( 'Canonical event update returned an invalid source-owned result.', 'extrachill-events' ),
				array(
					'status'    => 502,
					'retryable' => true,
				)
			);
		}
		return $result;
	}

	/** Confirm that DME is authorizing the exact nested write assembled here. */
	public static function is_active_source_update( array $input ): bool {
		return is_array( self::$active_source_update_input ) && self::$active_source_update_input === $input;
	}

	private function finish_success( array $booking, array $start, array $authority, string $kind, string $action, array $baseline, string $fingerprint ) {
		$terminal = $this->append_terminal(
			$booking,
			$start,
			$kind,
			array(
				'code'        => $action,
				'authority'   => $authority,
				'baseline'    => $baseline,
				'fingerprint' => $fingerprint,
			)
		);
		if ( is_wp_error( $terminal ) ) {
			return $terminal;
		}
		$result = array(
			'booking_id'      => $booking['id'],
			'booking_version' => $booking['version'],
			'event_id'        => $booking['event_id'],
			'status'          => 'event_sync_noop' === $kind ? 'no_change' : 'succeeded',
			'code'            => $action,
			'retryable'       => false,
			'conflicts'       => array(),
			'_sync_terminal'  => $terminal,
		);
		return $this->repair_marketing_signal( $result );
	}

	private function finish_error( array $booking, array $start, \WP_Error $error, string $kind ) {
		$data      = (array) $error->get_error_data();
		$retryable = ! empty( $data['retryable'] ) || (int) ( $data['status'] ?? 0 ) >= 500;
		if ( $retryable ) {
			$retry = $this->activity->append(
				array(
					'booking_id'      => $booking['id'],
					'kind'            => 'event_sync_retryable',
					'external_id'     => (string) $start['id'],
					'idempotency_key' => 'event-sync-retryable:' . $start['id'] . ':' . hash( 'sha256', (string) wp_json_encode( $data ) ),
					'payload'         => array(
						'code'        => $error->get_error_code(),
						'fingerprint' => (string) ( $data['fingerprint'] ?? $start['payload']['data']['fingerprint'] ),
					),
				)
			);
			return is_wp_error( $retry ) ? $retry : new \WP_Error(
				$error->get_error_code(),
				$error->get_error_message(),
				array_merge(
					$data,
					array(
						'retryable'         => true,
						'booking_committed' => true,
						'sync_activity_id'  => $start['id'],
					)
				)
			);
		}
		$terminal = $this->append_terminal(
			$booking,
			$start,
			$kind,
			array(
				'code'       => $error->get_error_code(),
				'retryable'  => $retryable,
				'error_data' => $data,
			)
		);
		if ( is_wp_error( $terminal ) ) {
			return $terminal;
		}
		return new \WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array_merge(
				$data,
				array(
					'retryable'         => $retryable,
					'booking_committed' => true,
					'sync_activity_id'  => $terminal['id'],
				)
			)
		);
	}

	private function append_terminal( array $booking, array $start, string $kind, array $payload ) {
		return $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => $kind,
				'external_id'     => (string) $start['id'],
				'idempotency_key' => $kind . ':' . $start['id'],
				'payload'         => array_merge(
					array(
						'attempt'         => $start['payload']['data']['attempt'],
						'booking_version' => $booking['version'],
						'event_id'        => $booking['event_id'],
					),
					$payload
				),
			)
		);
	}

	/** Normalize the public request shape before hashing and durable storage. */
	private function canonical_request_changes( array $changes ): array {
		ksort( $changes, SORT_STRING );
		return $changes;
	}

	/** Route cancellation through the canonical lifecycle and recoverable suppression saga. */
	private function canonical_cancellation( int $booking_id, int $expected_version, int $actor_id ) {
		$current = $this->bookings->get( $booking_id );
		if ( ! is_array( $current ) ) {
			return is_wp_error( $current ) ? $current : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		if ( 'confirmed' === $current['status'] && (int) $current['version'] === $expected_version ) {
			$current = $this->lifecycle->transition( $booking_id, 'cancelled', $expected_version, $actor_id );
		} elseif ( 'cancelled' !== $current['status'] || (int) $current['version'] < $expected_version + 1 ) {
			return new \WP_Error(
				'booking_version_conflict',
				__( 'The booking changed since cancellation was requested.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'current_version' => $current['version'],
				)
			);
		}
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$suppressed = $this->communication->suppress_pending_reminders( $booking_id, 'booking_status_changed' );
		if ( is_wp_error( $suppressed ) ) {
			$suppressed->add_data(
				array_merge(
					(array) $suppressed->get_error_data(),
					array(
						'booking_committed' => true,
						'repairable'        => true,
					)
				)
			);
			return $suppressed;
		}
		return $current;
	}

	/** Acquire old/new venue locks in global numeric order before any transaction. */
	private function acquire_venue_locks( int $booking_id, array $changes ) {
		global $wpdb;
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$booking = $this->bookings->get( $booking_id );
			if ( ! is_array( $booking ) ) {
				return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
			}
			$old_venue_id = (int) $booking['venue_term_id'];
			$venue_ids    = array( $old_venue_id, absint( $changes['venue_term_id'] ?? 0 ) );
			$venue_ids    = array_values( array_unique( array_filter( $venue_ids ) ) );
			sort( $venue_ids, SORT_NUMERIC );
			foreach ( $venue_ids as $venue_id ) {
				$name     = BookingHoldRepository::venue_lock_name( $venue_id );
				$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Shared publication lock acquired before transaction.
				if ( '1' !== (string) $acquired ) {
					$this->release_venue_locks();
					return new \WP_Error(
						'booking_event_venue_lock_unavailable',
						__( 'A booking venue is currently being changed; retry the request.', 'extrachill-events' ),
						array(
							'status'    => 409,
							'retryable' => true,
							'venue_id'  => $venue_id,
						)
					);
				}
				$this->acquired_locks[] = $name;
			}
			$current = $this->bookings->get( $booking_id );
			if ( is_array( $current ) && (int) $current['venue_term_id'] === $old_venue_id ) {
				return true;
			}
			$released = $this->release_venue_locks();
			if ( is_wp_error( $released ) ) {
				return $released;
			}
		}
		return new \WP_Error(
			'booking_event_venue_lock_race',
			__( 'The booking venue changed while synchronization locks were acquired.', 'extrachill-events' ),
			array(
				'status'    => 409,
				'retryable' => true,
			)
		);
	}

	/** Release only this synchronization invocation's advisory locks. */
	private function release_venue_locks() {
		global $wpdb;
		$error = null;
		foreach ( array_reverse( $this->acquired_locks ) as $name ) {
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only acquired synchronization locks.
			if ( '1' !== (string) $released && null === $error ) {
				$error = new \WP_Error(
					'booking_event_venue_unlock_uncertain',
					__( 'A booking venue lock release could not be confirmed.', 'extrachill-events' ),
					array(
						'status'    => 503,
						'retryable' => true,
						'lock_name' => $name,
					)
				);
			}
		}
		$this->acquired_locks = array();
		return $error ? $error : true;
	}

	/** Idempotently repair the owner-neutral marketing handoff before replaying success. */
	private function repair_marketing_signal( array $result ) {
		$terminal = $result['_sync_terminal'] ?? null;
		unset( $result['_sync_terminal'] );
		if ( ! is_array( $terminal ) ) {
			return $result;
		}
		$data   = (array) ( $terminal['payload']['data'] ?? array() );
		$before = $data['baseline'] ?? null;
		$after  = $data['authority'] ?? null;
		if ( ! is_array( $before ) || ! is_array( $after ) || $before === $after ) {
			return $result;
		}
		$key      = 'event-marketing-change:' . $terminal['external_id'];
		$existing = $this->activity->find_by_idempotency( (int) $result['booking_id'], $key );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$signal = $existing;
		if ( ! is_array( $signal ) ) {
			$signal = $this->activity->append(
				array(
					'booking_id'      => $result['booking_id'],
					'kind'            => 'event_marketing_change_signaled',
					'idempotency_key' => $key,
					'external_id'     => (string) $result['event_id'],
					'payload'         => array(
						'sync_activity_id' => $terminal['id'],
						'event_id'         => $result['event_id'],
						'before'           => $before,
						'after'            => $after,
					),
				)
			);
		}
		if ( is_wp_error( $signal ) ) {
			return $this->repairable_side_effect_error( $signal );
		}
		$delivered_key = 'event-marketing-change-delivered:' . $signal['id'];
		$delivered     = $this->activity->find_by_idempotency( (int) $result['booking_id'], $delivered_key );
		if ( is_wp_error( $delivered ) ) {
			return $delivered;
		}
		if ( is_array( $delivered ) ) {
			return $result;
		}
		do_action( 'extrachill_events_booking_event_changed', $result['booking_id'], $result['event_id'], $before, $after, (int) $signal['id'] );
		$delivered = $this->activity->append(
			array(
				'booking_id'      => $result['booking_id'],
				'kind'            => 'event_marketing_change_delivered',
				'idempotency_key' => $delivered_key,
				'external_id'     => (string) $signal['id'],
				'payload'         => array(
					'sync_activity_id'   => $terminal['id'],
					'signal_activity_id' => $signal['id'],
				),
			)
		);
		return is_wp_error( $delivered ) ? $this->repairable_side_effect_error( $delivered ) : $result;
	}

	private function repairable_side_effect_error( \WP_Error $error ): \WP_Error {
		$error->add_data(
			array_merge(
				(array) $error->get_error_data(),
				array(
					'booking_committed' => true,
					'repairable'        => true,
				)
			)
		);
		return $error;
	}

	private function authorize( int $actor_id, int $venue_id ) {
		$allowed = $this->authorization->authorize( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? true : ( is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
	}

	private function commit() {
		$result                   = $GLOBALS['wpdb']->query( 'COMMIT' );
		$this->transaction_active = false;
		return false === $result ? new \WP_Error(
			'booking_event_sync_commit_uncertain',
			__( 'The booking synchronization transaction outcome is uncertain.', 'extrachill-events' ),
			array(
				'status'    => 503,
				'retryable' => true,
			)
		) : true;
	}

	private function rollback( \WP_Error $error ) {
		if ( $this->transaction_active ) {
			$GLOBALS['wpdb']->query( 'ROLLBACK' );
			$this->transaction_active = false;
		}
		return $error;
	}
}
