<?php
/**
 * REST route: Apple OAuth authentication.
 *
 * POST /wp-json/extrachill/v1/auth/apple
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_apple_route' );

/**
 * Registers the Apple auth route.
 */
function extrachill_api_register_auth_apple_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/apple',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_apple_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'identity_token'      => array(
					'required' => true,
					'type'     => 'string',
				),
				'nonce'               => array(
					'required' => true,
					'type'     => 'string',
				),
				'device_id'           => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'device_name'         => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'given_name'          => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'family_name'         => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'registration_source' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'registration_method' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'registration_page'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'newsletter_consent'  => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
		)
	);
}

/**
 * Handles the Apple auth request.
 *
 * @param WP_REST_Request $request Request data.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_auth_apple_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_apple_login_with_tokens' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'Apple OAuth service is not available.',
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

	if ( function_exists( 'ec_is_apple_oauth_configured' ) && ! ec_is_apple_oauth_configured() ) {
		return new WP_Error(
			'apple_not_configured',
			'Apple OAuth is not configured.',
			array( 'status' => 503 )
		);
	}

	$identity_token = (string) $request->get_param( 'identity_token' );
	$raw_nonce      = (string) $request->get_param( 'nonce' );
	$device_id      = trim( (string) $request->get_param( 'device_id' ) );

	if ( empty( $identity_token ) ) {
		return new WP_Error( 'missing_token', 'Apple identity token is required.', array( 'status' => 400 ) );
	}

	if ( empty( $raw_nonce ) ) {
		return new WP_Error( 'missing_nonce', 'Apple raw nonce is required.', array( 'status' => 400 ) );
	}

	if ( empty( $device_id ) || ! extrachill_users_is_uuid_v4( $device_id ) ) {
		return new WP_Error( 'invalid_device_id', 'device_id must be a UUID v4.', array( 'status' => 400 ) );
	}

	$options = extrachill_api_auth_apple_options( $request );
	$result  = ec_apple_login_with_tokens( $identity_token, $device_id, $options );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Map the Apple auth request to the Users-owned service options.
 *
 * @param WP_REST_Request $request Apple auth request.
 * @return array Apple service options.
 */
function extrachill_api_auth_apple_options( WP_REST_Request $request ): array {
	return array(
		'raw_nonce'           => (string) $request->get_param( 'nonce' ),
		'device_name'         => (string) $request->get_param( 'device_name' ),
		'given_name'          => (string) $request->get_param( 'given_name' ),
		'family_name'         => (string) $request->get_param( 'family_name' ),
		'registration_source' => (string) $request->get_param( 'registration_source' ),
		'registration_method' => (string) $request->get_param( 'registration_method' ),
		'registration_page'   => (string) $request->get_param( 'registration_page' ),
		'newsletter_consent'  => rest_sanitize_boolean( (string) $request->get_param( 'newsletter_consent' ) ),
	);
}
