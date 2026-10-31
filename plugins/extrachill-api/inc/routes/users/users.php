<?php
/**
 * User REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/users/{id} - Retrieve user profile data
 *
 * Profile data is supplied by the public Extra Chill Users profile ability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_routes' );

function extrachill_api_register_user_routes() {
	register_rest_route(
		'extrachill/v1',
		'/users/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_user_get_handler',
			'permission_callback' => 'extrachill_api_user_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Permission check for user endpoint
 */
function extrachill_api_user_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
		);
	}

	$user_id = $request->get_param( 'id' );
	$user    = get_userdata( $user_id );

	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	return true;
}

/**
 * GET handler - retrieve user profile data
 */
function extrachill_api_user_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-user-profile' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'user_id' => $request->get_param( 'id' ) ) );

	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}
