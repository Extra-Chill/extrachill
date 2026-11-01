<?php
/**
 * Booking contributions to the venue settings server bootstrap.
 *
 * @package ExtraChillEvents
 */

namespace ExtraChillEvents\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Read a non-negative booking selection query argument.
 *
 * @param string $key Query argument.
 * @return int
 */
function venue_settings_query_id( string $key ): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection; abilities reauthorize every operation.
	return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
}

/**
 * Preserve the booking console login destination.
 *
 * @param string $redirect Default destination.
 * @param int    $venue_id Requested venue.
 * @return string
 */
function venue_settings_login_redirect( string $redirect, int $venue_id ): string {
	return ec_events_get_booking_console_url( $venue_id ? $venue_id : venue_settings_query_id( 'booking_venue_id' ), venue_settings_query_id( 'booking_id' ) );
}
add_filter( 'extrachill_events_venue_settings_login_redirect', __NAMESPACE__ . '\\venue_settings_login_redirect', 10, 2 );

/**
 * Append the booking URL before the venue's timezone and link-page fields.
 *
 * @param array         $venue Venue bootstrap data.
 * @param \WP_Term|null $term Venue term, if available.
 * @return array
 */
function venue_settings_venue_data( array $venue, $term ): array {
	$venue['booking_url'] = $venue['can_access'] && $term instanceof \WP_Term ? \ExtraChillEvents\Core\VenueBookingEmbed::booking_url( $term ) : '';
	return $venue;
}
add_filter( 'extrachill_events_venue_settings_venue_data', __NAMESPACE__ . '\\venue_settings_venue_data', 10, 2 );

/**
 * Append booking selection before the editor availability flag.
 *
 * @param array      $context Page bootstrap data.
 * @param array|null $selected Selected venue.
 * @param array      $venues Managed venues.
 * @return array
 */
function venue_settings_boot_data( array $context, ?array $selected, array $venues ): array {
	if ( ! array_key_exists( 'venues', $context ) ) {
		return $context;
	}
	$booking_id       = venue_settings_query_id( 'booking_id' );
	$requested_venue  = venue_settings_query_id( 'booking_venue_id' );
	$booking_venue_id = $selected && $selected['can_access'] ? (int) $selected['id'] : 0;
	if ( ! $selected && $booking_id && $requested_venue ) {
		foreach ( $venues as $venue ) {
			if ( $requested_venue === $venue['id'] && $venue['can_access'] ) {
				$booking_venue_id = $venue['id'];
				break;
			}
		}
	}
	$context['booking_id']       = $booking_venue_id ? $booking_id : 0;
	$context['booking_venue_id'] = $booking_venue_id;
	$context['booking_url']      = $selected['booking_url'] ?? '';
	return $context;
}
add_filter( 'extrachill_events_venue_settings_boot_data', __NAMESPACE__ . '\\venue_settings_boot_data', 10, 3 );
