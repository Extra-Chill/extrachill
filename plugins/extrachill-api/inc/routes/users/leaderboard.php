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
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = (int) $request->get_param( 'per_page' );
	$per_page = max( 1, min( 100, $per_page ) );

	$offset = ( $page - 1 ) * $per_page;

	$query = new WP_User_Query( array(
		'fields'   => 'all',
		'orderby'  => 'meta_value_num',
		'order'    => 'DESC',
		'number'   => $per_page,
		'offset'   => $offset,
		'meta_key' => 'extrachill_total_points',
	) );

	$total_query = new WP_User_Query( array(
		'fields'   => 'ID',
		'orderby'  => 'meta_value_num',
		'order'    => 'DESC',
		'meta_key' => 'extrachill_total_points',
	) );

	$total       = (int) $total_query->get_total();
	$total_pages = $per_page ? (int) ceil( $total / $per_page ) : 1;

	$items = array();
	$index = 0;

	foreach ( $query->get_results() as $user ) {
		$user_id = (int) $user->ID;
		$points  = (float) get_user_meta( $user_id, 'extrachill_total_points', true );
		$rank    = function_exists( 'ec_get_rank_for_points' ) ? ec_get_rank_for_points( $points ) : '';
		$badges  = function_exists( 'ec_get_user_badges' ) ? ec_get_user_badges( $user_id ) : array();

		$profile_url = function_exists( 'ec_get_user_profile_url' )
			? ec_get_user_profile_url( $user_id, $user->user_email )
			: get_author_posts_url( $user_id );

		$items[] = array(
			'id'           => $user_id,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'slug'         => $user->user_nicename,
			'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			'profile_url'  => $profile_url,
			'registered'   => mysql2date( 'c', $user->user_registered ),
			'points'       => $points,
			'rank'         => $rank,
			'badges'       => $badges,
			'position'     => $offset + $index + 1,
		);

		$index++;
	}

	return rest_ensure_response( array(
		'items'      => $items,
		'pagination' => array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
		),
	) );
}
