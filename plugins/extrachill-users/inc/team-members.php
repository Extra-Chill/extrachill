<?php
/**
 * Team Member Helper Functions
 *
 * @package ExtraChill\Users
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/team-members/role.php';

/**
 * Check team member status.
 *
 * The extra_chill_team WP role is the source of truth. A user is a
 * team member iff they hold the `access_studio` capability — granted
 * by the role on every site in the network. Super-admins always count
 * as team members regardless of role assignment.
 *
 * No fallback. No meta read. If the cap check fails, the user is not
 * a team member — full stop. The previous meta-based system was
 * retired by the one-time migration in inc/team-members/role.php.
 *
 * @param int $user_id User ID (0 = current user).
 * @return bool
 */
function ec_is_team_member( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
		return true;
	}

	// access_studio is granted by the extra_chill_team role registered
	// in inc/team-members/role.php.
	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom cap registered by ec_users_register_team_role().
	return user_can( $user_id, 'access_studio' );
}

/**
 * Check if user has account on extrachill.com.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_has_main_site_account( $user_id ) {
	if ( ! $user_id ) {
		return false;
	}

	$has_account = false;

	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return false;
	}

	if ( function_exists( 'switch_to_blog' ) ) {
		global $current_user;

		if ( isset( $current_user ) && $current_user instanceof WP_User ) {
			try {
				switch_to_blog( $main_blog_id );
				$has_account = is_user_member_of_blog( $user_id, $main_blog_id );
			} finally {
				restore_current_blog();
			}
		}
	}

	return $has_account;
}
