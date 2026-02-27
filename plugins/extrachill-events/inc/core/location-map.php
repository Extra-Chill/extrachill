<?php
/**
 * Location Map
 *
 * Hooks into the datamachine-events/events-map block to display venue maps
 * on location archive pages. Filters venues by city, sets map center from
 * location coordinates, and generates summary text.
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
 * asset loading and rendering. Filter callbacks below customize the
 * venue list, center point, and summary text.
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

	// Render the block — filters below provide the data.
	echo do_blocks( '<!-- wp:datamachine-events/events-map /-->' );
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_render_location_map' );

/**
 * Filter map venues to only show venues in the current location's city.
 *
 * @hook datamachine_events_map_venues
 * @param array $venues  All venues with coordinates.
 * @param array $context Map context with taxonomy/term info.
 * @return array Filtered venues matching the location.
 */
function extrachill_events_filter_map_venues( array $venues, array $context ): array {
	if ( ! $context['is_taxonomy'] || 'location' !== $context['taxonomy'] ) {
		return $venues;
	}

	$location = get_term( $context['term_id'], 'location' );
	if ( ! $location || is_wp_error( $location ) ) {
		return $venues;
	}

	$city_name = $location->name;

	// Filter to only venues whose _venue_city matches this location.
	$filtered = array();
	foreach ( $venues as $venue ) {
		$venue_city = get_term_meta( $venue['term_id'], '_venue_city', true );

		if ( ! empty( $venue_city ) && strcasecmp( $venue_city, $city_name ) === 0 ) {
			$filtered[] = $venue;
		}
	}

	// Sort by priority first, then by event count descending.
	$priority_ids = function_exists( 'ec_get_priority_venue_ids' ) ? ec_get_priority_venue_ids() : array();
	usort( $filtered, function ( $a, $b ) use ( $priority_ids ) {
		$a_priority = in_array( $a['term_id'], $priority_ids, true ) ? 1 : 0;
		$b_priority = in_array( $b['term_id'], $priority_ids, true ) ? 1 : 0;

		if ( $a_priority !== $b_priority ) {
			return $b_priority - $a_priority;
		}

		return $b['event_count'] - $a['event_count'];
	} );

	return $filtered;
}
add_filter( 'datamachine_events_map_venues', 'extrachill_events_filter_map_venues', 10, 2 );

/**
 * Set map center to the location's coordinates.
 *
 * @hook datamachine_events_map_center
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
add_filter( 'datamachine_events_map_center', 'extrachill_events_filter_map_center', 10, 2 );

/**
 * Generate summary text with event/venue counts for location maps.
 *
 * @hook datamachine_events_map_summary
 * @param string $summary Current summary (empty by default).
 * @param array  $venues  Venue data array.
 * @param array  $context Map context.
 * @return string Summary text.
 */
function extrachill_events_filter_map_summary( string $summary, array $venues, array $context ): string {
	if ( ! $context['is_taxonomy'] || 'location' !== $context['taxonomy'] ) {
		return $summary;
	}

	$venue_count = count( $venues );
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
add_filter( 'datamachine_events_map_summary', 'extrachill_events_filter_map_summary', 10, 3 );
