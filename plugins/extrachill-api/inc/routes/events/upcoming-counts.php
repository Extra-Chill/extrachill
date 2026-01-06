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
					'default'           => 8,
					'minimum'           => 1,
					'maximum'           => 50,
					'description'       => 'Max terms to return for bulk queries (default: 8, max: 50)',
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
	$limit    = $request->get_param( 'limit' ) ?: 8;

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
 * Get bulk upcoming counts for top terms
 *
 * @param string $taxonomy        Taxonomy slug.
 * @param int    $events_blog_id  Events site blog ID.
 * @param int    $limit           Max terms to return.
 * @return array Array of term data sorted by count descending.
 */
function extrachill_api_get_bulk_upcoming_counts( $taxonomy, $events_blog_id, $limit ) {
	$results = array();

	switch_to_blog( $events_blog_id );
	try {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'childless'  => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		foreach ( $terms as $term ) {
			$count = extrachill_api_count_upcoming_events_for_term( $term->term_id, $taxonomy );
			if ( $count < 1 ) {
				continue;
			}

			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$results[] = array(
				'slug'  => $term->slug,
				'name'  => $term->name,
				'count' => $count,
				'url'   => $url,
			);
		}
	} finally {
		restore_current_blog();
	}

	// Sort by count descending
	usort(
		$results,
		function ( $a, $b ) {
			return $b['count'] - $a['count'];
		}
	);

	return array_slice( $results, 0, $limit );
}

/**
 * Count upcoming events for a term
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @return int Count of upcoming events.
 */
function extrachill_api_count_upcoming_events_for_term( $term_id, $taxonomy ) {
	$today = gmdate( 'Y-m-d 00:00:00' );

	$query = new WP_Query(
		array(
			'post_type'      => 'datamachine_events',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
			'meta_query'     => array(
				array(
					'key'     => '_datamachine_event_datetime',
					'value'   => $today,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			),
		)
	);

	return $query->found_posts;
}
