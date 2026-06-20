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
 *
 * @package ExtraChill\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_permissions_route' );

/**
 * Register the artist permissions REST route.
 *
 * @return void
 */
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
 * Auth model: the extrachill.link edit button sends a wp-native bearer token in
 * the Authorization header (NOT a cross-site cookie), resolved network-wide by
 * the wp-native determine_current_user filter. WordPress core's default REST
 * CORS handling (rest_send_cors_headers + rest_handle_options_request) already
 * echoes the request Origin into Access-Control-Allow-Origin and lists
 * Authorization in Access-Control-Allow-Headers, so the cross-origin GET and its
 * OPTIONS preflight from extrachill.link succeed without any extra headers here.
 * The legacy SameSite=None cross-site cookie path is no longer used by this flow.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error The response object.
 */
function ec_api_check_artist_permissions( WP_REST_Request $request ) {
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
