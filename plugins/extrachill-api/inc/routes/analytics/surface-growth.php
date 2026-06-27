<?php
/**
 * REST route: GET /wp-json/extrachill/v1/analytics/surface-growth
 *
 * Read route wrapping the extrachill/get-surface-growth ability from
 * extrachill-analytics. Returns a normalized, cross-surface growth-rate read
 * (supply: inventory growth per week; demand: organic-sessions slope) for each
 * live Extra Chill surface, plus a ranked fastest-growing surface.
 *
 * Thin wrapper only — all computation lives in the ability.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_surface_growth_route' );

/**
 * Register the analytics surface-growth read route.
 */
function extrachill_api_register_analytics_surface_growth_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/surface-growth',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_surface_growth_handler',
			'permission_callback' => 'extrachill_api_analytics_reports_permission_check',
			'args'                => array(
				'weeks' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 4,
				),
			),
		)
	);
}

/**
 * Handle the surface-growth request.
 *
 * Wraps the extrachill/get-surface-growth ability from extrachill-analytics.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_surface_growth_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-surface-growth' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-analytics plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'weeks' => (int) $request->get_param( 'weeks' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
