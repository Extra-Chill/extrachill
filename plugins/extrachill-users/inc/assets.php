<?php
/**
 * Asset Management for ExtraChill Users
 *
 * Loads avatar menu, online users CSS/JS network-wide with filemtime() versioning.
 *
 * @package ExtraChill\Users
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue avatar menu and online users assets.
 */
function extrachill_users_enqueue_avatar_menu_assets() {
    $css_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/css/avatar-menu.css';
    if (file_exists($css_path)) {
        wp_enqueue_style(
            'extrachill-users-avatar-menu',
            EXTRACHILL_USERS_PLUGIN_URL . 'assets/css/avatar-menu.css',
            array(),
            filemtime($css_path),
            'all'
        );
    }

    $js_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/js/avatar-menu.js';
    if (file_exists($js_path)) {
        wp_enqueue_script(
            'extrachill-users-avatar-menu',
            EXTRACHILL_USERS_PLUGIN_URL . 'assets/js/avatar-menu.js',
            array(),
            filemtime($js_path),
            true
        );
    }

    $online_users_css_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/css/online-users.css';
    if (file_exists($online_users_css_path)) {
        wp_enqueue_style(
            'extrachill-users-online-users',
            EXTRACHILL_USERS_PLUGIN_URL . 'assets/css/online-users.css',
            array(),
            filemtime($online_users_css_path),
            'all'
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_users_enqueue_avatar_menu_assets');

/**
 * Register shared auth utilities script for blocks.
 */
function extrachill_users_register_auth_utils_script() {
    $js_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/js/auth-utils.js';
    if (file_exists($js_path)) {
        wp_register_script(
            'extrachill-auth-utils',
            EXTRACHILL_USERS_PLUGIN_URL . 'assets/js/auth-utils.js',
            array(),
            filemtime($js_path),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_users_register_auth_utils_script');
