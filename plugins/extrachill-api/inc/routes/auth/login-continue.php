<?php
/**
 * REST route: continue a challenged login.
 *
 * POST /wp-json/extrachill/v1/auth/login/continue
 *
 * Finishes a login that /auth/login, /auth/google, or /auth/apple paused with
 * `challenge_required` (for example a 2FA code). Thin transport over
 * extrachill_users_continue_login_with_tokens(); verification, attempt limits,
 * and session minting live in extrachill-users and wp-native-auth.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_login_continue_route' );

/**
 * Registers the login continuation route.
 */
function extrachill_api_register_auth_login_continue_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/login/continue',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_login_continue_handler',
			// The continuation token is the credential: it is single-use, bound
			// to the device and client, and expires within minutes.
			'permission_callback' => '__return_true',
			'args'                => array(
				'continuation_token' => array(
					'required' => true,
					'type'     => 'string',
				),
				'device_id'          => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'challenge_response' => array(
					'required' => true,
					'type'     => 'object',
				),
			),
		)
	);
}

/**
 * Handles the login continuation request.
 *
 * @param WP_REST_Request $request Request data.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_auth_login_continue_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_users_continue_login_with_tokens' ) || ! function_exists( 'extrachill_users_is_uuid_v4' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'extrachill-users is required for token authentication.',
			array( 'status' => 500 )
		);
	}

	$continuation_token = trim( (string) $request->get_param( 'continuation_token' ) );
	$device_id          = trim( (string) $request->get_param( 'device_id' ) );
	$challenge_response = $request->get_param( 'challenge_response' );

	if ( '' === $continuation_token ) {
		return new WP_Error(
			'invalid_continuation',
			'continuation_token is required.',
			array( 'status' => 400 )
		);
	}

	if ( '' === $device_id || ! extrachill_users_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			'device_id must be a UUID v4.',
			array( 'status' => 400 )
		);
	}

	$result = extrachill_users_continue_login_with_tokens(
		$continuation_token,
		$device_id,
		is_array( $challenge_response ) ? $challenge_response : array()
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
