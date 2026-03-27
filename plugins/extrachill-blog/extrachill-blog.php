<?php
/**
 * Plugin Name: Extra Chill Blog
 * Plugin URI: https://extrachill.com
 * Description: Blog-specific functionality for extrachill.com (Blog ID 1). Provides secondary header navigation, homepage customizations, and blog-specific templates.
 * Version: 0.3.15
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

define( 'EXTRACHILL_BLOG_VERSION', '0.3.15' );
define( 'EXTRACHILL_BLOG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_BLOG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/nav.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/co-authors.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/admin-customizations.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/ads-filter.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/homepage-hooks.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/blog-archive-routing.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/breadcrumbs.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/single/share-card.php';

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
}
add_action( 'wp_enqueue_scripts', 'extrachill_blog_register_styles', 5 );
