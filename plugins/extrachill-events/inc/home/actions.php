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
 * Render aggregate calendar totals below the homepage discovery links.
 *
 * @hook extrachill_events_home_before_calendar
 * @return void
 */
function extrachill_events_home_calendar_stats() {
	extrachill_events_render_calendar_stats();
}
add_action( 'extrachill_events_home_before_calendar', 'extrachill_events_home_calendar_stats', 15 );

/**
 * Render the homepage promoted-event card, gated on the viewer's Local Scene.
 *
 * @hook extrachill_events_home_before_calendar
 * @return void
 * @since 0.68.0
 */
function extrachill_events_home_promoted_event_card() {
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/home/promoted-event-card.php';
}
add_action( 'extrachill_events_home_before_calendar', 'extrachill_events_home_promoted_event_card', 18 );

/**
 * Render the homepage feature cards (My Shows + Submit) below the badges.
 *
 * @hook extrachill_events_home_before_calendar
 * @return void
 * @since 0.25.0
 */
function extrachill_events_home_feature_cards() {
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/home/feature-cards.php';
}
add_action( 'extrachill_events_home_before_calendar', 'extrachill_events_home_feature_cards', 20 );

/**
 * Render promoted upcoming events on a location taxonomy archive
 *
 * Prepends 1-3 promoted events above the chronological calendar/description
 * furniture. Runs before the venue badges (priority 5) so the promotion
 * reads first.
 *
 * @hook extrachill_archive_below_description
 * @return void
 * @since 0.68.0
 */
function extrachill_events_location_archive_promoted_events() {
	if ( ! is_tax( 'location' ) ) {
		return;
	}
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/location-promoted-events.php';
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_location_archive_promoted_events', 4 );

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
