<?php
/**
 * User Subscriptions REST API Endpoints
 *
 * GET  /wp-json/extrachill/v1/users/me/subscriptions  - Get subscriptions
 * POST /wp-json/extrachill/v1/users/me/subscriptions  - Update subscriptions
 *
 * Delegates to extrachill-users abilities.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_subscriptions_routes' );

function extrachill_api_register_user_subscriptions_routes() {
	register_rest_route(
		'extrachill/v1',
		'/users/me/subscriptions',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_user_subscriptions_get',
				'permission_callback' => 'extrachill_api_user_subscriptions_permission',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_user_subscriptions_update',
				'permission_callback' => 'extrachill_api_user_subscriptions_permission',
				'args'                => array(
					'consented_artists' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
				),
			),
		)
	);
}

/**
 * Permission check — must be logged in.
 */
function extrachill_api_user_subscriptions_permission( WP_REST_Request $request ) {
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
 * GET /users/me/subscriptions
 */
function extrachill_api_user_subscriptions_get( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-subscriptions' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'user_id' => get_current_user_id() ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * POST /users/me/subscriptions
 */
function extrachill_api_user_subscriptions_update( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/update-subscriptions' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id'           => get_current_user_id(),
			'consented_artists' => $request->get_param( 'consented_artists' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( $result );
}
