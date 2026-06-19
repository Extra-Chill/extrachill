<?php
/**
 * Venue Map
 *
 * Renders the data-machine-events/events-map block on venue archive pages.
 * Sets map center from venue coordinates and generates summary text.
 *
 * @package ExtraChillEvents
 * @since 0.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get parsed coordinates for a venue term.
 *
 * @param int $term_id Venue term ID.
 * @return array|null Array with 'lat' and 'lon' floats, or null if not set.
 */
function extrachill_events_get_venue_coordinates( int $term_id ): ?array {
	if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
		return null;
	}

	$venue_data  = data_machine_events_get_venue_data( $term_id );
	$coordinates = $venue_data['coordinates'] ?? '';

	if ( empty( $coordinates ) || strpos( $coordinates, ',' ) === false ) {
		return null;
	}

	$parts = explode( ',', $coordinates );
	$lat   = floatval( trim( $parts[0] ) );
	$lon   = floatval( trim( $parts[1] ) );

	if ( 0.0 === $lat && 0.0 === $lon ) {
		return null;
	}

	return array(
		'lat' => $lat,
		'lon' => $lon,
	);
}

/**
 * Count upcoming events for a venue term.
 *
 * @param int $term_id Venue term ID.
 * @return int Number of upcoming events.
 */
function extrachill_events_get_upcoming_venue_event_count( int $term_id ): int {
	if ( ! function_exists( 'data_machine_events_query_events' ) ) {
		return 0;
	}

	$result = data_machine_events_query_events(
		array(
			'scope'       => 'upcoming',
			'tax_filters' => array( 'venue' => array( $term_id ) ),
			'fields'      => 'count',
		)
	);

	return (int) ( $result['total'] ?? 0 );
}

/**
 * Render the events-map block on venue archive pages.
 *
 * @hook extrachill_archive_below_description
 */
function extrachill_events_render_venue_map() {
	if ( ! is_tax( 'venue' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->term_id ) ) {
		return;
	}

	$center = extrachill_events_get_venue_coordinates( $term->term_id );
	if ( ! $center ) {
		return;
	}

	// Collapsible, height-reduced map so it stays secondary to the event list
	// (data-machine-events#373). Mirrors the location archive treatment.
	$height    = extrachill_events_archive_map_height();
	$map_block = sprintf( '<!-- wp:data-machine-events/events-map {"zoom":14,"height":%d} /-->', $height );
	echo extrachill_events_render_collapsible_map( do_blocks( $map_block ), 'venue' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper escapes its own chrome; inner markup is trusted block output.
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_render_venue_map' );

/**
 * Set map center to the venue's coordinates.
 *
 * @hook data_machine_events_map_center
 * @param array|null $center  Current center or null.
 * @param array      $context Map context.
 * @return array|null Center coordinates array or null.
 */
function extrachill_events_filter_venue_map_center( $center, array $context ) {
	if ( ! $context['is_taxonomy'] || 'venue' !== $context['taxonomy'] ) {
		return $center;
	}

	return extrachill_events_get_venue_coordinates( $context['term_id'] );
}
add_filter( 'data_machine_events_map_center', 'extrachill_events_filter_venue_map_center', 10, 2 );

/**
 * Suppress the map summary on venue archives.
 *
 * As of #107, the archive header renders a canonical upcoming-events
 * stats line ("N upcoming events") via
 * `extrachill_events_render_term_calendar_stats()`. Showing the same
 * count inside the map's summary slot duplicated the number on the
 * page, so we return an empty string here. The map itself still
 * renders — only its overlay summary text is dropped. Mirrors the
 * location-archive treatment in `location-map.php`.
 *
 * @hook data_machine_events_map_summary
 * @param string $summary Current summary.
 * @param array  $venues  Venue data array.
 * @param array  $context Map context.
 * @return string Summary text. Empty on venue archives.
 */
function extrachill_events_filter_venue_map_summary( string $summary, array $venues, array $context ): string {
	if ( ! $context['is_taxonomy'] || 'venue' !== $context['taxonomy'] ) {
		return $summary;
	}

	// Counts moved to the archive-header stats line; map stands on its own.
	return '';
}
add_filter( 'data_machine_events_map_summary', 'extrachill_events_filter_venue_map_summary', 10, 3 );
