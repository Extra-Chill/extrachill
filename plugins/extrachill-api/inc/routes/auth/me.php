<?php
/**
 * REST route: auth me.
 *
 * GET /wp-json/extrachill/v1/auth/me
 *
 * Returns authenticated user data. Requires bearer token.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_me_route' );

/**
 * Registers the auth me route.
 */
function extrachill_api_register_auth_me_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/me',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_auth_me_handler',
			'permission_callback' => 'is_user_logged_in',
		)
	);
}

/**
 * Handles the auth me request.
 *
 * @param WP_REST_Request $request Request data.
 * @return array|WP_Error
 */
function extrachill_api_auth_me_handler( WP_REST_Request $request ) {
	$user = wp_get_current_user();

	if ( ! $user || ! $user->exists() ) {
		return new WP_Error(
			'not_authenticated',
			'User not authenticated.',
			array( 'status' => 401 )
		);
	}

	$onboarding_completed = function_exists( 'ec_is_onboarding_complete' )
		? ec_is_onboarding_complete( $user->ID )
		: true;

	$response = array(
		'id'                   => (int) $user->ID,
		'username'             => $user->user_login,
		'email'                => $user->user_email,
		'display_name'         => $user->display_name,
		'avatar_url'           => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
		'profile_url'          => function_exists( 'ec_get_user_profile_url' )
			? ec_get_user_profile_url( $user->ID, $user->user_email )
			: '',
		'registered'           => $user->user_registered,
		'onboarding_completed' => $onboarding_completed,
	);

	$response = apply_filters( 'extrachill_auth_me_response', $response, $user );

	return rest_ensure_response( $response );
}
