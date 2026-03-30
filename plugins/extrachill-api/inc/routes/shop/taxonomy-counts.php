<?php
/**
 * Shop Taxonomy Counts Endpoint
 *
 * Thin REST wrapper around the extrachill/taxonomy-post-counts ability.
 * Routes to the shop site via switch_to_blog.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_taxonomy_counts_route' );

/**
 * Register the shop taxonomy counts endpoint
 */
function extrachill_api_register_shop_taxonomy_counts_route() {
	register_rest_route(
		'extrachill/v1',
		'/shop/taxonomy-counts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_shop_taxonomy_counts_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'taxonomy' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'artist' ),
					'description'       => 'Taxonomy to query (currently only artist supported)',
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
 * Handle shop taxonomy counts request.
 *
 * Routes to shop site, checks transient, falls back to ability.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_shop_taxonomy_counts_handler( WP_REST_Request $request ) {
	$taxonomy = $request->get_param( 'taxonomy' );
	$slug     = $request->get_param( 'slug' );
	$limit    = $request->get_param( 'limit' ) ?: 8;

	$shop_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;
	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'shop_site_unavailable',
			__( 'Shop site is not configured.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $shop_blog_id );
	try {
		// Single term query.
		if ( ! empty( $slug ) ) {
			$result = extrachill_api_get_single_term_product_count( $slug, $taxonomy );
			return rest_ensure_response( $result );
		}

		// Bulk query — check transient, fall back to ability.
		$cache_key = 'ec_shop_counts_' . $taxonomy;
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
				'site'      => 'shop',
				'post_type' => 'product',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$terms = $result['terms'] ?? array();

		set_transient( $cache_key, $terms, 6 * HOUR_IN_SECONDS );

		return rest_ensure_response( array_slice( $terms, 0, $limit ) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Get product count for a single term.
 *
 * Must be called while switched to shop blog context.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @return array|null Term data or null.
 */
function extrachill_api_get_single_term_product_count( $slug, $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$query = new WP_Query(
		array(
			'post_type'      => 'product',
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
