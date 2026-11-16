<?php
/**
 * Per-person, network-wide submission review grants. Never a team role default.
 *
 * @package ExtraChillUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grant submission review access across the network.
 *
 * @param int $user_id User ID.
 * @return int[] Changed site IDs.
 */
function ec_grant_review_submissions( int $user_id ): array {
	return ec_users_set_review_submissions_grant( $user_id, true );
}

/**
 * Revoke submission review access across the network.
 *
 * @param int $user_id User ID.
 * @return int[] Changed site IDs.
 */
function ec_revoke_review_submissions( int $user_id ): array {
	return ec_users_set_review_submissions_grant( $user_id, false );
}

/**
 * Write the per-user grant, mirroring brand-socials permissions.
 *
 * @param int  $user_id User ID.
 * @param bool $grant   Whether to grant rather than revoke.
 * @return int[] Changed site IDs.
 */
function ec_users_set_review_submissions_grant( int $user_id, bool $grant ): array {
	if ( $user_id <= 0 || ( $grant && is_super_admin( $user_id ) ) ) {
		return array();
	}

	$changed = array();
	foreach ( ec_users_get_network_site_ids() as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		try {
			$user = new WP_User( $user_id );
			if ( ! $user->exists() ) {
				continue;
			}
			// Inspect the explicit grant, not role-derived capabilities.
			$present = ! empty( $user->caps['review_submissions'] );
			if ( $present === $grant ) {
				continue;
			}
			if ( $grant ) {
				$user->add_cap( 'review_submissions' );
			} else {
				$user->remove_cap( 'review_submissions' );
			}
			$changed[] = (int) $blog_id;
		} finally {
			restore_current_blog();
		}
	}
	return $changed;
}

/**
 * Resolve review access on main, including its editors and administrators.
 *
 * @return bool Whether the current user may read submissions.
 */
function ec_can_review_submissions(): bool {
	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return false;
	}
	switch_to_blog( $main_blog_id );
	try {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Explicit per-person grant above.
		return current_user_can( 'review_submissions' ) || current_user_can( 'edit_others_posts' );
	} finally {
		restore_current_blog();
	}
}
