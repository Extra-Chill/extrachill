<?php
/**
 * REST route: auth register.
 *
 * POST /wp-json/extrachill/v1/auth/register
 *
 * Creates user with auto-generated username from email.
 * User must complete onboarding to set final username and artist/professional flags.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_register_route' );

/**
 * Registers the auth registration route.
 */
function extrachill_api_register_auth_register_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/register',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_register_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'email'                => array(
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => 'is_email',
					'sanitize_callback' => 'sanitize_email',
				),
				'password'             => array(
					'required' => true,
					'type'     => 'string',
				),
				'password_confirm'     => array(
					'required' => true,
					'type'     => 'string',
				),
				'turnstile_response'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
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
				'set_cookie'           => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'remember'             => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'registration_page'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'success_redirect_url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'invite_token'         => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'invite_artist_id'     => array(
					'required' => false,
					'type'     => 'integer',
				),
				'from_join'            => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
		)
	);
}

/**
 * Handles the auth registration request.
 *
 * @param WP_REST_Request $request Request data.
 * @return array|WP_Error
 */
function extrachill_api_auth_register_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_users_register_with_tokens' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'extrachill-users is required for user registration.',
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

	$device_id = trim( (string) $request->get_param( 'device_id' ) );
	if ( empty( $device_id ) || ! extrachill_users_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			'device_id must be a UUID v4.',
			array( 'status' => 400 )
		);
	}

	$payload = array(
		'email'                => (string) $request->get_param( 'email' ),
		'password'             => (string) $request->get_param( 'password' ),
		'password_confirm'     => (string) $request->get_param( 'password_confirm' ),
		'turnstile_response'   => (string) $request->get_param( 'turnstile_response' ),
		'device_id'            => $device_id,
		'device_name'          => (string) $request->get_param( 'device_name' ),
		'set_cookie'           => rest_sanitize_boolean( $request->get_param( 'set_cookie' ) ),
		'remember'             => rest_sanitize_boolean( $request->get_param( 'remember' ) ),
		'from_join'            => rest_sanitize_boolean( $request->get_param( 'from_join' ) ),
		'invite_token'         => sanitize_text_field( (string) $request->get_param( 'invite_token' ) ),
		'invite_artist_id'     => absint( $request->get_param( 'invite_artist_id' ) ),
		'registration_page'    => (string) $request->get_param( 'registration_page' ),
		'success_redirect_url' => (string) $request->get_param( 'success_redirect_url' ),
	);

	$result = extrachill_users_register_with_tokens( $payload );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
