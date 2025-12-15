<?php
/**
 * REST route: auth login.
 *
 * POST /wp-json/extrachill/v1/auth/login
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_login_route' );

/**
 * Registers the auth login route.
 */
function extrachill_api_register_auth_login_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/login',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_login_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'identifier'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'password'    => array(
					'required' => true,
					'type'     => 'string',
				),
				'device_id'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'device_name' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'remember'    => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'set_cookie'  => array(
					'required' => false,
					'type'     => 'boolean',
				),
			),
		)
	);
}

/**
 * Handles the auth login request.
 *
 * @param WP_REST_Request $request Request data.
 * @return array|WP_Error
 */
function extrachill_api_auth_login_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_users_login_with_tokens' ) ) {
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

	$identifier = trim( (string) $request->get_param( 'identifier' ) );
	$password   = (string) $request->get_param( 'password' );
	$device_id  = trim( (string) $request->get_param( 'device_id' ) );

	if ( empty( $identifier ) || empty( $password ) ) {
		return new WP_Error(
			'missing_credentials',
			'Identifier and password are required.',
			array( 'status' => 400 )
		);
	}

	if ( empty( $device_id ) || ! extrachill_users_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			'device_id must be a UUID v4.',
			array( 'status' => 400 )
		);
	}

	$options = array(
		'device_name' => (string) $request->get_param( 'device_name' ),
		'remember'    => rest_sanitize_boolean( $request->get_param( 'remember' ) ),
		'set_cookie'  => rest_sanitize_boolean( $request->get_param( 'set_cookie' ) ),
	);

	$result = extrachill_users_login_with_tokens( $identifier, $password, $device_id, $options );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
