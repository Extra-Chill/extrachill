<?php
/**
 * REST route: auth logout.
 *
 * POST /wp-json/extrachill/v1/auth/logout
 *
 * Revokes the refresh token for the specified device.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_logout_route' );

/**
 * Registers the auth logout route.
 */
function extrachill_api_register_auth_logout_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/logout',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_logout_handler',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'device_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

/**
 * Handles the auth logout request.
 *
 * @param WP_REST_Request $request Request data.
 * @return array|WP_Error
 */
function extrachill_api_auth_logout_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_users_revoke_refresh_token' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'extrachill-users is required for logout.',
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

	$user_id = get_current_user_id();
	$revoked = extrachill_users_revoke_refresh_token( $user_id, $device_id );

	return rest_ensure_response(
		array(
			'success' => $revoked,
			'message' => $revoked ? 'Logged out successfully.' : 'No active session found for this device.',
		)
	);
}
