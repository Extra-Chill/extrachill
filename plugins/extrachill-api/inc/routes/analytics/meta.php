<?php
/**
 * REST routes: Analytics Meta
 *
 * GET /wp-json/extrachill/v1/analytics/meta - Get filter options (event types, blogs)
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_meta_routes' );

/**
 * Register analytics meta routes.
 */
function extrachill_api_register_analytics_meta_routes() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/meta',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_meta_handler',
			'permission_callback' => function () {
				return current_user_can( 'manage_network_options' );
			},
		)
	);
}

/**
 * Handle analytics meta request.
 *
 * Wraps the extrachill/get-analytics-meta ability from extrachill-analytics.
 *
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_meta_handler() {
	$ability = wp_get_ability( 'extrachill/get-analytics-meta' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-analytics plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
