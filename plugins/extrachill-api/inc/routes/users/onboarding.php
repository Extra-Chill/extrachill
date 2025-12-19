<?php
/**
 * Onboarding REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/users/onboarding - Get onboarding status
 * POST /wp-json/extrachill/v1/users/onboarding - Complete onboarding
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_onboarding_routes' );

function extrachill_api_register_onboarding_routes() {
	register_rest_route(
		'extrachill/v1',
		'/users/onboarding',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_onboarding_get_handler',
				'permission_callback' => 'extrachill_api_onboarding_permission_check',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_onboarding_post_handler',
				'permission_callback' => 'extrachill_api_onboarding_permission_check',
				'args'                => array(
					'username'             => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_user',
					),
					'user_is_artist'       => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'user_is_professional' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			),
		)
	);
}

/**
 * Permission check - must be logged in
 */
function extrachill_api_onboarding_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'You must be logged in.', 'extrachill-api' ),
			array( 'status' => 401 )
		);
	}

	return true;
}

/**
 * GET handler - retrieve onboarding status
 */
function extrachill_api_onboarding_get_handler( WP_REST_Request $request ) {
	$user_id = get_current_user_id();

	if ( ! function_exists( 'ec_get_onboarding_status' ) ) {
		return new WP_Error(
			'service_unavailable',
			__( 'Onboarding service not available.', 'extrachill-api' ),
			array( 'status' => 503 )
		);
	}

	$status = ec_get_onboarding_status( $user_id );

	return rest_ensure_response( $status );
}

/**
 * POST handler - complete onboarding
 */
function extrachill_api_onboarding_post_handler( WP_REST_Request $request ) {
	$user_id = get_current_user_id();

	if ( ! function_exists( 'ec_complete_onboarding' ) ) {
		return new WP_Error(
			'service_unavailable',
			__( 'Onboarding service not available.', 'extrachill-api' ),
			array( 'status' => 503 )
		);
	}

	$data = array(
		'username'             => $request->get_param( 'username' ),
		'user_is_artist'       => $request->get_param( 'user_is_artist' ),
		'user_is_professional' => $request->get_param( 'user_is_professional' ),
	);

	$result = ec_complete_onboarding( $user_id, $data );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			$result->get_error_code(),
			$result->get_error_message(),
			array( 'status' => 400 )
		);
	}

	return rest_ensure_response( $result );
}
