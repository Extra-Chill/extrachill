<?php
/**
 * REST route: GET /wp-json/extrachill/v1/analytics/conversion-map
 *
 * Read route wrapping the extrachill/get-conversion-map ability from
 * extrachill-analytics. Returns the first-party, bot-filtered cross-surface
 * conversion map: for visitors whose first session starts on an editorial
 * article (blog 1), the share that reach a platform surface (events/community/
 * artist) same-session or on a return visit, ranked per entry article and per
 * entry category.
 *
 * Thin wrapper only — all computation lives in the ability.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_conversion_map_route' );

/**
 * Register the analytics conversion-map read route.
 */
function extrachill_api_register_analytics_conversion_map_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/conversion-map',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_conversion_map_handler',
			'permission_callback' => 'extrachill_api_analytics_reports_permission_check',
			'args'                => array(
				'days'               => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 28,
				),
				'session_gap_mins'   => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 30,
				),
				'top_articles'       => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 25,
				),
				'min_entry_sessions' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 1,
				),
			),
		)
	);
}

/**
 * Handle the conversion-map request.
 *
 * Wraps the extrachill/get-conversion-map ability from extrachill-analytics.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_conversion_map_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-conversion-map' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-analytics plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'days'               => (int) $request->get_param( 'days' ),
			'session_gap_mins'   => (int) $request->get_param( 'session_gap_mins' ),
			'top_articles'       => (int) $request->get_param( 'top_articles' ),
			'min_entry_sessions' => (int) $request->get_param( 'min_entry_sessions' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
