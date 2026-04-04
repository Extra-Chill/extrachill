<?php
/**
 * Upcoming Event Counts Endpoint
 *
 * Returns counts of upcoming events (date >= today) for taxonomy terms.
 * Route affinity middleware ensures this runs on the events site.
 *
 * The heavy SQL query result is cached in a transient (6hr TTL) and
 * pre-warmed by the cron warmer in extrachill-multisite.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_upcoming_counts_route' );

/**
 * Register the upcoming counts endpoint.
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
 * Handle upcoming counts request.
 *
 * Checks transient cache, runs SQL on cold cache.
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_upcoming_counts_handler( WP_REST_Request $request ) {
	$taxonomy = $request->get_param( 'taxonomy' );
	$slug     = $request->get_param( 'slug' );
	$limit    = (int) $request->get_param( 'limit' );

	// Single term query.
	if ( ! empty( $slug ) ) {
		return rest_ensure_response(
			extrachill_api_single_upcoming_count( $slug, $taxonomy )
		);
	}

	// Bulk query — check transient, run SQL on cold cache.
	$cache_key = 'ec_upcoming_counts_' . $taxonomy;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		$results = $cached;
		if ( $limit > 0 ) {
			$results = array_slice( $results, 0, $limit );
		}
		return rest_ensure_response( $results );
	}

	// Cold cache — run the query.
	$terms = extrachill_api_query_upcoming_counts( $taxonomy );

	set_transient( $cache_key, $terms, 6 * HOUR_IN_SECONDS );

	if ( $limit > 0 ) {
		$terms = array_slice( $terms, 0, $limit );
	}

	return rest_ensure_response( $terms );
}

/**
 * Query upcoming event counts grouped by taxonomy term.
 *
 * Single SQL query with GROUP BY. Route affinity middleware ensures events blog context.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return array Array of term data sorted by count descending.
 */
function extrachill_api_query_upcoming_counts( $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}

	global $wpdb;

	$today        = gmdate( 'Y-m-d 00:00:00' );
	$exclude_roots = is_taxonomy_hierarchical( $taxonomy );
	$parent_clause = $exclude_roots ? 'AND tt.parent != 0' : '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS event_count
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			INNER JOIN {$wpdb->prefix}datamachine_event_dates ed ON p.ID = ed.post_id
			WHERE tt.taxonomy = %s
			AND p.post_type = 'data_machine_events'
			AND p.post_status = 'publish'
			AND ed.start_datetime >= %s
			{$parent_clause}
			GROUP BY t.term_id
			ORDER BY event_count DESC",
			$taxonomy,
			$today
		)
	);

	if ( empty( $rows ) ) {
		return array();
	}

	$terms = array();
	foreach ( $rows as $row ) {
		$url = get_term_link( (int) $row->term_id, $taxonomy );
		if ( is_wp_error( $url ) ) {
			continue;
		}

		$terms[] = array(
			'term_id' => (int) $row->term_id,
			'name'    => $row->name,
			'slug'    => $row->slug,
			'count'   => (int) $row->event_count,
			'url'     => $url,
		);
	}

	return $terms;
}

/**
 * Get upcoming event count for a single term.
 *
 * Route affinity middleware ensures events blog context.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @return array|null Term data or null.
 */
function extrachill_api_single_upcoming_count( $slug, $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	global $wpdb;
	$today = gmdate( 'Y-m-d 00:00:00' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->prefix}datamachine_event_dates ed ON p.ID = ed.post_id
			WHERE tt.term_id = %d
			AND tt.taxonomy = %s
			AND p.post_type = 'data_machine_events'
			AND p.post_status = 'publish'
			AND ed.start_datetime >= %s",
			$term->term_id,
			$taxonomy,
			$today
		)
	);

	if ( $count < 1 ) {
		return null;
	}

	$url = get_term_link( $term );
	if ( is_wp_error( $url ) ) {
		return null;
	}

	return array(
		'term_id' => $term->term_id,
		'slug'    => $term->slug,
		'name'    => $term->name,
		'count'   => $count,
		'url'     => $url,
	);
}
