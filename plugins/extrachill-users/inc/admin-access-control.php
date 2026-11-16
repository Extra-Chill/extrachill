<?php
/**
 * Explicit wp-admin access and front-end team navigation.
 * Writers use Studio; AJAX and admin-post handlers remain available.
 *
 * @package ExtraChill\Users
 */

/**
 * Whether a user may enter wp-admin. Team membership alone is not a grant.
 *
 * @param WP_User|null $user User to check, or the current user.
 * @return bool
 */
function ec_users_can_access_wp_admin( $user = null ) {
	$user = $user instanceof WP_User ? $user : wp_get_current_user();
	return user_can( $user, 'manage_options' ) || ec_user_has_wp_admin_grant( $user->ID );
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
	if ( ! ec_users_can_access_wp_admin() && ! ec_is_team_member( get_current_user_id() ) ) {
		show_admin_bar( false );
	}
}
add_action( 'init', 'extrachill_hide_admin_bar_for_non_admins', 5 );

/**
 * Front-end editing destination for the current object, if one exists.
 *
 * @return string
 */
function ec_users_frontend_edit_url() {
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
		return '';
	}
	if ( (int) get_current_blog_id() === (int) ec_get_blog_id( 'main' ) && 'post' === $post->post_type ) {
		// TODO #680: deep-link to Compose once Studio supports editing this post.
		return get_home_url( ec_get_blog_id( 'studio' ), '/' );
	}
	if ( 'artist_profile' === $post->post_type && function_exists( 'ec_get_site_url' ) ) {
		return ec_get_site_url( 'artist' ) . '/manage-artist/';
	}
	if ( function_exists( 'ec_link_page_post_type' ) && ec_link_page_post_type() === $post->post_type && function_exists( 'ec_link_page_public_base_url' ) && function_exists( 'ec_get_link_page_edit_endpoints' ) ) {
		$base = ec_link_page_public_base_url();
		if ( $base && ec_get_link_page_edit_endpoints()['configuration_url'] ) {
			return add_query_arg( 'link_page', $post->ID, $base . 'edit' );
		}
	}
	if ( 'topic' === $post->post_type && function_exists( 'bbp_get_topic_edit_url' ) ) {
		return bbp_get_topic_edit_url( $post->ID );
	}
	if ( 'reply' === $post->post_type && function_exists( 'bbp_get_reply_edit_url' ) ) {
		return bbp_get_reply_edit_url( $post->ID );
	}
	// Events have no existing front-end event editor; do not invent a route.
	return '';
}

/**
 * Team bar is a front-end navigation surface, never a backend shortcut.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 */
function ec_users_trim_team_admin_bar( $bar ) {
	if ( ec_users_can_access_wp_admin() || ! ec_is_team_member( get_current_user_id() ) ) {
		return;
	}
	foreach ( array( 'dashboard', 'new-content', 'edit', 'comments', 'updates', 'site-name', 'my-sites' ) as $id ) {
		$bar->remove_node( $id );
	}
	$settings = ec_get_site_url( 'community' ) . '/settings/';
	foreach ( array( 'my-account', 'user-info', 'edit-profile' ) as $id ) {
		$node = $bar->get_node( $id );
		if ( $node ) {
			$node->href = $settings;
			$bar->add_node( (array) $node );
		}
	}
	$bar->add_node( array(
		'id'    => 'studio',
		'title' => __( 'Studio', 'extrachill-users' ),
		'href'  => get_home_url( ec_get_blog_id( 'studio' ), '/' ),
	) );
	$edit_url = ec_users_frontend_edit_url();
	if ( $edit_url ) {
		$bar->add_node( array(
			'id'    => 'edit',
			'title' => __( 'Edit', 'extrachill-users' ),
			'href'  => $edit_url,
		) );
	}
	// Catch plugin and multisite nodes too, including descendants of core nodes.
	foreach ( $bar->get_nodes() as $node ) {
		if ( false !== stripos( rawurldecode( html_entity_decode( (string) $node->href ) ), '/wp-admin' ) ) {
			$bar->remove_node( $node->id );
		}
	}
}
add_action( 'admin_bar_menu', 'ec_users_trim_team_admin_bar', PHP_INT_MAX );

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
