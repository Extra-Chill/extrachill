<?php
/**
 * User Search REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/users/search - Search users by term
 *
 * Contexts:
 * - mentions (default): Public, lightweight response for @mentions autocomplete
 * - admin: Admin-only, full user data for relationship management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_search_routes' );

function extrachill_api_register_user_search_routes() {
	register_rest_route( 'extrachill/v1', '/users/search', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_user_search_handler',
		'permission_callback' => 'extrachill_api_user_search_permission_check',
		'args'                => array(
			'term'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'context' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'mentions',
				'enum'              => array( 'mentions', 'admin' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}

/**
 * Permission check for user search endpoint
 *
 * - mentions context: Public access (for @mentions in forums)
 * - admin context: Requires manage_options capability
 */
function extrachill_api_user_search_permission_check( WP_REST_Request $request ) {
	$context = $request->get_param( 'context' );

	if ( $context === 'admin' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				'Admin access required.',
				array( 'status' => 403 )
			);
		}
	}

	return true;
}

/**
 * Search handler - find users by term
 */
function extrachill_api_user_search_handler( WP_REST_Request $request ) {
	$term    = $request->get_param( 'term' );
	$context = $request->get_param( 'context' );

	if ( empty( $term ) ) {
		return new WP_Error(
			'missing_search_term',
			'Search term is required.',
			array( 'status' => 400 )
		);
	}

	// Require minimum 2 characters for admin context
	if ( $context === 'admin' && strlen( $term ) < 2 ) {
		return rest_ensure_response( array() );
	}

	$search_columns = array( 'user_login', 'user_nicename' );
	$number         = 10;

	// Admin context searches more columns and returns more results
	if ( $context === 'admin' ) {
		$search_columns = array( 'user_login', 'user_email', 'display_name' );
		$number         = 20;
	}

	$users_query = new WP_User_Query( array(
		'search'         => '*' . esc_attr( $term ) . '*',
		'search_columns' => $search_columns,
		'number'         => $number,
		'orderby'        => 'display_name',
		'order'          => 'ASC',
	) );

	$users_data = array();

	foreach ( $users_query->get_results() as $user ) {
		if ( $context === 'admin' ) {
			$users_data[] = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			);
		} else {
			// Mentions context - lightweight response
			$users_data[] = array(
				'id'       => $user->ID,
				'username' => $user->user_login,
				'slug'     => $user->user_nicename,
			);
		}
	}

	return rest_ensure_response( $users_data );
}
