<?php
/**
 * Near Me City Grid Rows
 *
 * Shapes canonical upcoming-count rows for the Near Me "Browse by City"
 * grid. The events home "Active scenes" block and the location directory
 * consume the same rows from /extrachill/v1/events/upcoming-counts (backed
 * by the extrachill/events-upcoming-counts ability), so the Near Me grid
 * shows the same numbers and the same ordering as those surfaces.
 *
 * @package ExtraChillEvents
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Build Near Me city-card rows from canonical upcoming-count rows.
 *
 * The owning data-machine-events get-upcoming-counts ability returns
 * location rows ordered by upcoming event count DESC and already limited to
 * terms with at least one upcoming event. This helper adapts those rows for
 * the Near Me grid: it keeps only terms that carry city-center coordinates
 * (state and region terms do not), re-resolves each term link at render
 * time, and caps the list — preserving the source ordering so the grid
 * ranks cities exactly like the events home does.
 *
 * @param array $rows  Rows from the /extrachill/v1/events/upcoming-counts source.
 * @param int   $limit Maximum number of city cards to return.
 * @return array City-card rows: term_id, name, slug, count, url.
 */
function extrachill_events_near_me_city_rows( array $rows, int $limit = 20 ): array {
	$cities = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$term_id = absint( $row['term_id'] ?? 0 );
		$count   = absint( $row['count'] ?? 0 );
		if ( $term_id < 1 || $count < 1 ) {
			continue;
		}

		// The map fallback needs city-center coordinates; hierarchy terms
		// above the city level do not carry them.
		if ( null === extrachill_events_get_location_coordinates( $term_id ) ) {
			continue;
		}

		$term = get_term( $term_id, 'location' );
		if ( ! $term instanceof WP_Term ) {
			continue;
		}

		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			continue;
		}

		$cities[] = array(
			'term_id' => $term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
			'count'   => $count,
			'url'     => $url,
		);

		if ( count( $cities ) >= $limit ) {
			break;
		}
	}

	return $cities;
}
