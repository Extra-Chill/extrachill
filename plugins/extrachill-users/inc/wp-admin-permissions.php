<?php
/**
 * Explicit, per-user wp-admin access. Never add this capability to a role.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EC_WP_ADMIN_CAP = 'access_wp_admin';

/**
 * Read the explicit grant from the main site's user caps (not role defaults).
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_user_has_wp_admin_grant( $user_id ) {
	if ( (int) $user_id <= 0 ) {
		return false;
	}
	switch_to_blog( (int) ec_get_blog_id( 'main' ) );
	try {
		$user = new WP_User( (int) $user_id );
		return ! empty( $user->caps[ EC_WP_ADMIN_CAP ] );
	} finally {
		restore_current_blog();
	}
}

/**
 * Write the per-user capability across the network, idempotently.
 *
 * @param int  $user_id User ID.
 * @param bool $grant Whether to grant rather than revoke.
 * @return int[] Changed blog IDs.
 */
function ec_users_write_wp_admin_grant( $user_id, $grant ) {
	if ( (int) $user_id <= 0 ) {
		return array();
	}
	$changed = array();
	foreach ( ec_users_get_network_site_ids() as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		try {
			$user = new WP_User( (int) $user_id );
			if ( ! $user->exists() ) {
				continue;
			}
			$present = ! empty( $user->caps[ EC_WP_ADMIN_CAP ] );
			if ( $grant && ! $present ) {
				$user->add_cap( EC_WP_ADMIN_CAP );
				$changed[] = (int) $blog_id;
			} elseif ( ! $grant && array_key_exists( EC_WP_ADMIN_CAP, $user->caps ) ) {
				$user->remove_cap( EC_WP_ADMIN_CAP );
				$changed[] = (int) $blog_id;
			}
		} finally {
			restore_current_blog();
		}
	}
	return $changed;
}

/**
 * Grant wp-admin access network-wide.
 *
 * @param int $user_id User ID.
 * @return int[] Changed blog IDs.
 */
function ec_grant_wp_admin( $user_id ) {
	return ec_users_write_wp_admin_grant( $user_id, true );
}

/**
 * Revoke wp-admin access network-wide (administrators retain inherent access).
 *
 * @param int $user_id User ID.
 * @return int[] Changed blog IDs.
 */
function ec_revoke_wp_admin( $user_id ) {
	return ec_users_write_wp_admin_grant( $user_id, false );
}

/**
 * Network user-edit control, only for super admins.
 *
 * @param WP_User $user Edited user.
 */
function ec_users_wp_admin_profile_field( $user ) {
	if ( ! is_network_admin() || ! is_super_admin() ) {
		return;
	}
	wp_nonce_field( 'ec_wp_admin_grant_' . $user->ID, 'ec_wp_admin_grant_nonce' );
	?>
	<h2><?php esc_html_e( 'WP Admin access', 'extrachill-users' ); ?></h2>
	<table class="form-table"><tr>
		<th><label for="ec_wp_admin_grant"><?php esc_html_e( 'Explicit grant', 'extrachill-users' ); ?></label></th>
		<td><input type="checkbox" id="ec_wp_admin_grant" name="ec_wp_admin_grant" value="1" <?php checked( ec_user_has_wp_admin_grant( $user->ID ) ); ?> />
		<?php esc_html_e( 'Allow wp-admin on every site. Administrators already have access.', 'extrachill-users' ); ?></td>
	</tr></table>
	<?php
}
add_action( 'edit_user_profile', 'ec_users_wp_admin_profile_field' );

/**
 * Save only from the super-admin network edit form with its user-bound nonce.
 *
 * @param int $user_id Edited user ID.
 */
function ec_users_save_wp_admin_profile_field( $user_id ) {
	if ( ! is_network_admin() || ! is_super_admin() || ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	if ( ! isset( $_POST['ec_wp_admin_grant_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ec_wp_admin_grant_nonce'] ) ), 'ec_wp_admin_grant_' . $user_id ) ) {
		return;
	}
	if ( isset( $_POST['ec_wp_admin_grant'] ) && '1' === $_POST['ec_wp_admin_grant'] ) {
		ec_grant_wp_admin( $user_id );
	} else {
		ec_revoke_wp_admin( $user_id );
	}
}
add_action( 'edit_user_profile_update', 'ec_users_save_wp_admin_profile_field' );
