<?php
/**
 * Blog Taxonomy Counts Endpoint
 *
 * Thin REST wrapper around the extrachill/taxonomy-post-counts ability.
 * Route affinity middleware ensures this runs on the blog (main) site.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_blog_taxonomy_counts_route' );

/**
 * Register the blog taxonomy counts endpoint
 */
function extrachill_api_register_blog_taxonomy_counts_route() {
	register_rest_route(
		'extrachill/v1',
		'/blog/taxonomy-counts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_blog_taxonomy_counts_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'taxonomy' => array(
					'required'          => true,
					'type'              => 'string',
					'description'       => 'Taxonomy to query (e.g., artist, venue, location, festival)',
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
 * Handle blog taxonomy counts request.
 *
 * Checks transient cache, falls back to ability. Route affinity middleware
 * ensures this runs on the main blog where taxonomies are registered.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_blog_taxonomy_counts_handler( WP_REST_Request $request ) {
	$taxonomy = $request->get_param( 'taxonomy' );
	$slug     = $request->get_param( 'slug' );
	$limit    = $request->get_param( 'limit' ) ?: 8;

	// Single term query.
	if ( ! empty( $slug ) ) {
		$result = extrachill_api_get_single_blog_term_count( $slug, $taxonomy );
		return rest_ensure_response( $result );
	}

	// Bulk query — check transient, fall back to ability.
	$cache_key = 'ec_blog_counts_' . $taxonomy;
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
			'taxonomy' => $taxonomy,
			'site'     => 'main',
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
 * Get count for a single term on the blog site.
 *
 * Route affinity middleware ensures this runs on the main blog.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @return array|null Term data or null.
 */
function extrachill_api_get_single_blog_term_count( $slug, $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$post_types = get_taxonomy( $taxonomy )->object_type;

	$query = new WP_Query(
		array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		)
	);

	if ( $query->found_posts < 1 ) {
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
		'count'   => $query->found_posts,
		'url'     => $url,
	);
}
