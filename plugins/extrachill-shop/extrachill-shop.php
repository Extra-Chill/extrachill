<?php
/**
 * Plugin Name: Extra Chill Shop
 * Plugin URI: https://extrachill.com
 * Description: WooCommerce integration and e-commerce functionality for the Extra Chill platform. Features cross-domain lifetime membership system, custom breadcrumbs, product category navigation, and comprehensive WooCommerce styling.
 * Version: 0.24.3
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-shop
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;
define( 'EXTRACHILL_SHOP_VERSION', '0.24.3' );
define( 'EXTRACHILL_SHOP_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_SHOP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_SHOP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_SHOP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/class-extrachill-shop.php';

require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/plugin-functions.php';

// Start the plugin.
extrachill_shop();
