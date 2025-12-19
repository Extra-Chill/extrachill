<?php
/**
 * REST route: Google OAuth authentication.
 *
 * POST /wp-json/extrachill/v1/auth/google
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_google_route' );

/**
 * Registers the Google auth route.
 */
function extrachill_api_register_auth_google_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/google',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_google_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'id_token'             => array(
					'required' => true,
					'type'     => 'string',
				),
				'device_id'            => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'device_name'          => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'from_join'            => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
				'remember'             => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => true,
				),
				'set_cookie'           => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
				'success_redirect_url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}

/**
 * Handles the Google auth request.
 *
 * @param WP_REST_Request $request Request data.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_auth_google_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_google_login_with_tokens' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'Google OAuth service is not available.',
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

	$id_token  = (string) $request->get_param( 'id_token' );
	$device_id = trim( (string) $request->get_param( 'device_id' ) );

	if ( empty( $id_token ) ) {
		return new WP_Error(
			'missing_token',
			'Google ID token is required.',
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
		'device_name'          => (string) $request->get_param( 'device_name' ),
		'remember'             => rest_sanitize_boolean( $request->get_param( 'remember' ) ),
		'set_cookie'           => rest_sanitize_boolean( $request->get_param( 'set_cookie' ) ),
		'from_join'            => rest_sanitize_boolean( $request->get_param( 'from_join' ) ),
		'success_redirect_url' => (string) $request->get_param( 'success_redirect_url' ),
	);

	$result = ec_google_login_with_tokens( $id_token, $device_id, $options );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
