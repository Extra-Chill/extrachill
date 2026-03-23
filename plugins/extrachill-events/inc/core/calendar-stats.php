<?php
/**
 * Calendar Stats
 *
 * Renders a live stats line showing upcoming event/venue/location counts.
 * Stats are cached in a transient (1 hour) to avoid heavy queries on every page load.
 *
 * @package ExtraChillEvents
 * @since 0.12.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get calendar stats (cached).
 *
 * @return array{events: int, venues: int, locations: int}
 */
function extrachill_events_get_calendar_stats(): array {
	$cache_key = 'extrachill_calendar_stats';
	$cached    = get_transient( $cache_key );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	// Requires data-machine-events to be active.
	if ( ! class_exists( '\DataMachineEvents\Abilities\EventDateQueryAbilities' ) ) {
		return array( 'events' => 0, 'venues' => 0, 'locations' => 0 );
	}

	// Count upcoming events via query-events ability.
	$event_query = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
	$result      = $event_query->executeQueryEvents( array(
		'scope'  => 'upcoming',
		'fields' => 'count',
	) );
	$events = (int) $result['total'];

	// Count upcoming venues and locations via get-upcoming-counts ability.
	$counts_ability = new \DataMachineEvents\Abilities\UpcomingCountAbilities();

	$venue_result = $counts_ability->executeGetUpcomingCounts( array(
		'taxonomy'      => 'venue',
		'exclude_roots' => false,
	) );
	$venues = count( $venue_result['terms'] ?? array() );

	$location_result = $counts_ability->executeGetUpcomingCounts( array(
		'taxonomy'      => 'location',
		'exclude_roots' => false,
	) );
	$locations = count( $location_result['terms'] ?? array() );

	$stats = array(
		'events'    => $events,
		'venues'    => $venues,
		'locations' => $locations,
	);

	set_transient( $cache_key, $stats, 6 * HOUR_IN_SECONDS );

	return $stats;
}

/**
 * Render the calendar stats line.
 *
 * Output: "25,318 upcoming events at 596 venues in 56 locations"
 */
function extrachill_events_render_calendar_stats(): void {
	$stats = extrachill_events_get_calendar_stats();

	if ( $stats['events'] < 1 ) {
		return;
	}

	printf(
		'<p class="calendar-stats">%s upcoming events at %s venues in %s locations</p>',
		esc_html( number_format( $stats['events'] ) ),
		esc_html( number_format( $stats['venues'] ) ),
		esc_html( number_format( $stats['locations'] ) )
	);
}
