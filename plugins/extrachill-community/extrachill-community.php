<?php
/**
 * Plugin Name: Extra Chill Community
 * Description: bbPress extension plugin providing community and forum functionality for the Extra Chill platform.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Text Domain: extra-chill-community
 * Domain Path: /languages
 *
 * @package ExtraChillCommunity
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin initialization
function extrachill_community_init() {
    // Load plugin functionality
    require_once plugin_dir_path(__FILE__) . 'inc/core/multisite/main-site-comments.php';
    require_once plugin_dir_path(__FILE__) . 'forum-features/forum-features.php';
    require_once plugin_dir_path(__FILE__) . 'login/login-includes.php';
    require_once plugin_dir_path(__FILE__) . 'login/email-change-emails.php';
}
add_action('plugins_loaded', 'extrachill_community_init');