<?php
/**
 * Network-wide admin access for administrators and main-site team reviewers.
 * Writers use Studio; AJAX and admin-post handlers remain available.
 *
 * @package ExtraChill\Users
 */

/**
 * Whether a user may enter wp-admin or see the admin bar.
 *
 * Reuse Studio's review check when available. Studio is site-only, so other
 * sites (and login, which may check a different user) use the same main-site
 * edit_others_posts check without depending on Studio being active there.
 *
 * @param WP_User|null $user User to check, or the current user.
 * @return bool
 */
function ec_users_can_access_wp_admin( $user = null ) {
	$user = $user instanceof WP_User ? $user : wp_get_current_user();
	if ( user_can( $user, 'manage_options' ) ) {
		return true;
	}
	if ( ! ec_is_team_member( $user->ID ) ) {
		return false;
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return false;
	}
	if ( get_current_user_id() === $user->ID && function_exists( 'ec_studio_review_queue_user_can_review_main' ) ) {
		return ec_studio_review_queue_user_can_review_main( $main_blog_id );
	}

	switch_to_blog( $main_blog_id );
	try {
		// Construct in main's context: the login user may retain subsite caps.
		return user_can( new WP_User( $user->ID ), 'edit_others_posts' );
	} finally {
		restore_current_blog();
	}
}

/** Resolve a blocked user's destination from the network blog map. */
function ec_users_admin_access_redirect_url( $user ) {
	if ( ec_is_team_member( $user->ID ) ) {
		$studio_blog_id = (int) ec_get_blog_id( 'studio' );
		if ( $studio_blog_id > 0 ) {
			return get_home_url( $studio_blog_id, '/' );
		}
	}
	return home_url( '/' );
}

function extrachill_redirect_admin() {
	global $pagenow;

	if ( is_admin() && ! wp_doing_ajax() && 'admin-post.php' !== $pagenow && ! ec_users_can_access_wp_admin() ) {
		// Trusted network URL, not request input. Safe redirect rejects mapped
		// subsite domains unless they are separately allowlisted.
		wp_redirect( ec_users_admin_access_redirect_url( wp_get_current_user() ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Network-resolved destination.
		exit();
	}
}
add_action( 'admin_init', 'extrachill_redirect_admin' );

function extrachill_hide_admin_bar_for_non_admins() {
	if ( ! ec_users_can_access_wp_admin() ) {
		show_admin_bar( false );
	}
}
add_action( 'init', 'extrachill_hide_admin_bar_for_non_admins', 5 );

/** Keep blocked users out of wp-admin after login, including default redirects. */
function extrachill_prevent_admin_auth_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! $user instanceof WP_User ) {
		return $redirect_to;
	}
	if ( ! ec_users_can_access_wp_admin( $user ) ) {
		// Preserve explicit front-end continuations (Events, Roadie, etc.).
		if ( ! empty( $requested_redirect_to ) && strpos( $requested_redirect_to, '/wp-admin' ) === false && strpos( $redirect_to, '/wp-admin' ) === false ) {
			return $redirect_to;
		}
		return ec_users_admin_access_redirect_url( $user );
	}
	if ( ! empty( $requested_redirect_to ) && strpos( $requested_redirect_to, '/wp-admin' ) !== false ) {
		return $requested_redirect_to;
	}
	return $redirect_to;
}
add_filter( 'login_redirect', 'extrachill_prevent_admin_auth_redirect', 5, 3 );
