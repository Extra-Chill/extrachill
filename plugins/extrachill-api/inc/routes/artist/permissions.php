<?php
/**
 * Artist Permissions REST Endpoint
 *
 * Registers the /extrachill/v1/artist/permissions endpoint to check if the current user
 * has permission to edit a specific artist profile. Used by extrachill.link for the
 * client-side edit button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_permissions_route' );

function extrachill_api_register_artist_permissions_route() {
	register_rest_route(
		'extrachill/v1',
		'/artist/permissions',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ec_api_check_artist_permissions',
			'permission_callback' => '__return_true',
			'args'                => array(
				'artist_id' => array(
					'required'          => true,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
				),
			),
		)
	);
}

/**
 * Check artist permissions
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response The response object.
 */
function ec_api_check_artist_permissions( WP_REST_Request $request ) {
	// Handle CORS for extrachill.link
	$origin = get_http_origin();
	if ( in_array( $origin, array( 'https://extrachill.link', 'https://www.extrachill.link' ), true ) ) {
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin' );
	}

	$artist_id       = absint( $request->get_param( 'artist_id' ) );
	$current_user_id = get_current_user_id();
	$can_edit        = false;
	$manage_url      = '';

	if ( $artist_id && $current_user_id && function_exists( 'ec_can_manage_artist' ) && ec_can_manage_artist( $current_user_id, $artist_id ) ) {
		$can_edit   = true;
		$manage_url = home_url( '/manage-link-page/' );
	}

	return rest_ensure_response(
		array(
			'can_edit'   => $can_edit,
			'manage_url' => $manage_url,
			'user_id'    => $current_user_id,
		)
	);
}
