<?php
/**
 * Upcoming Event Counts Endpoint
 *
 * Returns counts of upcoming events (date >= today) for taxonomy terms.
 * Used by cross-site linking and blog homepage badges.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_upcoming_counts_route' );

/**
 * Register the upcoming counts endpoint
 */
function extrachill_api_register_events_upcoming_counts_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/upcoming-counts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_upcoming_counts_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'taxonomy' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'venue', 'location', 'artist', 'festival' ),
					'description'       => 'Taxonomy to query: venue, location, artist, or festival',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'slug'     => array(
					'required'          => false,
					'type'              => 'string',
					'description'       => 'Specific term slug. If provided, returns single term data.',
					'sanitize_callback' => 'sanitize_title',
				),
				'limit'    => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'description'       => 'Max terms to return for bulk queries. 0 = unlimited (default).',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handle upcoming counts request
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_upcoming_counts_handler( WP_REST_Request $request ) {
	$taxonomy = $request->get_param( 'taxonomy' );
	$slug     = $request->get_param( 'slug' );
	$limit    = (int) $request->get_param( 'limit' );

	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id ) {
		return new WP_Error(
			'events_site_unavailable',
			__( 'Events site is not configured.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	// Single term query
	if ( ! empty( $slug ) ) {
		$result = extrachill_api_get_single_term_upcoming_count( $slug, $taxonomy, $events_blog_id );
		if ( ! $result ) {
			return rest_ensure_response( null );
		}
		return rest_ensure_response( $result );
	}

	// Bulk query - top terms by count
	$results = extrachill_api_get_bulk_upcoming_counts( $taxonomy, $events_blog_id, $limit );
	return rest_ensure_response( $results );
}

/**
 * Get upcoming event count for a single term
 *
 * @param string $slug            Term slug.
 * @param string $taxonomy        Taxonomy slug.
 * @param int    $events_blog_id  Events site blog ID.
 * @return array|null Term data or null if not found/no events.
 */
function extrachill_api_get_single_term_upcoming_count( $slug, $taxonomy, $events_blog_id ) {
	switch_to_blog( $events_blog_id );
	try {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$count = extrachill_api_count_upcoming_events_for_term( $term->term_id, $taxonomy );
		if ( $count < 1 ) {
			return null;
		}

		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return null;
		}

		return array(
			'slug'  => $term->slug,
			'name'  => $term->name,
			'count' => $count,
			'url'   => $url,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Get bulk upcoming counts for top terms.
 *
 * Uses a single SQL query with GROUP BY instead of N separate WP_Query calls.
 * Results are cached in a transient for 30 minutes.
 *
 * @param string $taxonomy        Taxonomy slug.
 * @param int    $events_blog_id  Events site blog ID.
 * @param int    $limit           Max terms to return.
 * @return array Array of term data sorted by count descending.
 */
function extrachill_api_get_bulk_upcoming_counts( $taxonomy, $events_blog_id, $limit ) {
	$cache_key = 'ec_upcoming_counts_' . $taxonomy;

	switch_to_blog( $events_blog_id );
	try {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			$results = $cached;
			if ( $limit > 0 ) {
				$results = array_slice( $results, 0, $limit );
			}
			return $results;
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		global $wpdb;

		$today = gmdate( 'Y-m-d 00:00:00' );

		// Single query: count upcoming events per term, grouped by term.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS event_count
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE tt.taxonomy = %s
				AND p.post_type = 'data_machine_events'
				AND p.post_status = 'publish'
				AND pm.meta_key = '_datamachine_event_datetime'
				AND pm.meta_value >= %s
				AND tt.parent != 0
				GROUP BY t.term_id
				ORDER BY event_count DESC",
				$taxonomy,
				$today
			)
		);

		if ( empty( $rows ) ) {
			set_transient( $cache_key, array(), 30 * MINUTE_IN_SECONDS );
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$url = get_term_link( (int) $row->term_id, $taxonomy );
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$results[] = array(
				'slug'  => $row->slug,
				'name'  => $row->name,
				'count' => (int) $row->event_count,
				'url'   => $url,
			);
		}

		set_transient( $cache_key, $results, 30 * MINUTE_IN_SECONDS );
	} finally {
		restore_current_blog();
	}

	return $limit > 0 ? array_slice( $results, 0, $limit ) : $results;
}

/**
 * Count upcoming events for a single term.
 *
 * Used by the single-term query path (slug parameter).
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @return int Count of upcoming events.
 */
function extrachill_api_count_upcoming_events_for_term( $term_id, $taxonomy ) {
	global $wpdb;

	$today = gmdate( 'Y-m-d 00:00:00' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE tt.term_id = %d
			AND tt.taxonomy = %s
			AND p.post_type = 'data_machine_events'
			AND p.post_status = 'publish'
			AND pm.meta_key = '_datamachine_event_datetime'
			AND pm.meta_value >= %s",
			$term_id,
			$taxonomy,
			$today
		)
	);

	return (int) $count;
}
