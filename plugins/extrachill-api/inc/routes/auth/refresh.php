<?php
/**
 * Auth refresh endpoint.
 *
 * POST /wp-json/extrachill/v1/auth/refresh
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_refresh_route' );

function extrachill_api_register_auth_refresh_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/refresh',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_refresh_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'refresh_token' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'device_id'      => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'remember'       => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'set_cookie'     => array(
					'required' => false,
					'type'     => 'boolean',
				),
			),
		)
	);
}

function extrachill_api_auth_refresh_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_users_refresh_tokens' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'extrachill-users is required for token authentication.',
			array( 'status' => 500 )
		);
	}

	if ( ! function_exists( 'extrachill_users_is_uuid_v4' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'extrachill-users token helpers are not available.',
			array( 'status' => 500 )
		);
	}

	$refresh_token = trim( (string) $request->get_param( 'refresh_token' ) );
	$device_id     = trim( (string) $request->get_param( 'device_id' ) );

	if ( empty( $refresh_token ) || empty( $device_id ) ) {
		return new WP_Error(
			'missing_refresh_credentials',
			'refresh_token and device_id are required.',
			array( 'status' => 400 )
		);
	}

	if ( ! extrachill_users_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			'device_id must be a UUID v4.',
			array( 'status' => 400 )
		);
	}

	$options = array(
		'remember'   => rest_sanitize_boolean( $request->get_param( 'remember' ) ),
		'set_cookie' => rest_sanitize_boolean( $request->get_param( 'set_cookie' ) ),
	);

	$result = extrachill_users_refresh_tokens( $refresh_token, $device_id, $options );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
