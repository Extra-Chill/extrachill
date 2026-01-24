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
function extrachill_analytics_track_search( $search_term, $result_count, $referer ) {
	if ( empty( trim( $search_term ) ) ) {
		return;
	}

	wp_execute_ability(
		'extrachill/track-analytics-event',
		array(
			'event_type' => 'search',
			'event_data' => array(
				'search_term'  => $search_term,
				'result_count' => (int) $result_count,
			),
			'source_url' => $referer ?: '',
		)
	);
}

add_action( 'extrachill_search_performed', 'extrachill_analytics_track_search', 10, 3 );
