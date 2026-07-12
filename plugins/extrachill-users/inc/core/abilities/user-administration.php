<?php
/**
 * Network user administration abilities.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_user_administration_abilities' );

/**
 * Register abilities formerly supplied by the Admin Tools umbrella.
 *
 * Existing registrations win during the transition so this plugin can be
 * deployed before Admin Tools is removed without duplicate registrations.
 */
function extrachill_users_register_user_administration_abilities() {
	$abilities = array(
		'extrachill/grant-lifetime-membership'  => array(
			'label'            => __( 'Grant Lifetime Membership', 'extrachill-users' ),
			'description'      => __( 'Grant lifetime ad-free membership to a user by username or email.', 'extrachill-users' ),
			'input_schema'     => array(
				'type'       => 'object',
				'properties' => array(
					'user_identifier' => array(
						'type'        => 'string',
						'description' => __( 'Username or email address.', 'extrachill-users' ),
					),
				),
				'required'   => array( 'user_identifier' ),
			),
			'output_schema'    => array(
				'type'        => 'object',
				'description' => __( 'Grant confirmation with user details.', 'extrachill-users' ),
			),
			'execute_callback' => 'extrachill_users_ability_grant_lifetime_membership',
			'annotations'      => array(
				'readonly'    => false,
				'idempotent'  => true,
				'destructive' => false,
			),
		),
		'extrachill/revoke-lifetime-membership' => array(
			'label'            => __( 'Revoke Lifetime Membership', 'extrachill-users' ),
			'description'      => __( 'Revoke lifetime membership from a user.', 'extrachill-users' ),
			'input_schema'     => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'User ID to revoke membership from.', 'extrachill-users' ),
					),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'    => array(
				'type'        => 'object',
				'description' => __( 'Revoke confirmation.', 'extrachill-users' ),
			),
			'execute_callback' => 'extrachill_users_ability_revoke_lifetime_membership',
			'annotations'      => array(
				'readonly'    => false,
				'idempotent'  => true,
				'destructive' => true,
			),
		),
		'extrachill/sync-team-members'          => array(
			'label'            => __( 'Sync Team Members', 'extrachill-users' ),
			'description'      => __( 'Sync team member status for all network users based on current role assignments.', 'extrachill-users' ),
			'input_schema'     => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'    => array(
				'type'        => 'object',
				'description' => __( 'Sync report with updated site counts.', 'extrachill-users' ),
			),
			'execute_callback' => 'extrachill_users_ability_sync_team_members',
			'annotations'      => array(
				'readonly'    => false,
				'idempotent'  => true,
				'destructive' => false,
			),
		),
		'extrachill/manage-team-member'         => array(
			'label'            => __( 'Manage Team Member', 'extrachill-users' ),
			'description'      => __( 'Grant or revoke the extra_chill_team WordPress role for a user, network-wide.', 'extrachill-users' ),
			'input_schema'     => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'User ID to manage.', 'extrachill-users' ),
					),
					'action'  => array(
						'type'        => 'string',
						'description' => __( 'Action to take: force_add to grant the role, force_remove to revoke it.', 'extrachill-users' ),
						'enum'        => array( 'force_add', 'force_remove' ),
					),
				),
				'required'   => array( 'user_id', 'action' ),
			),
			'output_schema'    => array(
				'type'        => 'object',
				'description' => __( 'Updated team member status.', 'extrachill-users' ),
			),
			'execute_callback' => 'extrachill_users_ability_manage_team_member',
			'annotations'      => array(
				'readonly'    => false,
				'idempotent'  => true,
				'destructive' => false,
			),
		),
	);

	foreach ( $abilities as $name => $definition ) {
		if ( wp_get_ability( $name ) ) {
			continue;
		}

		wp_register_ability(
			$name,
			array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => 'extrachill-users',
				'input_schema'        => $definition['input_schema'],
				'output_schema'       => $definition['output_schema'],
				'execute_callback'    => $definition['execute_callback'],
				'permission_callback' => 'extrachill_users_user_administration_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => $definition['annotations'],
				),
			)
		);
	}
}

