<?php
/**
 * Delete My Account Ability
 *
 * Self-service, network-wide account deletion (Apple App Store guideline
 * 5.1.1(v)). The authenticated user deletes their own account; nobody can
 * target another user through this ability.
 *
 * Content handling: the network has no anonymous/"deleted user" account to
 * reassign authorship to (the network bot user is reserved for honest
 * automation attribution), so the account is removed with core's
 * wpmu_delete_user() and no reassignment. Core removes the user's posts on
 * every site they belong to and deletes all of their usermeta rows, so no
 * orphaned usermeta is left behind (see inc/core/orphan-meta-cleanup.php for
 * the sweeper that covers legacy deletion paths).
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 2 ) . '/oauth/apple-token-revocation.php';

/**
 * Exact confirmation phrase required to delete an account.
 */
const EXTRACHILL_USERS_DELETE_ACCOUNT_CONFIRMATION = 'DELETE';

add_action( 'wp_abilities_api_init', 'extrachill_users_register_delete_my_account_ability' );

/**
 * Register the delete-my-account ability.
 */
function extrachill_users_register_delete_my_account_ability() {
	wp_register_ability(
		'extrachill/delete-my-account',
		array(
			'label'               => __( 'Delete My Account', 'extrachill-users' ),
			'description'         => __( 'Permanently delete the authenticated user account across the Extra Chill network. Requires confirm: "DELETE". Staff accounts are refused.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'confirm' => array(
						'type'        => 'string',
						'description' => __( 'Must be exactly "DELETE".', 'extrachill-users' ),
					),
				),
				'required'   => array( 'confirm' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'deleted' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_delete_my_account',
			// Self-only: deletes the authenticated user; staff accounts are refused.
			'permission_callback' => 'extrachill_users_can_delete_own_account',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);
}

/**
 * Permission check: a logged-in, non-staff user acting on themselves.
 *
 * @return bool|WP_Error True when allowed, WP_Error otherwise.
 */
function extrachill_users_can_delete_own_account() {
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( extrachill_users_is_protected_from_self_deletion( $user_id ) ) {
		return extrachill_users_self_deletion_refused_error();
	}

	return true;
}

/**
 * Whether an account is staff and therefore cannot self-delete.
 *
 * Refuses super admins, team members (extra_chill_team role / access_studio
 * cap via ec_is_team_member()), and anyone holding the administrator or team
 * role on any site in the network, including archived or spammed sites.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function extrachill_users_is_protected_from_self_deletion( int $user_id ): bool {
	global $wpdb;

	if ( is_super_admin( $user_id ) ) {
		return true;
	}

	if ( function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user_id ) ) {
		return true;
	}

	$protected_roles = array( 'administrator', defined( 'EC_USERS_TEAM_ROLE' ) ? EC_USERS_TEAM_ROLE : 'extra_chill_team' );

	$blog_ids = is_multisite() ? array_map( 'intval', array_keys( get_blogs_of_user( $user_id, true ) ) ) : array( get_current_blog_id() );
	foreach ( $blog_ids as $blog_id ) {
		$caps = get_user_meta( $user_id, $wpdb->get_blog_prefix( $blog_id ) . 'capabilities', true );
		if ( ! is_array( $caps ) ) {
			continue;
		}

		foreach ( $protected_roles as $role ) {
			if ( ! empty( $caps[ $role ] ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Error returned when a staff account attempts self-deletion.
 *
 * @return WP_Error
 */
function extrachill_users_self_deletion_refused_error(): WP_Error {
	return new WP_Error(
		'account_deletion_refused',
		__( 'Staff accounts cannot be deleted from the app. Please contact the Extra Chill team.', 'extrachill-users' ),
		array( 'status' => 403 )
	);
}

/**
 * Delete the authenticated user's account network-wide.
 *
 * @param array $input Input with `confirm` set to exactly "DELETE".
 * @return array|WP_Error `{ deleted: true }` on success.
 */
function extrachill_users_ability_delete_my_account( $input ) {
	// Self-only: always operate on the authenticated user, ignoring any client-supplied user_id.
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$confirm = is_array( $input ) && isset( $input['confirm'] ) ? $input['confirm'] : null;
	if ( EXTRACHILL_USERS_DELETE_ACCOUNT_CONFIRMATION !== $confirm ) {
		return new WP_Error(
			'confirmation_required',
			__( 'Type DELETE to confirm account deletion.', 'extrachill-users' ),
			array( 'status' => 400 )
		);
	}

	// Defense in depth: PHP callers can reach the execute callback directly.
	if ( extrachill_users_is_protected_from_self_deletion( $user_id ) ) {
		return extrachill_users_self_deletion_refused_error();
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ), array( 'status' => 404 ) );
	}
	$user_email = (string) $user->user_email;

	// Apple revocation needs the stored identifiers, so it runs before deletion.
	// It never blocks deletion.
	extrachill_users_revoke_apple_tokens_for_user( $user_id );

	extrachill_users_revoke_user_auth_state( $user_id );
	extrachill_users_unsubscribe_deleted_user_newsletter( $user_id, $user_email );

	/**
	 * Fires before a user deletes their own account.
	 *
	 * The user and their meta still exist when this fires, so listeners can
	 * clean up records keyed to the user ID or email address.
	 *
	 * @param int    $user_id    User being deleted.
	 * @param string $user_email User's email address.
	 */
	do_action( 'extrachill_user_self_deleted', $user_id, $user_email );

	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = wpmu_delete_user( $user_id );
	} else {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = wp_delete_user( $user_id );
	}

	if ( ! $deleted ) {
		return new WP_Error(
			'account_deletion_failed',
			__( 'Your account could not be deleted. Please try again or contact support.', 'extrachill-users' ),
			array( 'status' => 500 )
		);
	}

	wp_set_current_user( 0 );
	if ( ! headers_sent() ) {
		wp_clear_auth_cookie();
	}

	return array( 'deleted' => true );
}

