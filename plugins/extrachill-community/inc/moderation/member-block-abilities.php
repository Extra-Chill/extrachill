<?php
/**
 * Member Block Abilities
 *
 * Self-scoped abilities for blocking, unblocking, and listing blocked
 * members. Every ability acts on the current user's own block list only;
 * there is no input that reads or writes another member's list, and nothing
 * reveals to a member who has blocked them.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_member_block_abilities' );

/**
 * Permission callback shared by the member block abilities.
 *
 * @return bool
 */
function extrachill_community_member_block_permission() {
	return is_user_logged_in();
}

/**
 * Register member block abilities.
 */
function extrachill_community_register_member_block_abilities() {
	$change_output = array(
		'type'                 => 'object',
		'properties'           => array(
			'user_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'blocked' => array( 'type' => 'boolean' ),
			'total'   => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_MAX,
			),
		),
		'required'             => array( 'user_id', 'blocked', 'total' ),
		'additionalProperties' => false,
	);

	wp_register_ability(
		'extrachill/community-block-user',
		array(
			'label'               => __( 'Block Member', 'extra-chill-community' ),
			'description'         => __( 'Block a member for the current user. Their topics and replies are hidden from your community views. The blocked member is not notified.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Member to block or unblock.',
					),
				),
				'required'             => array( 'user_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $change_output,
			'execute_callback'    => 'extrachill_community_ability_block_user',
			'permission_callback' => 'extrachill_community_member_block_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-unblock-user',
		array(
			'label'               => __( 'Unblock Member', 'extra-chill-community' ),
			'description'         => __( 'Remove a member from the current user\'s block list.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Member to block or unblock.',
					),
				),
				'required'             => array( 'user_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $change_output,
			'execute_callback'    => 'extrachill_community_ability_unblock_user',
			'permission_callback' => 'extrachill_community_member_block_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-list-blocked-users',
		array(
			'label'               => __( 'List Blocked Members', 'extra-chill-community' ),
			'description'         => __( 'List the members the current user has blocked.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'default'              => array(),
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'users' => array(
						'type'     => 'array',
						'maxItems' => EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_MAX,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'user_id'      => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'display_name' => array( 'type' => 'string' ),
								'profile_url'  => array(
									'oneOf' => array(
										array( 'type' => 'null' ),
										array(
											'type'   => 'string',
											'format' => 'uri',
										),
									),
								),
							),
							'required'             => array( 'user_id', 'display_name', 'profile_url' ),
							'additionalProperties' => false,
						),
					),
					'total' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_MAX,
					),
				),
				'required'             => array( 'users', 'total' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'extrachill_community_ability_list_blocked_users',
			'permission_callback' => 'extrachill_community_member_block_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Validate the shared block/unblock target.
 *
 * @param array $input Ability input.
 * @return array{0:int,1:int}|WP_Error Tuple of [current user ID, target ID].
 */
function extrachill_community_member_block_resolve_target( $input ) {
	$user_id   = get_current_user_id();
	$target_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

	if ( $user_id <= 0 ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}
	if ( $target_id <= 0 ) {
		return new WP_Error( 'missing_user_id', 'A valid user_id is required.', array( 'status' => 400 ) );
	}
	if ( $target_id === $user_id ) {
		return new WP_Error( 'cannot_block_self', 'You cannot block yourself.', array( 'status' => 400 ) );
	}

	return array( $user_id, $target_id );
}

/**
 * Block a member for the current user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_block_user( $input ) {
	$resolved = extrachill_community_member_block_resolve_target( $input );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $user_id, $target_id ) = $resolved;

	if ( ! get_userdata( $target_id ) ) {
		return new WP_Error( 'user_not_found', 'Member not found.', array( 'status' => 404 ) );
	}

	$ids = extrachill_community_get_member_blocked_ids( $user_id );
	if ( ! in_array( $target_id, $ids, true ) ) {
		if ( count( $ids ) >= EXTRACHILL_COMMUNITY_MEMBER_BLOCKS_MAX ) {
			return new WP_Error( 'block_limit_reached', 'You have reached the maximum number of blocked members.', array( 'status' => 400 ) );
		}
		$ids[] = $target_id;
		$ids   = extrachill_community_set_member_blocked_ids( $user_id, $ids );

		/**
		 * Fires after a member blocks another member.
		 *
		 * @param int $user_id   Blocking member.
		 * @param int $target_id Blocked member.
		 */
		do_action( 'extrachill_community_member_blocked', $user_id, $target_id );
	}

	return array(
		'user_id' => $target_id,
		'blocked' => true,
		'total'   => count( $ids ),
	);
}

/**
 * Unblock a member for the current user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_unblock_user( $input ) {
	$resolved = extrachill_community_member_block_resolve_target( $input );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $user_id, $target_id ) = $resolved;

	$ids = extrachill_community_get_member_blocked_ids( $user_id );
	if ( in_array( $target_id, $ids, true ) ) {
		$ids = extrachill_community_set_member_blocked_ids( $user_id, array_diff( $ids, array( $target_id ) ) );

		/**
		 * Fires after a member unblocks another member.
		 *
		 * @param int $user_id   Unblocking member.
		 * @param int $target_id Unblocked member.
		 */
		do_action( 'extrachill_community_member_unblocked', $user_id, $target_id );
	}

	return array(
		'user_id' => $target_id,
		'blocked' => false,
		'total'   => count( $ids ),
	);
}

/**
 * List members blocked by the current user.
 *
 * Members whose accounts no longer exist are omitted from the listing.
 *
 * @return array|WP_Error
 */
function extrachill_community_ability_list_blocked_users() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
	}

	$users = array();
	foreach ( extrachill_community_get_member_blocked_ids( $user_id ) as $blocked_id ) {
		$blocked = get_userdata( $blocked_id );
		if ( ! $blocked ) {
			continue;
		}

		$profile_url = function_exists( 'extrachill_get_user_profile_url' ) ? (string) extrachill_get_user_profile_url( $blocked_id ) : '';
		$users[]     = array(
			'user_id'      => (int) $blocked_id,
			'display_name' => (string) $blocked->display_name,
			'profile_url'  => '' !== $profile_url ? $profile_url : null,
		);
	}

	return array(
		'users' => $users,
		'total' => count( $users ),
	);
}
