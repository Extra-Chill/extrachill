<?php
/**
 * Plugin Name: Extra Chill Users
 * Plugin URI: https://extrachill.com
 * Description: Single source of truth for user management across the ExtraChill Platform network. Handles authentication, user creation, team members, profile URL resolution, custom avatars, avatar menu, online user tracking, and ad-free licenses.
 * Version: 0.3.1
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Network: true
 * Requires Plugins: extrachill-multisite, extrachill-api
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Text Domain: extrachill-users
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_USERS_VERSION', '0.2.4' );
define( 'EXTRACHILL_USERS_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_USERS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_USERS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'extrachill_users_activate' );

function extrachill_users_activate() {
	if ( ! is_multisite() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( 'Extra Chill Users plugin requires a WordPress multisite installation.' );
	}
}

add_action( 'init', 'extrachill_users_register_blocks' );

function extrachill_users_register_blocks() {
	register_block_type( __DIR__ . '/build/login-register' );
	register_block_type( __DIR__ . '/build/password-reset' );
}

function extrachill_users_enqueue_block_styles() {
	if ( is_admin() ) {
		return;
	}

	$blocks_to_check = array(
		'login-register' => 'extrachill/login-register',
		'password-reset' => 'extrachill/password-reset',
	);

	foreach ( $blocks_to_check as $block_slug => $block_name ) {
		if ( has_block( $block_name ) ) {
			$style_path = EXTRACHILL_USERS_PLUGIN_DIR . "build/{$block_slug}/style-index.css";

			if ( file_exists( $style_path ) ) {
				// Get WordPress core block handle (backwards compatible)
				$handle = (
					function_exists( 'wp_should_load_separate_core_block_assets' ) &&
					wp_should_load_separate_core_block_assets()
				) ? 'wp-block-library' : 'wp-block-library';

				// Read the stylesheet
				$styles = file_get_contents( $style_path );

				// Inline to WordPress core handle
				wp_add_inline_style( $handle, $styles );
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_users_enqueue_block_styles' );

add_action( 'plugins_loaded', 'extrachill_users_init' );

function extrachill_users_init() {
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth/class-redirect-handler.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth/login.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth/register.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth/logout.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth/password-reset.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/core/online-users.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/core/registration-emails.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/core/user-creation.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/team-members.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/admin-access-control.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/author-links.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/artist-profiles.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/assets.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/avatar-display.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/avatar-menu.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/comment-auto-approval.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/ad-free-license.php';
}

add_filter( 'newsletter_form_integrations', 'extrachill_users_newsletter_integration' );
function extrachill_users_newsletter_integration( $integrations ) {
	$integrations['registration'] = array(
		'label'       => __( 'User Registration', 'extrachill-users' ),
		'description' => __( 'Newsletter subscription during account registration', 'extrachill-users' ),
		'list_id_key' => 'registration_list_id',
		'enable_key'  => 'enable_registration',
		'plugin'      => 'extrachill-users',
	);
	return $integrations;
}
