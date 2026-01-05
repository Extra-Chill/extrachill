<?php
/**
 * Plugin Name: Extra Chill Analytics
 * Description: Network-wide analytics tracking and reporting for the Extra Chill Platform.
 * Version: 0.1.0
 * Author: Chris Huber
 * Network: true
 * Text Domain: extrachill-analytics
 *
 * @package ExtraChill\Analytics
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_ANALYTICS_VERSION', '0.1.0' );
define( 'EXTRACHILL_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_ANALYTICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core functionality (network-wide)
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/view-counts.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/assets.php';

// Admin functionality
if ( is_admin() ) {
	require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/admin/network-menu.php';
}
