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

add_action( 'rest_api_init', function () {
	register_rest_route( 'extrachill/v1', '/artist/permissions', array(
		'methods'             => array( 'GET', 'POST' ),
		'callback'            => 'ec_api_check_artist_permissions',
		'permission_callback' => '__return_true', // Public endpoint, auth handled via cookies
		'args'                => array(
			'artist_id' => array(
				'required'          => true,
				'validate_callback' => function( $param ) {
					return is_numeric( $param );
				},
			),
		),
	) );
} );

/**
 * Check artist permissions
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response The response object.
 */
function ec_api_check_artist_permissions( WP_REST_Request $request ) {
	// Handle CORS for extrachill.link
	$origin = get_http_origin();
	if ( $origin === 'https://extrachill.link' ) {
		header( 'Access-Control-Allow-Origin: https://extrachill.link' );
		header( 'Access-Control-Allow-Credentials: true' );
	}

	$artist_id       = $request->get_param( 'artist_id' );
	$current_user_id = get_current_user_id();
	$can_edit        = false;
	$manage_url      = '';

	if ( $current_user_id && function_exists( 'ec_can_manage_artist' ) && ec_can_manage_artist( $current_user_id, $artist_id ) ) {
		$can_edit   = true;
		$manage_url = home_url( '/manage-link-page/?artist_id=' . $artist_id );
	}

	// Return structure matching wp_send_json_success for compatibility
	return new WP_REST_Response( array(
		'success' => true,
		'data'    => array(
			'can_edit'   => $can_edit,
			'manage_url' => $manage_url,
			'user_id'    => $current_user_id,
		),
	), 200 );
}
