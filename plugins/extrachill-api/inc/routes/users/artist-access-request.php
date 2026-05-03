<?php
/**
 * Artist Access Request REST API Endpoint
 *
 * POST /wp-json/extrachill/v1/users/me/artist-access  - Request artist platform access
 *
 * User-facing endpoint. Delegates to extrachill-users ability.
 * Admin-facing approve/reject lives in admin/artist-access.php.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_artist_access_request_route' );

function extrachill_api_register_user_artist_access_request_route() {
	register_rest_route(
		'extrachill/v1',
		'/users/me/artist-access',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_user_artist_access_request',
			'permission_callback' => 'extrachill_api_user_artist_access_permission',
			'args'                => array(
				'type' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'artist', 'professional' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

/**
 * Permission check — must be logged in.
 */
function extrachill_api_user_artist_access_permission( WP_REST_Request $request ) {
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
 * POST /users/me/artist-access
 */
function extrachill_api_user_artist_access_request( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/request-artist-access' );

	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id' => get_current_user_id(),
			'type'    => $request->get_param( 'type' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( $result );
}
