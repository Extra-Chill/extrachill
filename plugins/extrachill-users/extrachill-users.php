<?php
/**
 * Plugin Name: Extra Chill Users
 * Plugin URI: https://extrachill.com
 * Description: Network-activated user management system for the ExtraChill Platform. Handles user creation, authentication, team members, profile URLs, avatar menu, and password reset.
 * Version: 1.1.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Network: true
 * Requires Plugins: extrachill-multisite
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Text Domain: extrachill-users
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_USERS_VERSION', '1.1.0' );
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

add_action( 'plugins_loaded', 'extrachill_users_init' );

function extrachill_users_init() {
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/team-members.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/author-links.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/user-creation.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/assets.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/login.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/register.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/logout.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/registration-emails.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/password-reset.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/online-users.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/avatar-display.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/avatar-upload.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/avatar-menu.php';
	require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/online-users-display.php';
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
