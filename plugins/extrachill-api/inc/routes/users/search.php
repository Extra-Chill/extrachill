<?php
/**
 * User Search REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/users/search - Search users by term
 *
 * Thin wrapper around the extrachill/users-search ability. All search logic
 * (column selection, context handling, artist-capable filtering) lives in
 * the ability implementation in the extrachill-users plugin.
 *
 * Contexts:
 * - mentions (default): Logged-in only, lightweight response for @mentions autocomplete
 * - admin: Admin-only, full user data for relationship management
 * - artist-capable: Users who can create artist profiles (for roster invites)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_search_routes' );

function extrachill_api_register_user_search_routes() {
	register_rest_route(
		'extrachill/v1',
		'/users/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_user_search_handler',
			'permission_callback' => 'extrachill_api_user_search_permission_check',
			'args'                => array(
				'term'              => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'context'           => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'mentions',
					'enum'              => array( 'mentions', 'admin', 'artist-capable' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'exclude_artist_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Permission check for user search endpoint.
 *
 * Gates at logged-in users. Context-specific permission checks
 * (admin requires manage_options, artist-capable requires
 * ec_can_create_artist_profiles) live in the ability's execute_callback
 * which can read the sanitized input.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function extrachill_api_user_search_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
		);
	}

	return true;
}

/**
 * Search handler - thin wrapper around extrachill/users-search ability.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with user list or error.
 */
function extrachill_api_user_search_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/users-search' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_not_found',
			'Users search ability is not available.',
			array( 'status' => 500 )
		);
	}

	$input = array(
		'term'    => $request->get_param( 'term' ),
		'context' => $request->get_param( 'context' ),
	);

	$exclude_artist_id = $request->get_param( 'exclude_artist_id' );
	if ( ! empty( $exclude_artist_id ) ) {
		$input['exclude_artist_id'] = absint( $exclude_artist_id );
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
