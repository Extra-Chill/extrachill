<?php
/**
 * ExtraChill Events Home Action Hooks
 *
 * Hook-based homepage component registration system. Registers location badges
 * for filtering the calendar by city on the events homepage, and venue badges
 * scoped by city on the location taxonomy archive.
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

/**
 * Render venue badges on a location taxonomy archive
 *
 * Lists every venue with upcoming events in the current city, mirroring the
 * homepage location badge graph one layer deeper.
 *
 * @hook extrachill_archive_below_description
 * @return void
 * @since 0.22.0
 */
function extrachill_events_location_archive_venue_badges() {
	if ( ! is_tax( 'location' ) ) {
		return;
	}
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/location-venue-badges.php';
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_location_archive_venue_badges', 5 );
