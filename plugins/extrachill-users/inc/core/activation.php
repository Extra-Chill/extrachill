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

	if ( ! wp_next_scheduled( 'extrachill_welcome_email_fallback' ) ) {
		wp_schedule_event( time(), 'hourly', 'extrachill_welcome_email_fallback' );
	}
}

/**
 * Deactivation handler.
 * Unschedules cron events.
 */
function extrachill_users_run_deactivation() {
	$timestamp = wp_next_scheduled( 'extrachill_welcome_email_fallback' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'extrachill_welcome_email_fallback' );
	}
}

/**
 * Cron callback for sending welcome emails to users who never completed onboarding.
 *
 * Finds users registered over 1 hour ago who haven't completed onboarding
 * and haven't received a welcome email yet.
 */
function extrachill_welcome_email_fallback_callback() {
	$users = get_users(
		array(
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'   => 'onboarding_completed',
					'value' => '0',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => 'welcome_email_sent',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'welcome_email_sent',
						'value' => '0',
					),
				),
			),
			'date_query' => array(
				array(
					'before' => '1 hour ago',
				),
			),
		)
	);

	$ability = wp_get_ability( 'extrachill/send-welcome-email' );
	if ( ! $ability ) {
		return;
	}

	foreach ( $users as $user ) {
		$ability->execute(
			array(
				'user_id'    => $user->ID,
				'email_type' => 'onboarding_incomplete',
			)
		);
	}
}

add_action( 'extrachill_welcome_email_fallback', 'extrachill_welcome_email_fallback_callback' );

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
