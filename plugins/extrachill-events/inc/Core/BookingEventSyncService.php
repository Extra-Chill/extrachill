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
	public const MARKETING_ISSUE = 'https://github.com/Extra-Chill/extrachill-events/issues/296';

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
	}

	/** Apply an approved correction, then reconcile its already-linked event. */
	public function reconcile( int $booking_id, int $expected_version, int $actor_id, array $changes = array() ) {
		$prepared = $this->prepare( $booking_id, $expected_version, $actor_id, $changes );
		if ( is_wp_error( $prepared ) || isset( $prepared['status'] ) ) {
			return $prepared;
		}

		$booking = $prepared['booking'];
		$start   = $prepared['start'];
		$desired = $prepared['authority'];
		$current = $this->read_event_authority( $booking );
		if ( is_wp_error( $current ) ) {
			return $this->finish_error( $booking, $start, $current, 'event_sync_failed' );
		}
		$baseline = $this->activity->latest_event_authority( $booking_id );
		if ( is_wp_error( $baseline ) ) {
			return $this->finish_error( $booking, $start, $baseline, 'event_sync_failed' );
		}
		if ( null === $baseline ) {
			return $this->finish_error(
				$booking,
				$start,
				new \WP_Error(
					'booking_event_sync_baseline_missing',
					__( 'The converted event has no authoritative synchronization baseline.', 'extrachill-events' ),
					array(
						'status'     => 409,
						'repairable' => true,
					)
				),
				'event_sync_conflict'
			);
		}
		if ( 'EventCancelled' !== $desired['eventStatus'] ) {
			$dates_changed          = ( $baseline['startDate'] ?? null ) !== $desired['startDate']
				|| ( $baseline['startTime'] ?? null ) !== $desired['startTime']
				|| ( $baseline['endDate'] ?? null ) !== $desired['endDate']
				|| ( $baseline['endTime'] ?? null ) !== $desired['endTime'];
			$desired['eventStatus'] = $dates_changed ? 'EventRescheduled' : (string) ( $baseline['eventStatus'] ?? 'EventScheduled' );
		}

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
			return $this->finish_success( $booking, $start, $desired, 'event_sync_noop', 'no_change', $baseline );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'data-machine-events/update-event' ) : null;
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

		$content = array( 'event' => (int) $booking['event_id'] );
		foreach ( array( 'startDate', 'startTime', 'endDate', 'endTime', 'ticketUrl', 'performer', 'performerType', 'eventStatus' ) as $field ) {
			if ( ( $current[ $field ] ?? null ) !== $desired[ $field ] ) {
				$content[ $field ] = $desired[ $field ];
			}
		}
		if ( isset( $content['startDate'] ) && 'EventRescheduled' === $desired['eventStatus'] ) {
			$content['previousStartDate'] = (string) ( $baseline['startDate'] ?? '' );
		}
		if ( count( $content ) > 1 ) {
			$result = $this->execute_update( $ability, $content );
			if ( is_wp_error( $result ) ) {
				return $this->finish_error( $booking, $start, $result, 'event_sync_failed' );
			}
		}
		if ( (int) $current['venue_id'] !== (int) $desired['venue_id'] ) {
			$result = $this->execute_update(
				$ability,
				array(
					'event' => (int) $booking['event_id'],
					'venue' => (int) $desired['venue_id'],
				)
			);
			if ( is_wp_error( $result ) ) {
				return $this->finish_error( $booking, $start, $result, 'event_sync_failed' );
			}
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
			return $this->finish_error( $booking, $start, $error, 'event_sync_failed' );
		}
		return $this->finish_success( $booking, $start, $desired, 'event_sync_succeeded', 'updated', $baseline );
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

	/** Lock authorization and aggregate state, apply correction, and persist an outbox intent. */
	private function prepare( int $booking_id, int $expected_version, int $actor_id, array $changes ) {
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
		$normalized = $this->normalize_changes( $booking, $changes, $actor_id );
		if ( is_wp_error( $normalized ) ) {
			return $this->rollback( $normalized );
		}
		if ( $state['pending'] ) {
			if ( $normalized ) {
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
				'booking'   => $booking,
				'start'     => $state['start'],
				'authority' => $state['start']['payload']['data']['authority'],
			);
		}
		if ( (int) $booking['version'] !== $expected_version ) {
			$terminal = $state['terminal'];
			if ( empty( $normalized ) && is_array( $terminal ) && in_array( $terminal['kind'], array( 'event_sync_succeeded', 'event_sync_noop' ), true ) && (int) ( $state['start']['payload']['data']['booking_version'] ?? 0 ) === (int) $booking['version'] ) {
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : array(
					'booking_id'      => $booking['id'],
					'booking_version' => $booking['version'],
					'event_id'        => $booking['event_id'],
					'status'          => 'event_sync_noop' === $terminal['kind'] ? 'no_change' : 'succeeded',
					'code'            => (string) ( $terminal['payload']['data']['code'] ?? 'replayed' ),
					'retryable'       => false,
					'conflicts'       => array(),
				);
			}
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
		$attempt = (int) ( $state['start']['payload']['data']['attempt'] ?? 0 ) + 1;
		$start   = $this->activity->append(
			array(
				'booking_id'      => $booking_id,
				'kind'            => 'event_sync_started',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'idempotency_key' => sprintf( 'event-sync:%s:%d:%d', $booking['public_id'], $booking['version'], $attempt ),
				'payload'         => array(
					'attempt'         => $attempt,
					'booking_version' => $booking['version'],
					'event_id'        => $booking['event_id'],
					'authority'       => $authority,
				),
			)
		);
		if ( is_wp_error( $start ) ) {
			return $this->rollback( $start );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : array(
			'booking'   => $booking,
			'start'     => $start,
			'authority' => $authority,
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
		$venue    = get_term( $booking['venue_term_id'], 'venue' );
		$timezone = (string) get_term_meta( $booking['venue_term_id'], '_venue_timezone', true );
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
		$identity = hash( 'sha256', BookingEventConversionService::SOURCE . "\0" . $booking['public_id'] );
		if ( BookingEventConversionService::SOURCE !== get_post_meta( $booking['event_id'], '_datamachine_event_source', true )
			|| get_post_meta( $booking['event_id'], '_datamachine_event_source_id', true ) !== $booking['public_id']
			|| get_post_meta( $booking['event_id'], '_datamachine_event_source_identity', true ) !== $identity ) {
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

	/** Normalize the DME batch result into one stable error boundary. */
	private function execute_update( $ability, array $input ) {
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$item = is_array( $result['results'][0] ?? null ) ? $result['results'][0] : null;
		if ( ! $item || ! in_array( $item['status'] ?? '', array( 'updated', 'no_change' ), true ) ) {
			$data              = (array) ( $item['error_data'] ?? array() );
			$data['status']    = (int) ( $item['error_status'] ?? $data['status'] ?? 502 );
			$data['retryable'] = $data['status'] >= 500;
			return new \WP_Error( (string) ( $item['error_code'] ?? 'booking_event_update_failed' ), (string) ( $item['error'] ?? 'Canonical event update failed.' ), $data );
		}
		return true;
	}

	private function finish_success( array $booking, array $start, array $authority, string $kind, string $action, array $baseline ) {
		$terminal = $this->append_terminal(
			$booking,
			$start,
			$kind,
			array(
				'code'      => $action,
				'authority' => $authority,
			)
		);
		if ( is_wp_error( $terminal ) ) {
			return $terminal;
		}
		if ( $authority !== $baseline ) {
			$signal = $this->activity->append(
				array(
					'booking_id'      => $booking['id'],
					'kind'            => 'event_marketing_change_signaled',
					'idempotency_key' => 'event-marketing-change:' . $start['id'],
					'external_id'     => (string) $booking['event_id'],
					'payload'         => array(
						'sync_activity_id' => $terminal['id'],
						'event_id'         => $booking['event_id'],
						'before'           => $baseline,
						'after'            => $authority,
						'owner_issue'      => self::MARKETING_ISSUE,
					),
				)
			);
			if ( is_wp_error( $signal ) ) {
				return $signal;
			}
			do_action( 'extrachill_events_booking_event_changed', $booking['id'], $booking['event_id'], $baseline, $authority, (int) $signal['id'] );
		}
		return array(
			'booking_id'      => $booking['id'],
			'booking_version' => $booking['version'],
			'event_id'        => $booking['event_id'],
			'status'          => 'event_sync_noop' === $kind ? 'no_change' : 'succeeded',
			'code'            => $action,
			'retryable'       => false,
			'conflicts'       => array(),
		);
	}

	private function finish_error( array $booking, array $start, \WP_Error $error, string $kind ) {
		$data      = (array) $error->get_error_data();
		$retryable = ! empty( $data['retryable'] ) || (int) ( $data['status'] ?? 0 ) >= 500;
		$terminal  = $this->append_terminal(
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
