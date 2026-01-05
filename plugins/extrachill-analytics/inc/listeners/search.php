<?php
/**
 * Search Event Listener
 *
 * Tracks search queries when fired by extrachill-search.
 *
 * @package ExtraChill\Analytics
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Track search event.
 *
 * @param string $search_term   The search query.
 * @param int    $result_count  Total number of results found.
 * @param string $referer       The page user was on before searching.
 */
function ec_analytics_track_search( $search_term, $result_count, $referer ) {
	if ( ! function_exists( 'ec_track_event' ) ) {
		return;
	}

	// Skip empty searches
	if ( empty( trim( $search_term ) ) ) {
		return;
	}

	ec_track_event(
		'search',
		array(
			'search_term'  => $search_term,
			'result_count' => (int) $result_count,
		),
		$referer ?: ''
	);
}

add_action( 'extrachill_search_performed', 'ec_analytics_track_search', 10, 3 );
