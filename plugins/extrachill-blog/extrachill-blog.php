<?php
/**
 * Plugin Name: Extra Chill Blog
 * Plugin URI: https://extrachill.com
 * Description: Blog-specific functionality for extrachill.com (Blog ID 1). Provides secondary header navigation, homepage customizations, and blog-specific templates.
 * Version: 0.6.8
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-blog
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_BLOG_VERSION', '0.6.8' );
define( 'EXTRACHILL_BLOG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_BLOG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/nav.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/co-authors.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/admin-customizations.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/ads-filter.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/homepage-hooks.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/power/power-page.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/blog-archive-routing.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/breadcrumbs.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/single/share-card.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/single/network-bridge.php';

/**
 * Register plugin styles
 */
function extrachill_blog_register_styles() {
	wp_register_style(
		'extrachill-blog-share-card',
		EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/share-card.css',
		array(),
		filemtime( EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/css/share-card.css' )
	);

	wp_register_style(
		'extrachill-blog-network-bridge',
		EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/network-bridge.css',
		array( 'extrachill-root' ),
		filemtime( EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/css/network-bridge.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_blog_register_styles', 5 );

/**
 * Provision the /power manifesto page on activation.
 *
 * The version-gated admin_init check in inc/power/power-page.php handles
 * upgrades; this guarantees the page exists immediately on fresh activation.
 */
function extrachill_blog_activate() {
	if ( function_exists( 'extrachill_blog_create_power_page' ) ) {
		extrachill_blog_create_power_page();
	}
}
register_activation_hook( __FILE__, 'extrachill_blog_activate' );
