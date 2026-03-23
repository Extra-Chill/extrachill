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
	$venue_data = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( $term_id );
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
	$now = current_time( 'mysql' );
	$event_date_filter = function ( $clauses ) use ( $now ) {
		global $wpdb;
		$table = \DataMachineEvents\Core\EventDatesTable::table_name();
		if ( strpos( $clauses['join'], $table ) === false ) {
			$clauses['join'] .= " INNER JOIN {$table} AS ed ON {$wpdb->posts}.ID = ed.post_id";
		}
		$clauses['where'] .= $wpdb->prepare( ' AND ed.start_datetime >= %s', $now );
		return $clauses;
	};
	add_filter( 'posts_clauses', $event_date_filter );

	$query = new \WP_Query(
		array(
			'post_type'      => 'data_machine_events',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'venue',
					'terms'    => $term_id,
				),
			),
		)
	);

	remove_filter( 'posts_clauses', $event_date_filter );

	return $query->found_posts;
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

	echo do_blocks( '<!-- wp:data-machine-events/events-map {"zoom":14} /-->' );
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
 * Generate summary text for venue maps.
 *
 * @hook data_machine_events_map_summary
 * @param string $summary Current summary.
 * @param array  $venues  Venue data array.
 * @param array  $context Map context.
 * @return string Summary text.
 */
function extrachill_events_filter_venue_map_summary( string $summary, array $venues, array $context ): string {
	if ( ! $context['is_taxonomy'] || 'venue' !== $context['taxonomy'] ) {
		return $summary;
	}

	$event_count = extrachill_events_get_upcoming_venue_event_count( $context['term_id'] );

	if ( $event_count > 0 ) {
		return sprintf(
			/* translators: %d: number of upcoming events */
			_n( '%d upcoming event', '%d upcoming events', $event_count, 'extrachill-events' ),
			$event_count
		);
	}

	return __( 'Venue location', 'extrachill-events' );
}
add_filter( 'data_machine_events_map_summary', 'extrachill_events_filter_venue_map_summary', 10, 3 );
