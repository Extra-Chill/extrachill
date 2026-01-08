<?php
/**
 * REST routes: Analytics Events
 *
 * GET /wp-json/extrachill/v1/analytics/events - Query events with filters
 * GET /wp-json/extrachill/v1/analytics/events/summary - Aggregated stats
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_events_routes' );

/**
 * Register analytics events routes.
 */
function extrachill_api_register_analytics_events_routes() {
	// Query events.
	register_rest_route(
		'extrachill/v1',
		'/analytics/events',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_events_handler',
			'permission_callback' => function () {
				return current_user_can( 'manage_network_options' );
			},
			'args'                => array(
				'event_type' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'blog_id'    => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'date_from'  => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_to'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'search'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'      => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 100,
				),
				'offset'     => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 0,
				),
			),
		)
	);

	// Get summary stats.
	register_rest_route(
		'extrachill/v1',
		'/analytics/events/summary',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_events_summary_handler',
			'permission_callback' => function () {
				return current_user_can( 'manage_network_options' );
			},
			'args'                => array(
				'event_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'days'       => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 30,
				),
				'blog_id'    => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handle events query request.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_events_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_get_analytics_events' ) ) {
		return new WP_Error(
			'function_missing',
			'Analytics events function not available.',
			array( 'status' => 500 )
		);
	}

	$args = array(
		'event_type' => $request->get_param( 'event_type' ),
		'blog_id'    => $request->get_param( 'blog_id' ),
		'date_from'  => $request->get_param( 'date_from' ),
		'date_to'    => $request->get_param( 'date_to' ),
		'search'     => $request->get_param( 'search' ),
		'limit'      => $request->get_param( 'limit' ),
		'offset'     => $request->get_param( 'offset' ),
	);

	$events = extrachill_get_analytics_events( $args );

	// Get total count for pagination (uses same filters, excludes limit/offset).
	$count_args = array_diff_key( $args, array_flip( array( 'limit', 'offset' ) ) );
	$total      = function_exists( 'extrachill_count_analytics_events' ) ? extrachill_count_analytics_events( $count_args ) : count( $events );

	return rest_ensure_response(
		array(
			'events' => $events,
			'count'  => count( $events ),
			'total'  => $total,
		)
	);
}

/**
 * Handle events summary request.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_events_summary_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_get_analytics_event_stats' ) ) {
		return new WP_Error(
			'function_missing',
			'Analytics stats function not available.',
			array( 'status' => 500 )
		);
	}

	$event_type = $request->get_param( 'event_type' );
	$days       = $request->get_param( 'days' );
	$blog_id    = $request->get_param( 'blog_id' );

	$stats = extrachill_get_analytics_event_stats( $event_type, $days, $blog_id );

	return rest_ensure_response( $stats );
}
