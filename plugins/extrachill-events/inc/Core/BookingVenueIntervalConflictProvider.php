<?php
/**
 * Booking-owned conflict provider for canonical event publication.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BookingVenueIntervalConflictProvider {

	public function __construct() {
		add_filter( 'extrachill_events_venue_interval_conflicts', array( $this, 'collect_conflicts' ), 10, 5 );
	}

	/** Preserve other providers' records and fail closed on read errors. */
	public function collect_conflicts( $conflicts, int $venue_id, string $start_at, string $end_at, array $context ) {
		if ( is_wp_error( $conflicts ) ) {
			return $conflicts;
		}
		$intervals = $context['candidate_intervals'] ?? array();
		if ( empty( $intervals ) ) {
			$intervals = array(
				array(
					'start_at' => $start_at,
					'end_at'   => $end_at,
				),
			);
		}
		$conflict = $this->find_booking_conflict( $venue_id, $start_at, $end_at, (int) ( $context['post_id'] ?? 0 ), (int) ( $context['excluded_booking_id'] ?? 0 ), $intervals );
		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}
		if ( $conflict ) {
			$conflicts[] = $conflict;
		}
		return $conflicts;
	}

	private function find_booking_conflict( int $venue_id, string $start_at, string $end_at, int $post_id, int $excluded_booking_id, array $candidate_intervals ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$hold_exact    = array();
		$booking_exact = array();
		$exact_values  = array();
		foreach ( $candidate_intervals as $interval ) {
			$hold_exact[]    = '(start_at = %s AND end_at = %s)';
			$booking_exact[] = '(performance_start_at = %s AND performance_end_at = %s)';
			$exact_values[]  = (string) $interval['start_at'];
			$exact_values[]  = (string) $interval['end_at'];
		}
		$hold_exact_sql    = implode( ' OR ', $hold_exact );
		$booking_exact_sql = implode( ' OR ', $booking_exact );
		$bookings          = BookingSchema::bookings_table();
		if ( $post_id > 0 ) {
			$linked = $wpdb->get_row( $wpdb->prepare( "SELECT id, venue_term_id, space_key, performance_start_at, performance_end_at, 'confirmed_booking' AS conflict_type FROM {$bookings} WHERE event_id = %d AND status = 'confirmed' LIMIT 1", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A linked booking may not be silently detached by moving its event.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error(
					'canonical_event_booking_conflict_check_failed',
					__( 'The event-linked booking could not be checked safely.', 'extrachill-events' ),
					array(
						'status'         => 503,
						'database_error' => $wpdb->last_error,
					)
				);
			}
			if ( is_array( $linked ) ) {
				$exact = (int) $linked['venue_term_id'] === $venue_id;
				foreach ( $candidate_intervals as $interval ) {
					if ( $exact && $linked['performance_start_at'] === $interval['start_at'] && $linked['performance_end_at'] === $interval['end_at'] ) {
						$linked = null;
						break;
					}
				}
				if ( is_array( $linked ) ) {
					return $linked;
				}
			}
		}
		$holds       = BookingSchema::holds_table();
		$hold_values = array_merge( array( $venue_id, $end_at, $start_at, $excluded_booking_id ), $exact_values );
		$row         = $wpdb->get_row( $wpdb->prepare( "SELECT id, booking_id, space_key, 'hold' AS conflict_type FROM {$holds} WHERE venue_term_id = %d AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND start_at < %s AND end_at > %s AND NOT (booking_id = %d AND ({$hold_exact_sql})) LIMIT 1", $hold_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic exact-interval placeholders are matched by the flattened value array while the stable venue-wide lock is held.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'canonical_event_booking_conflict_check_failed',
				__( 'Venue booking holds could not be checked safely.', 'extrachill-events' ),
				array(
					'status'         => 503,
					'database_error' => $wpdb->last_error,
				)
			);
		}
		if ( is_array( $row ) ) {
			return $row;
		}

		$booking_values = array_merge( array( $venue_id, $end_at, $start_at, $excluded_booking_id, $post_id ), $exact_values );
		$row            = $wpdb->get_row( $wpdb->prepare( "SELECT id, space_key, 'confirmed_booking' AS conflict_type FROM {$bookings} WHERE venue_term_id = %d AND status = 'confirmed' AND performance_start_at < %s AND performance_end_at > %s AND NOT ((id = %d OR event_id = %d) AND ({$booking_exact_sql})) LIMIT 1", $booking_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic exact-interval placeholders are matched by the flattened value array; only an exact originating booking is exempt.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'canonical_event_booking_conflict_check_failed',
				__( 'Confirmed venue bookings could not be checked safely.', 'extrachill-events' ),
				array(
					'status'         => 503,
					'database_error' => $wpdb->last_error,
				)
			);
		}
		return is_array( $row ) ? $row : null;
	}
}
