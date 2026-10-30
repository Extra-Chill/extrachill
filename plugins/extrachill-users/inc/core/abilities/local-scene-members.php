<?php
/**
 * Local Scene Members Ability
 *
 * @package ExtraChill\Users
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_local_scene_members_ability' );

/**
 * Register the public Local Scene member directory ability.
 */
function extrachill_users_register_local_scene_members_ability(): void {
	wp_register_ability(
		'extrachill/local-scene-members',
		array(
			'label'               => __( 'List Local Scene members', 'extrachill-users' ),
			'description'         => __( 'Returns public profile cards for members of a canonical Local Scene.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug'     => array( 'type' => 'string' ),
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
				'required'             => array( 'slug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_users_ability_local_scene_members',
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
 * List public members of a canonical Local Scene.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Member directory response or resolution error.
 */
function extrachill_users_ability_local_scene_members( array $input ): array|WP_Error {
	$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
	if ( '' === $slug ) {
		return new WP_Error( 'missing_local_scene', __( 'A Local Scene slug is required.', 'extrachill-users' ) );
	}

	$scene = extrachill_users_resolve_local_scene( $slug );
	if ( is_wp_error( $scene ) ) {
		return $scene;
	}

	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 25 ) ) );
	$query    = new WP_User_Query(
		array(
			'number'      => $per_page,
			'offset'      => ( $page - 1 ) * $per_page,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'count_total' => true,
			'meta_query'  => array(
				'relation' => 'AND',
				array(
					'key'   => EXTRACHILL_USERS_LOCAL_SCENE_META_KEY,
					'value' => $scene['slug'],
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY,
						'value' => 'public',
					),
				),
			),
		)
	);

	$members = array();
	foreach ( $query->get_results() as $user ) {
		$user_id     = (int) $user->ID;
		$points      = (float) get_user_meta( $user_id, 'extrachill_total_points', true );
		$profile_url = function_exists( 'extrachill_get_user_profile_url' )
			? extrachill_get_user_profile_url( $user_id, $user->user_email )
			: get_author_posts_url( $user_id );

		$members[] = array(
			'user_id'      => $user_id,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'profile_url'  => $profile_url,
			'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			'custom_title' => (string) get_user_meta( $user_id, 'ec_custom_title', true ),
			'bio'          => wp_trim_words( wp_strip_all_tags( (string) $user->description ), 30 ),
			'rank'         => function_exists( 'ec_get_rank_for_points' ) ? ec_get_rank_for_points( $points ) : '',
		);
	}

	$total = (int) $query->get_total();

	return array(
		'scene'      => $scene,
		'members'    => $members,
		'pagination' => array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		),
	);
}
