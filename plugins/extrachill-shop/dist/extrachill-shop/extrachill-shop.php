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
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Define plugin constants
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

        // Plugin activation/deactivation hooks
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'woocommerce_missing_notice' ] );
            return;
        }

        // Load plugin functionality
        $this->load_includes();
    }

    /**
     * Load plugin includes
     */
    private function load_includes() {
        // Core functionality
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/plugin-helpers.php';

        // WooCommerce integration (will be added in next phase)
        // require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/woocommerce/core.php';
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
        // Check WooCommerce dependency
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( EXTRACHILL_SHOP_PLUGIN_BASENAME );
            wp_die(
                esc_html__( 'Extra Chill Shop requires WooCommerce to be installed and activated.', 'extrachill-shop' ),
                esc_html__( 'Plugin Activation Error', 'extrachill-shop' ),
                [ 'back_link' => true ]
            );
        }

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
     * Show admin notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php esc_html_e( 'Extra Chill Shop requires WooCommerce to be installed and activated.', 'extrachill-shop' ); ?>
            </p>
        </div>
        <?php
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