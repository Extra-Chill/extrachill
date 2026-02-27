<?php
/**
 * Plugin Name: Extra Chill Analytics
 * Description: Network-wide analytics tracking and reporting for the Extra Chill Platform.
 * Version: 0.6.0
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

define( 'EXTRACHILL_ANALYTICS_VERSION', '0.6.0' );
define( 'EXTRACHILL_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_ANALYTICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Database table management.
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/database/events-db.php';

// Core functionality (network-wide).
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/events.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/view-counts.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/assets.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/gtm.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/abilities.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/404-tracking.php';
require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/core/email-tracking.php';

// Admin functionality.
if ( is_admin() ) {
	require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/admin/network-menu.php';
	require_once EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'inc/admin/network-tracking-settings.php';
}
