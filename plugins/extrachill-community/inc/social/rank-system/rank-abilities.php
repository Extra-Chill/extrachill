<?php
/**
 * Rank System Abilities
 *
 * Abilities-first primitives for user points, ranks, and leaderboard.
 * Colocated with point-calculation.php and chill-forums-rank.php.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_rank_abilities' );

/**
 * Register rank system abilities.
 */
function extrachill_community_register_rank_abilities() {

	wp_register_ability(
		'extrachill/community-get-user-points',
		array(
			'label'               => __( 'Get User Points', 'extrachill-community' ),
			'description'         => __( 'Get points, rank, and display name for a user.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer', 'description' => 'User ID (required)' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'      => array( 'type' => 'integer' ),
					'user_login'   => array( 'type' => 'string' ),
					'display_name' => array( 'type' => 'string' ),
					'total_points' => array( 'type' => 'number' ),
					'rank'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_user_points',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-recalculate-points',
		array(
			'label'               => __( 'Recalculate Points', 'extrachill-community' ),
			'description'         => __( 'Recalculate points for a specific user or all users with cached points.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer', 'description' => 'Specific user ID (omit for all users)' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'recalculated' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_recalculate_points',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-get-leaderboard',
		array(
			'label'               => __( 'Get Leaderboard', 'extrachill-community' ),
			'description'         => __( 'Get community leaderboard with pagination.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit'  => array( 'type' => 'integer', 'description' => 'Number of users to return (default 25)' ),
					'offset' => array( 'type' => 'integer', 'description' => 'Pagination offset (default 0)' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'total' => array( 'type' => 'integer' ),
					'users' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_leaderboard',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callbacks ─────────────────────────────────────────────────────────

/**
 * Get points, rank, and breakdown for a user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_user_points( $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', 'A user_id is required.' );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	$total_points = function_exists( 'extrachill_get_user_total_points' )
		? extrachill_get_user_total_points( $user_id )
		: (float) get_user_meta( $user_id, 'extrachill_total_points', true );

	$rank = function_exists( 'extrachill_determine_rank_by_points' )
		? extrachill_determine_rank_by_points( $total_points )
		: 'Unknown';

	return array(
		'user_id'      => $user_id,
		'user_login'   => $user->user_login,
		'display_name' => $user->display_name,
		'total_points' => $total_points,
		'rank'         => $rank,
	);
}

/**
 * Recalculate points for one or all users.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_recalculate_points( $input ) {
	if ( ! function_exists( 'extrachill_get_user_total_points' ) ) {
		return new WP_Error( 'points_unavailable', 'Point calculation system is not loaded.' );
	}

	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

	if ( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', 'User not found.' );
		}

		delete_transient( 'user_topic_count_' . $user_id );
		delete_transient( 'user_reply_count_' . $user_id );
		delete_transient( 'user_points_' . $user_id );
		extrachill_get_user_total_points( $user_id );

		return array( 'recalculated' => 1 );
	}

	// All users with stored points.
	$users = get_users(
		array(
			'meta_key' => 'extrachill_total_points',
			'fields'   => 'ID',
		)
	);

	$count = 0;
	foreach ( $users as $uid ) {
		$uid = (int) $uid;
		delete_transient( 'user_topic_count_' . $uid );
		delete_transient( 'user_reply_count_' . $uid );
		delete_transient( 'user_points_' . $uid );
		extrachill_get_user_total_points( $uid );
		++$count;
	}

	if ( function_exists( 'extrachill_delete_leaderboard_cache' ) ) {
		extrachill_delete_leaderboard_cache();
	}

	return array( 'recalculated' => $count );
}

/**
 * Get leaderboard with pagination.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_leaderboard( $input ) {
	$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 25;
	$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

	if ( ! function_exists( 'extrachill_get_leaderboard_users' ) ) {
		return new WP_Error( 'leaderboard_unavailable', 'Leaderboard system is not loaded.' );
	}

	$users = extrachill_get_leaderboard_users( $limit, $offset );
	$total = extrachill_get_leaderboard_total_users();

	$result = array();
	$rank   = $offset + 1;

	foreach ( $users as $user ) {
		$points   = (float) get_user_meta( $user->ID, 'extrachill_total_points', true );
		$result[] = array(
			'rank'         => $rank,
			'user_id'      => (int) $user->ID,
			'user_login'   => $user->user_login,
			'display_name' => $user->display_name,
			'total_points' => $points,
			'rank_name'    => function_exists( 'extrachill_determine_rank_by_points' )
				? extrachill_determine_rank_by_points( $points )
				: 'Unknown',
		);
		++$rank;
	}

	return array(
		'total' => $total,
		'users' => $result,
	);
}
