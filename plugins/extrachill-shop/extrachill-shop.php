<?php
/**
 * Plugin Name: Extra Chill Shop
 * Plugin URI: https://extrachill.com
 * Description: WooCommerce integration and e-commerce functionality for the Extra Chill platform. Features cross-domain ad-free license system, performance optimizations, and store customizations.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-shop
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;
define( 'EXTRACHILL_SHOP_VERSION', '1.0.0' );
define( 'EXTRACHILL_SHOP_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_SHOP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_SHOP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_SHOP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main Extra Chill Shop Class
 *
 * Singleton plugin class handling WooCommerce integration and e-commerce functionality.
 */
class ExtraChillShop {

    /** @var ExtraChillShop|null */
    private static $instance = null;

    /** @return ExtraChillShop */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        add_action( 'init', [ $this, 'load_textdomain' ] );

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        $this->load_includes();
    }

    /**
     * Load plugin includes
     */
    private function load_includes() {
        // Core functionality
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/database.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/ad-free-license.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/breadcrumb-integration.php';

        // Template functionality
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'templates/cart-icon.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'templates/product-category-header.php';
    }

    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'extrachill-shop',
            false,
            dirname( EXTRACHILL_SHOP_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create ad-free license table
        extrachill_shop_create_ad_free_table();

        // Set plugin activation flag
        update_option( 'extrachill_shop_activated', true );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up plugin data if needed
        delete_option( 'extrachill_shop_activated' );

        // Flush rewrite rules
        flush_rewrite_rules();
    }


    /**
     * Get plugin version
     */
    public function get_version() {
        return EXTRACHILL_SHOP_VERSION;
    }
}

// Initialize the plugin
function extrachill_shop() {
    return ExtraChillShop::instance();
}

// Start the plugin
extrachill_shop();