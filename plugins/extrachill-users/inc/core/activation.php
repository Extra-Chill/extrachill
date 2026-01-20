<?php
/**
 * Plugin activation and setup logic.
 * Creates Login page with login-register block on all network sites.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main activation handler.
 * Called via register_activation_hook.
 */
function extrachill_users_run_activation() {
	if ( ! is_multisite() ) {
		deactivate_plugins( plugin_basename( EXTRACHILL_USERS_PLUGIN_FILE ) );
		wp_die( 'Extra Chill Users plugin requires a WordPress multisite installation.' );
	}

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth-tokens/db.php';
	if ( function_exists( 'extrachill_users_install_refresh_token_table' ) ) {
		extrachill_users_install_refresh_token_table();
	}

	update_site_option( 'extrachill_users_refresh_token_table_created', 1 );

	extrachill_users_create_login_pages_network();
}

/**
 * Create login page on all network sites.
 * Uses ec_get_blog_ids() from extrachill-multisite as single source of truth.
 */
function extrachill_users_create_login_pages_network() {
	if ( ! function_exists( 'ec_get_blog_ids' ) ) {
		return;
	}

	$blog_ids = ec_get_blog_ids();

	foreach ( $blog_ids as $slug => $blog_id ) {
		if ( ! get_blog_details( $blog_id ) ) {
			continue;
		}

		try {
			switch_to_blog( $blog_id );
			extrachill_users_create_login_page();
		} finally {
			restore_current_blog();
		}
	}
}

/**
 * Create login page with login-register block.
 * Skips if page already exists.
 */
function extrachill_users_create_login_page() {
	if ( get_page_by_path( 'login' ) ) {
		return;
	}

	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Login',
			'post_name'    => 'login',
			'post_content' => '<!-- wp:extrachill/login-register /-->',
			'post_status'  => 'publish',
		)
	);
}

/**
 * Fallback for new sites added after initial activation.
 * Runs on admin_init with site option flag to prevent repeated checks.
 */
function extrachill_users_maybe_create_login_page() {
	if ( get_option( 'extrachill_users_login_page_created' ) ) {
		return;
	}

	extrachill_users_create_login_page();
	update_option( 'extrachill_users_login_page_created', 1 );
}
add_action( 'admin_init', 'extrachill_users_maybe_create_login_page' );

/**
 * Ensure refresh token table exists.
 * Fallback for existing installations where table was added after activation.
 */
function extrachill_users_maybe_create_refresh_token_table() {
	if ( get_site_option( 'extrachill_users_refresh_token_table_created' ) ) {
		return;
	}

	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth-tokens/db.php';
	if ( function_exists( 'extrachill_users_install_refresh_token_table' ) ) {
		extrachill_users_install_refresh_token_table();
	}

	update_site_option( 'extrachill_users_refresh_token_table_created', 1 );
}
add_action( 'admin_init', 'extrachill_users_maybe_create_refresh_token_table' );

/**
 * Create onboarding page on community site only.
 * Runs on admin_init with site option flag to prevent repeated checks.
 */
function extrachill_users_maybe_create_onboarding_page() {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}

	$community_blog_id = ec_get_blog_id( 'community' );
	if ( get_current_blog_id() !== $community_blog_id ) {
		return;
	}

	if ( get_option( 'extrachill_users_onboarding_page_created' ) ) {
		return;
	}

	if ( ! get_page_by_path( 'onboarding' ) ) {
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Onboarding',
				'post_name'    => 'onboarding',
				'post_content' => '<!-- wp:extrachill/onboarding /-->',
				'post_status'  => 'publish',
			)
		);
	}

	update_option( 'extrachill_users_onboarding_page_created', 1 );
}
add_action( 'admin_init', 'extrachill_users_maybe_create_onboarding_page' );
