<?php
/**
 * Users Leaderboard Ability
 *
 * Returns ranked users by extrachill_total_points.
 * Canonical implementation — REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_leaderboard_ability' );

/**
 * Register the extrachill/users-leaderboard ability.
 */
function extrachill_users_register_leaderboard_ability(): void {
	wp_register_ability(
		'extrachill/users-leaderboard',
		array(
			'label'               => __( 'Get user leaderboard', 'extrachill-users' ),
			'description'         => __( 'Returns ranked users by extrachill_total_points.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items'      => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'           => array( 'type' => 'integer' ),
								'display_name' => array( 'type' => 'string' ),
								'username'     => array( 'type' => 'string' ),
								'slug'         => array( 'type' => 'string' ),
								'avatar_url'   => array( 'type' => 'string' ),
								'profile_url'  => array( 'type' => 'string' ),
								'registered'   => array( 'type' => 'string' ),
								'points'       => array( 'type' => 'number' ),
								'rank'         => array( 'type' => 'string' ),
								'badges'       => array( 'type' => 'array' ),
								'position'     => array( 'type' => 'integer' ),
							),
						),
					),
					'pagination' => array(
						'type'       => 'object',
						'properties' => array(
							'page'        => array( 'type' => 'integer' ),
							'per_page'    => array( 'type' => 'integer' ),
							'total'       => array( 'type' => 'integer' ),
							'total_pages' => array( 'type' => 'integer' ),
						),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_users_ability_leaderboard',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}

/**
 * Get leaderboard-ranked users by extrachill_total_points.
 *
 * @param array $input Input with optional 'page' and 'per_page'.
 * @return array Leaderboard data.
 */
function extrachill_users_ability_leaderboard( array $input ): array {
	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 25 ) ) );
	$offset   = ( $page - 1 ) * $per_page;

	$query = new WP_User_Query(
		array(
			'fields'   => 'all',
			'orderby'  => 'meta_value_num',
			'order'    => 'DESC',
			'number'   => $per_page,
			'offset'   => $offset,
			'meta_key' => 'extrachill_total_points',
		)
	);

	$total_query = new WP_User_Query(
		array(
			'fields'   => 'ID',
			'orderby'  => 'meta_value_num',
			'order'    => 'DESC',
			'meta_key' => 'extrachill_total_points',
		)
	);

	$total = (int) $total_query->get_total();
	// $per_page is clamped to 1..100 above, so the divisor is always > 0.
	$total_pages = (int) ceil( $total / $per_page );

	$items = array();
	$index = 0;

	foreach ( $query->get_results() as $user ) {
		$user_id = (int) $user->ID;
		$points  = (float) get_user_meta( $user_id, 'extrachill_total_points', true );
		$rank    = function_exists( 'ec_get_rank_for_points' ) ? ec_get_rank_for_points( $points ) : '';
		$badges  = function_exists( 'ec_get_user_badges' ) ? ec_get_user_badges( $user_id ) : array();

		$profile_url = function_exists( 'extrachill_get_user_profile_url' )
			? extrachill_get_user_profile_url( $user_id, $user->user_email )
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

		++$index;
	}

	return array(
		'items'      => $items,
		'pagination' => array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
		),
	);
}
