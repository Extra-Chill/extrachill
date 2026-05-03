<?php
/**
 * Artist Permissions REST Endpoint
 *
 * Registers the /extrachill/v1/artists/{id}/permissions endpoint to check if the current user
 * has permission to edit a specific artist profile. Used by extrachill.link for the
 * client-side edit button.
 *
 * Delegates to ability:
 * - extrachill/artist-get-permissions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_permissions_route' );

function extrachill_api_register_artist_permissions_route() {
	register_rest_route(
		'extrachill/v1',
		'/artists/(?P<id>\d+)/permissions',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ec_api_check_artist_permissions',
			'permission_callback' => '__return_true',
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
 * Check artist permissions via ability.
 *
 * CORS headers for extrachill.link remain a transport-specific concern
 * owned by this route handler.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error The response object.
 */
function ec_api_check_artist_permissions( WP_REST_Request $request ) {
	// Handle CORS for extrachill.link
	$origin = get_http_origin();
	if ( in_array( $origin, array( 'https://extrachill.link', 'https://www.extrachill.link' ), true ) ) {
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin' );
	}

	$ability = wp_get_ability( 'extrachill/artist-get-permissions' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'id' => $request->get_param( 'id' ) ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
