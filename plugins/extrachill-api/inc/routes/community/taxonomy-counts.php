<?php
/**
 * Community Taxonomy Counts Endpoint
 *
 * Returns forum data for taxonomy terms. Used by cross-site linking.
 * Unlike other sites, community returns forum URLs (forums are location hubs).
 *
 * @package ExtraChillAPI
 * @since 1.0.0
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
					'required'          => true,
					'type'              => 'string',
					'description'       => 'Term slug to look up',
					'sanitize_callback' => 'sanitize_title',
				),
			),
		)
	);
}

/**
 * Handle community taxonomy counts request
 *
 * Returns forum URL and topic count for cross-site linking.
 * Forums are location hubs, so we return the forum permalink
 * instead of a taxonomy archive URL.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|null Response data or null if not found.
 */
function extrachill_api_community_taxonomy_counts_handler( WP_REST_Request $request ) {
	$taxonomy = $request->get_param( 'taxonomy' );
	$slug     = $request->get_param( 'slug' );

	$community_blog_id = function_exists( 'ec_get_blog_id' )
		? ec_get_blog_id( 'community' )
		: null;

	if ( ! $community_blog_id ) {
		return rest_ensure_response( null );
	}

	switch_to_blog( $community_blog_id );
	try {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return rest_ensure_response( null );
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return rest_ensure_response( null );
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
			return rest_ensure_response( null );
		}

		$forum       = $forums[0];
		$topic_count = function_exists( 'bbp_get_forum_topic_count' )
			? bbp_get_forum_topic_count( $forum->ID )
			: 0;

		return rest_ensure_response(
			array(
				'slug'  => $term->slug,
				'name'  => $term->name,
				'count' => (int) $topic_count,
				'url'   => get_permalink( $forum ),
			)
		);
	} finally {
		restore_current_blog();
	}
}
