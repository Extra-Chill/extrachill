<?php
/**
 * REST route: GET /wp-json/extrachill/v1/analytics/summary
 *
 * Read route wrapping the extrachill/get-analytics-summary ability from
 * extrachill-analytics. Returns event counts grouped by type over an optional
 * rolling window, with optional event_type / blog_id filtering.
 *
 * This is the canonical home for the summary the Studio Network tab and the
 * analytics dashboard client call by name (closes #24 — the ability was not
 * reachable over REST). Thin wrapper only — all computation lives in the
 * ability.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_summary_route' );

/**
 * Register the analytics summary read route.
 */
function extrachill_api_register_analytics_summary_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/summary',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_summary_handler',
			'permission_callback' => 'extrachill_api_analytics_reports_permission_check',
			'args'                => array(
				'days'       => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 28,
				),
				'event_type' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'blog_id'    => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handle the analytics summary request.
 *
 * Wraps the extrachill/get-analytics-summary ability from extrachill-analytics.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_summary_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-analytics-summary' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-analytics plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'days'       => (int) $request->get_param( 'days' ),
			'event_type' => $request->get_param( 'event_type' ),
			'blog_id'    => (int) $request->get_param( 'blog_id' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
