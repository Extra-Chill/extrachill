<?php
/**
 * Location Map
 *
 * Renders the data-machine-events/events-map block on location archive pages.
 * Sets map center from location coordinates and generates summary text.
 *
 * The map operates in dynamic mode — venues are fetched via REST API based
 * on the taxonomy context passed through data attributes. The extrachill
 * layer only needs to provide the center point and summary text.
 *
 * @package ExtraChillEvents
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the events-map block on location archive pages.
 *
 * Outputs the block markup via do_blocks() — the block handles its own
 * asset loading and rendering. The map block reads taxonomy/term_id from
 * data attributes and passes them to the REST endpoint for filtering.
 *
 * @hook extrachill_archive_below_description
 */
function extrachill_events_render_location_map() {
	if ( ! is_tax( 'location' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return;
	}

	// Only render if this location has coordinates.
	$center = extrachill_events_get_location_coordinates( $term->term_id );
	if ( ! $center ) {
		return;
	}

	// Render via the shared archive-map helper — filters below provide center
	// and summary. The helper applies the block-native collapse toggle (#377,
	// open by default) and the reduced height so the map no longer dominates
	// the archive (data-machine-events#373).
	echo extrachill_events_render_archive_map( array(), 'location' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted block markup from do_blocks().
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_render_location_map' );

/**
 * Set map center to the location's coordinates.
 *
 * @hook data_machine_events_map_center
 * @param array|null $center  Current center or null.
 * @param array      $context Map context.
 * @return array|null Center coordinates array or null.
 */
function extrachill_events_filter_map_center( $center, array $context ) {
	if ( ! $context['is_taxonomy'] || 'location' !== $context['taxonomy'] ) {
		return $center;
	}

	return extrachill_events_get_location_coordinates( $context['term_id'] );
}
add_filter( 'data_machine_events_map_center', 'extrachill_events_filter_map_center', 10, 2 );

/**
 * Suppress the map summary on location archives.
 *
 * As of #107, the archive header now renders a canonical upcoming-events
 * stats line ("N upcoming events at N venues") via
 * `extrachill_events_render_term_calendar_stats()`. Showing the same
 * counts inside the map's summary slot duplicated the number on the
 * page, so we return an empty string here. The map itself still
 * renders — only its overlay summary text is dropped.
 *
 * @hook data_machine_events_map_summary
 * @param string $summary Current summary (empty by default).
 * @param array  $venues  Venue data array (empty — dynamic mode).
 * @param array  $context Map context.
 * @return string Summary text. Empty on location archives.
 */
function extrachill_events_filter_map_summary( string $summary, array $venues, array $context ): string {
	if ( ! $context['is_taxonomy'] || 'location' !== $context['taxonomy'] ) {
		return $summary;
	}

	// Counts moved to the archive-header stats line; map stands on its own.
	return '';
}
add_filter( 'data_machine_events_map_summary', 'extrachill_events_filter_map_summary', 10, 3 );
