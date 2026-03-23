<?php
/**
 * ExtraChill Events Home Action Hooks
 *
 * Hook-based homepage component registration system. Registers location badges
 * for filtering the calendar by city on the events homepage.
 *
 * @package ExtraChillEvents
 * @since 0.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render location badges above the calendar
 *
 * @hook extrachill_events_home_before_calendar
 * @return void
 */
function extrachill_events_location_badges() {
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/home/location-badges.php';
}
add_action( 'extrachill_events_home_before_calendar', 'extrachill_events_location_badges', 10 );
