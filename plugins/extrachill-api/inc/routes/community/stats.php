<?php
/**
 * Community Stats Endpoint
 *
 * Thin REST wrapper around the extrachill/community-get-stats ability.
 * Route affinity middleware ensures this runs on the community site, where
 * extrachill-community (a per-site plugin) registers the ability.
 *
 * This is the canonical platform door for the cross-site NetworkStats
 * loopback (blog 1 → community blog): the loopback hits
 * /extrachill/v1/community/stats rather than the generic core Abilities
 * /run endpoint. Mirrors the sibling /community/taxonomy-counts route.
 *
 * @endpoint GET /extrachill/v1/community/stats
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_stats_route' );

/**
 * Register the community stats endpoint.
 */
function extrachill_api_register_community_stats_route() {
	register_rest_route(
		'extrachill/v1',
		'/community/stats',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_community_stats_handler',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Handle community stats request.
 *
 * Thin wrapper: extracts no inputs (the ability takes none) and delegates
 * to the extrachill/community-get-stats ability. All logic lives in the
 * ability. Route affinity middleware ensures this runs on the community
 * site where the ability is registered.
 *
 * @return WP_REST_Response|WP_Error Stats payload or error.
 */
function extrachill_api_community_stats_handler() {
	$ability = wp_get_ability( 'extrachill/community-get-stats' );

	if ( ! $ability ) {
		return new WP_Error(
			'ability_unavailable',
			'The extrachill/community-get-stats ability is not available.',
			array( 'status' => 503 )
		);
	}

	$result = $ability->execute( array() );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
