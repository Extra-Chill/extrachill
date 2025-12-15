<?php
/**
 * REST route: GET /wp-json/extrachill/v1/stream/status
 *
 * Centralized stream status route registration for the platform.
 * The business logic lives in the extrachill-stream plugin.
 *
 * @package ExtraChillAPI
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_stream_status_route' );

function extrachill_api_register_stream_status_route() {
	register_rest_route(
		'extrachill/v1',
		'/stream/status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_handle_stream_status',
			'permission_callback' => 'extrachill_api_stream_permissions_check',
		)
	);
}

function extrachill_api_stream_permissions_check() {
	if ( function_exists( 'ec_stream_rest_permissions_check' ) ) {
		return ec_stream_rest_permissions_check();
	}

	return new WP_Error(
		'stream_unavailable',
		__( 'Stream status unavailable.', 'extrachill-api' ),
		array( 'status' => 503 )
	);
}

function extrachill_api_handle_stream_status( WP_REST_Request $request ) {
	if ( function_exists( 'ec_stream_rest_get_status' ) ) {
		return ec_stream_rest_get_status( $request );
	}

	return new WP_Error(
		'stream_unavailable',
		__( 'Stream status unavailable.', 'extrachill-api' ),
		array( 'status' => 503 )
	);
}
