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

	global $wpdb;

	$events_table = $wpdb->prefix . 'posts';
	$tr_table     = $wpdb->prefix . 'term_relationships';
	$tt_table     = $wpdb->prefix . 'term_taxonomy';

	// Uses DateFilter from data-machine-events as the single source of truth
	// for what "upcoming" means. Requires data-machine-events to be active.
	$filter = \DataMachineEvents\Blocks\Calendar\Query\DateFilter::upcoming_sql( $wpdb->prefix . 'postmeta' );
	$now    = current_time( 'mysql' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

	$events = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$events_table} p
			{$filter['joins']}
			WHERE p.post_type = 'data_machine_events' AND p.post_status = 'publish'
			AND {$filter['where']}",
			$now,
			$now
		)
	);

	$venues = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$events_table} p
			{$filter['joins']}
			JOIN {$tr_table} tr ON p.ID = tr.object_id
			JOIN {$tt_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'venue'
			WHERE p.post_type = 'data_machine_events' AND p.post_status = 'publish'
			AND {$filter['where']}",
			$now,
			$now
		)
	);

	$locations = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$events_table} p
			{$filter['joins']}
			JOIN {$tr_table} tr ON p.ID = tr.object_id
			JOIN {$tt_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'location'
			WHERE p.post_type = 'data_machine_events' AND p.post_status = 'publish'
			AND {$filter['where']}",
			$now,
			$now
		)
	);

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

	$stats = array(
		'events'    => $events,
		'venues'    => $venues,
		'locations' => $locations,
	);

	set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

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
