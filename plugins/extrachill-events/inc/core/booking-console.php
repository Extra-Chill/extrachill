<?php
/**
 * Venue booking console routes and network menu integration.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the canonical venue workspace URL with deterministic booking context.
 *
 * @param int $venue_term_id Venue term ID, or zero for the default workspace.
 * @param int $booking_id    Optional booking ID.
 */
function ec_events_get_booking_console_url( int $venue_term_id, int $booking_id = 0 ): string {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	$base_url       = $events_blog_id > 0 ? get_home_url( $events_blog_id, '/venue-settings/' ) : home_url( '/venue-settings/' );
	$args           = array();
	if ( $venue_term_id > 0 ) {
		$args['venue_id'] = $venue_term_id;
	}
	if ( $booking_id > 0 ) {
		$args['booking_id'] = $booking_id;
	}
	return ( $args ? add_query_arg( $args, $base_url ) : $base_url ) . '#tab-calendar';
}

/**
 * Resolve a notification destination only when every recipient remains an
 * active member in both the transaction-locked rows and canonical policy.
 *
 * @param array $booking       Locked booking row.
 * @param array $recipient_ids Exact notification recipients.
 * @param array $locked_rows   Transaction-locked venue membership rows.
 * @return string|WP_Error
 */
function ec_events_resolve_booking_console_destination( array $booking, array $recipient_ids, array $locked_rows ) {
	$venue_term_id = absint( $booking['venue_term_id'] ?? 0 );
	$booking_id    = absint( $booking['id'] ?? 0 );
	if ( $venue_term_id < 1 || $booking_id < 1 || empty( $recipient_ids ) ) {
		return new WP_Error( 'booking_notification_destination_uncertain', __( 'The booking management destination could not be authorized.', 'extrachill-events' ) );
	}

	$locked_active = array();
	foreach ( $locked_rows as $row ) {
		if ( absint( $row['venue_term_id'] ?? 0 ) === $venue_term_id && VenueAuthorization::STATUS_ACTIVE === ( $row['status'] ?? '' ) ) {
			$locked_active[ absint( $row['user_id'] ?? 0 ) ] = true;
		}
	}

	$authorization = new VenueAuthorization();
	foreach ( array_unique( array_map( 'absint', $recipient_ids ) ) as $recipient_id ) {
		if ( $recipient_id < 1 || empty( $locked_active[ $recipient_id ] ) || true !== $authorization->authorize( $recipient_id, $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE ) ) {
			return new WP_Error( 'booking_notification_destination_forbidden', __( 'A booking notification recipient no longer has venue access.', 'extrachill-events' ) );
		}
	}

	return ec_events_get_booking_console_url( $venue_term_id, $booking_id );
}

/**
 * Test whether a user has any active venue membership on the Events site.
 *
 * @param int $user_id User ID.
 */
function ec_events_user_has_active_venue_membership( int $user_id ): bool {
	if ( $user_id < 1 || ! function_exists( 'ec_get_blog_id' ) ) {
		return false;
	}
	$events_blog_id = (int) ec_get_blog_id( 'events' );
	if ( $events_blog_id < 1 ) {
		return false;
	}

	$switched = get_current_blog_id() !== $events_blog_id;
	if ( $switched ) {
		switch_to_blog( $events_blog_id );
	}
	try {
		if ( ! BookingSchema::is_ready() ) {
			return false;
		}
		global $wpdb;
		$table = BookingSchema::memberships_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND status = 'active' LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact current-user membership lookup on the Events site.
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Add the venue workspace to the shared avatar menu for active members.
 *
 * @param array $items   Existing custom menu items.
 * @param int   $user_id User ID.
 */
function ec_events_add_manage_venue_avatar_item( array $items, int $user_id ): array {
	if ( ! ec_events_user_has_active_venue_membership( $user_id ) ) {
		return $items;
	}
	$items[] = array(
		'id'       => 'manage_venue',
		'label'    => __( 'Manage Venue', 'extrachill-events' ),
		'url'      => ec_events_get_booking_console_url( 0 ),
		'priority' => 45,
	);
	return $items;
}
add_filter( 'ec_avatar_menu_items', 'ec_events_add_manage_venue_avatar_item', 10, 2 );
