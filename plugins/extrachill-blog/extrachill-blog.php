<?php
/**
 * Plugin Name: Extra Chill Blog
 * Plugin URI: https://extrachill.com
 * Description: Blog-specific functionality for extrachill.com (Blog ID 1). Provides secondary header navigation, homepage customizations, and blog-specific templates.
 * Version: 0.15.0
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

define( 'EXTRACHILL_BLOG_VERSION', '0.15.0' );
define( 'EXTRACHILL_BLOG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_BLOG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/nav.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/co-authors.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/admin-customizations.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/core/ads-filter.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/homepage-hooks.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/power/power-page.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/submit/artist-dispatch.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/blog-archive-routing.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/breadcrumbs.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/festival-pillar.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/festival-subscriptions.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/archive/artist-pillar.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/single/share-card.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/single/network-bridge.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/single/login-register-cta.php';
require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/single/newsletter-context.php';

/**
 * Register plugin styles
 */
function extrachill_blog_register_styles() {
	$version = filemtime( EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/css/share-card.css' );
	$version = false === $version ? null : (string) $version;

	wp_register_style(
		'extrachill-blog-share-card',
		EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/share-card.css',
		array(),
		$version
	);

	$entity_pillar_version = filemtime( EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/css/entity-pillar.css' );
	$entity_pillar_version = false === $entity_pillar_version ? null : (string) $entity_pillar_version;

	wp_register_style(
		'extrachill-blog-entity-pillar',
		EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/entity-pillar.css',
		array( 'extrachill-root' ),
		$entity_pillar_version
	);

	$entity_subscription_version = filemtime( EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/js/entity-subscriptions.js' );
	$entity_subscription_version = false === $entity_subscription_version ? null : (string) $entity_subscription_version;

	wp_register_script(
		'extrachill-blog-entity-subscriptions',
		EXTRACHILL_BLOG_PLUGIN_URL . 'assets/js/entity-subscriptions.js',
		array(),
		$entity_subscription_version,
		true
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
	if ( function_exists( 'extrachill_blog_provision_submit_pages' ) ) {
		extrachill_blog_provision_submit_pages();
	}

	extrachill_blog_schedule_recently_shipped_refresh();
}
register_activation_hook( __FILE__, 'extrachill_blog_activate' );

/**
 * Remove the Recently Shipped scheduled event when the plugin is deactivated.
 */
function extrachill_blog_deactivate() {
	wp_clear_scheduled_hook( 'extrachill_blog_refresh_recently_shipped' );
}
register_deactivation_hook( __FILE__, 'extrachill_blog_deactivate' );
