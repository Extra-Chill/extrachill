<?php
/**
 * REST route: GET /wp-json/extrachill/v1/analytics/retention
 *
 * Read route wrapping the extrachill/get-retention-stats ability from
 * extrachill-analytics. Returns deterministic, bot-filtered visitor-retention
 * metrics (return rate, weekly cohort retention, cross-site return, session
 * depth) computed from first-party pageview events.
 *
 * Thin wrapper only — all computation lives in the ability.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_retention_route' );

/**
 * Register the analytics retention read route.
 */
function extrachill_api_register_analytics_retention_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/retention',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_retention_handler',
			'permission_callback' => 'extrachill_api_analytics_reports_permission_check',
			'args'                => array(
				'days'         => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 28,
				),
				'blog_id'      => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'cohort_weeks' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 8,
				),
				'start_date'   => array(
					'required' => false,
					'type'     => 'string',
				),
				'end_date'     => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		)
	);
}

/**
 * Handle the retention stats request.
 *
 * Wraps the extrachill/get-retention-stats ability from extrachill-analytics.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_retention_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-retention-stats' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-analytics plugin is required.', array( 'status' => 500 ) );
	}

	$input = array(
		'days'         => (int) $request->get_param( 'days' ),
		'blog_id'      => (int) $request->get_param( 'blog_id' ),
		'cohort_weeks' => (int) $request->get_param( 'cohort_weeks' ),
	);

	foreach ( array( 'start_date', 'end_date' ) as $date_param ) {
		if ( null !== $request->get_param( $date_param ) ) {
			$input[ $date_param ] = $request->get_param( $date_param );
		}
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