/**
 * Revoke native refresh tokens and destroy WordPress sessions for a user.
 *
 * Failures are logged, not fatal: once the user row is gone, any surviving
 * token cannot resolve to an account.
 *
 * @param int $user_id User ID.
 */
function extrachill_users_revoke_user_auth_state( int $user_id ): void {
	if ( function_exists( 'wp_native_auth_revoke_user_refresh_tokens' ) ) {
		$result = wp_native_auth_revoke_user_refresh_tokens( $user_id );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational visibility; deletion proceeds.
			error_log( sprintf( 'Account deletion: native refresh-token revocation failed for user %d: %s', $user_id, $result->get_error_message() ) );
		}
	}

	if ( class_exists( 'WP_Session_Tokens' ) ) {
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
	}
}

/**
 * Remove a deleting user's newsletter subscription.
 *
 * The newsletter plugin does not expose an unsubscribe API yet. The
 * unsubscriber is resolved through a filter (mirroring the registration
 * subscriber filter) so the newsletter plugin can supply one; the
 * `extrachill_user_self_deleted` action is also available to listeners.
 *
 * @param int    $user_id    User ID.
 * @param string $user_email User's email address.
 */
function extrachill_users_unsubscribe_deleted_user_newsletter( int $user_id, string $user_email ): void {
	/**
	 * Filters the callable used to unsubscribe a deleting user from the newsletter.
	 *
	 * Called as `$callable( $email, 'account_deletion' )`.
	 *
	 * @param callable|string $unsubscriber Default 'extrachill_network_unsubscribe'.
	 */
	$unsubscriber = apply_filters( 'extrachill_users_account_deletion_newsletter_unsubscriber', 'extrachill_network_unsubscribe' );
	if ( '' === $user_email || ! is_callable( $unsubscriber ) ) {
		return;
	}

	$result = call_user_func( $unsubscriber, $user_email, 'account_deletion' );
	if ( is_wp_error( $result ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational visibility; deletion proceeds.
		error_log( sprintf( 'Account deletion: newsletter unsubscribe failed for user %d: %s', $user_id, $result->get_error_message() ) );
	}
}
