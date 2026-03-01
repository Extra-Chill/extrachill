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

	// Render the block — filters below provide center and summary.
	echo do_blocks( '<!-- wp:data-machine-events/events-map /-->' );
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
 * Generate summary text with event/venue counts for location maps.
 *
 * Queries venue and event counts directly from the database instead of
 * relying on a pre-filtered venue array.
 *
 * @hook data_machine_events_map_summary
 * @param string $summary Current summary (empty by default).
 * @param array  $venues  Venue data array (empty — dynamic mode).
 * @param array  $context Map context.
 * @return string Summary text.
 */
function extrachill_events_filter_map_summary( string $summary, array $venues, array $context ): string {
	if ( ! $context['is_taxonomy'] || 'location' !== $context['taxonomy'] ) {
		return $summary;
	}

	// Query venue count for this location directly.
	$location_venues = extrachill_events_get_location_venues( $context['term_id'] );
	$venue_count     = count( $location_venues );

	if ( $venue_count === 0 ) {
		return $summary;
	}

	$event_count = extrachill_events_get_upcoming_event_count( $context['term_id'] );

	if ( $event_count > 0 ) {
		return sprintf(
			/* translators: 1: number of events, 2: number of venues */
			__( '%1$d events at %2$d venues', 'extrachill-events' ),
			$event_count,
			$venue_count
		);
	}

	return sprintf(
		/* translators: %d: number of venues */
		_n( '%d venue', '%d venues', $venue_count, 'extrachill-events' ),
		$venue_count
	);
}
add_filter( 'data_machine_events_map_summary', 'extrachill_events_filter_map_summary', 10, 3 );
