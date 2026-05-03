<?php
/**
 * Users Leaderboard REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/users/leaderboard
 *
 * Public endpoint returning leaderboard-ranked users by `extrachill_total_points`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_users_leaderboard_routes' );

function extrachill_api_register_users_leaderboard_routes() {
	register_rest_route( 'extrachill/v1', '/users/leaderboard', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_users_leaderboard_get_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'page'     => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 25,
				'sanitize_callback' => 'absint',
			),
		),
	) );
}

function extrachill_api_users_leaderboard_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-get-leaderboard' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-community plugin is required.', array( 'status' => 500 ) );
	}

	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = (int) $request->get_param( 'per_page' );
	$per_page = max( 1, min( 100, $per_page ) );

	$result = $ability->execute( array(
		'limit'  => $per_page,
		'offset' => ( $page - 1 ) * $per_page,
	) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
