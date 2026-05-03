<?php
/**
 * Community Taxonomy Counts Endpoint
 *
 * Thin REST wrapper around the extrachill/taxonomy-post-counts ability.
 * Route affinity middleware ensures this runs on the community site.
 *
 * Unlike other sites, single-term queries return forum URLs because
 * community forums are location hubs (not taxonomy archives).
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_taxonomy_counts_route' );

/**
 * Register the community taxonomy counts endpoint
 */
function extrachill_api_register_community_taxonomy_counts_route() {
	register_rest_route(
		'extrachill/v1',
		'/community/taxonomy-counts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_community_taxonomy_counts_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'taxonomy' => array(
					'required'          => true,
					'type'              => 'string',
					'description'       => 'Taxonomy to query (e.g., location)',
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
 * Handle community taxonomy counts request.
 *
 * Checks transient cache, falls back to ability. Route affinity middleware
 * ensures this runs on the community site where forum taxonomies exist.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_community_taxonomy_counts_handler( WP_REST_Request $request ) {
	$taxonomy = $request->get_param( 'taxonomy' );
	$slug     = $request->get_param( 'slug' );
	$limit    = $request->get_param( 'limit' ) ?: 8;

	// Single term query — community-specific forum/topic lookup.
	if ( ! empty( $slug ) ) {
		$result = extrachill_api_get_single_community_term_count( $slug, $taxonomy );
		return rest_ensure_response( $result );
	}

	// Bulk query — check transient, fall back to ability.
	$cache_key = 'ec_community_counts_' . $taxonomy;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return rest_ensure_response( array_slice( $cached, 0, $limit ) );
	}

	// Cold cache — call the ability.
	$ability = wp_get_ability( 'extrachill/taxonomy-post-counts' );

	if ( ! $ability ) {
		return rest_ensure_response( array() );
	}

	$result = $ability->execute(
		array(
			'taxonomy'  => $taxonomy,
			'site'      => 'community',
			'post_type' => 'topic',
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$terms = $result['terms'] ?? array();

	set_transient( $cache_key, $terms, 6 * HOUR_IN_SECONDS );

	return rest_ensure_response( array_slice( $terms, 0, $limit ) );
}

/**
 * Get count for a single term on the community site.
 *
 * Community is special: forums are location hubs, so we find the forum
 * tagged with the taxonomy term, count its child topics, and return
 * the forum permalink instead of a taxonomy archive URL.
 *
 * Route affinity middleware ensures this runs on the community site.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @return array|null Term data or null.
 */
function extrachill_api_get_single_community_term_count( $slug, $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$forums = get_posts(
		array(
			'post_type'      => 'forum',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		)
	);

	if ( empty( $forums ) ) {
		return null;
	}

	$forum = $forums[0];

	$topic_query = new WP_Query(
		array(
			'post_type'      => 'topic',
			'post_status'    => 'publish',
			'post_parent'    => $forum->ID,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);

	if ( $topic_query->found_posts < 1 ) {
		return null;
	}

	return array(
		'slug'  => $term->slug,
		'name'  => $term->name,
		'count' => (int) $topic_query->found_posts,
		'url'   => get_permalink( $forum ),
	);
}