/**
 * Preserve network-admin, CLI, and scheduled automation access.
 *
 * @return bool
 */
function extrachill_users_user_administration_permission() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	if ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) {
		return true;
	}

	return current_user_can( 'manage_network_options' );
}

/**
 * Grant lifetime membership by username or email.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_grant_lifetime_membership( $input ) {
	$identifier = isset( $input['user_identifier'] ) ? sanitize_text_field( $input['user_identifier'] ) : '';
	$user       = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier );

	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
	}

	$existing = get_user_meta( $user->ID, 'extrachill_lifetime_membership', true );
	if ( empty( $existing ) ) {
		$result = ec_create_lifetime_membership( $user->user_login );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return array(
		'success'    => true,
		'message'    => 'Lifetime membership granted.',
		'user_id'    => $user->ID,
		'user_login' => $user->user_login,
		'user_email' => $user->user_email,
	);
}

/**
 * Revoke lifetime membership from a user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_revoke_lifetime_membership( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
	}

	delete_user_meta( $user_id, 'extrachill_lifetime_membership' );

	return array(
		'success'    => true,
		'message'    => 'Lifetime membership revoked.',
		'user_id'    => $user_id,
		'user_login' => $user->user_login,
	);
}

/**
 * Re-grant current team roles across every network site.
 *
 * @return array
 */
function extrachill_users_ability_sync_team_members() {
	$team_user_ids   = extrachill_users_get_current_team_user_ids();
	$sites_processed = 0;

	foreach ( $team_user_ids as $user_id ) {
		$sites_processed += count( ec_users_grant_team_role( $user_id ) );
	}

	return array(
		'total_team_users' => count( $team_user_ids ),
		'sites_processed'  => $sites_processed,
	);
}

/**
 * Grant or revoke the team role for one user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_manage_team_member( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$action  = isset( $input['action'] ) ? (string) $input['action'] : '';

	if ( ! $user_id ) {
		return new WP_Error( 'invalid_user_id', 'A positive user_id is required.', array( 'status' => 400 ) );
	}
	if ( ! get_user_by( 'id', $user_id ) ) {
		return new WP_Error( 'user_not_found', sprintf( 'User %d not found.', $user_id ), array( 'status' => 404 ) );
	}

	if ( 'force_add' === $action ) {
		$sites = ec_users_grant_team_role( $user_id );
		return array(
			'message'        => sprintf( 'Team role granted on %d site(s).', count( $sites ) ),
			'user_id'        => $user_id,
			'is_team_member' => true,
			'sites_added'    => $sites,
		);
	}
	if ( 'force_remove' === $action ) {
		$sites = ec_users_revoke_team_role( $user_id );
		return array(
			'message'        => sprintf( 'Team role revoked on %d site(s).', count( $sites ) ),
			'user_id'        => $user_id,
			'is_team_member' => false,
			'sites_removed'  => $sites,
		);
	}

	return new WP_Error( 'invalid_action', sprintf( 'Unknown action: %s. Expected force_add or force_remove.', $action ), array( 'status' => 400 ) );
}

/**
 * Find users holding the team role on any network site.
 *
 * @return int[]
 */
function extrachill_users_get_current_team_user_ids() {
	global $wpdb;

	$ids = array();
	foreach ( ec_users_get_network_site_ids() as $blog_id ) {
		$caps_key = $wpdb->get_blog_prefix( (int) $blog_id ) . 'capabilities';
		$user_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off network role synchronization requires an uncached cross-site role lookup.
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$caps_key,
				'%' . $wpdb->esc_like( EC_USERS_TEAM_ROLE ) . '%'
			)
		);
		foreach ( (array) $user_ids as $user_id ) {
			$ids[ (int) $user_id ] = true;
		}
	}

	return array_keys( $ids );
}
