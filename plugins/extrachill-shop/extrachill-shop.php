<?php
/**
 * Plugin Name: Extra Chill Shop
 * Plugin URI: https://extrachill.com
 * Description: WooCommerce integration and e-commerce functionality for the Extra Chill platform. Features cross-domain ad-free license system, custom breadcrumbs, product category navigation, and comprehensive WooCommerce styling.
 * Version: 0.4.1
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
define( 'EXTRACHILL_SHOP_VERSION', '0.4.1' );
define( 'EXTRACHILL_SHOP_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_SHOP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_SHOP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_SHOP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

class ExtraChillShop {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        add_action( 'init', [ $this, 'load_textdomain' ] );

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    public function init() {
        $this->load_includes();
    }

    private function load_includes() {
        // Product customizations
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/ad-free-license-product.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/ad-free-license.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/raffle/admin-fields.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/raffle/frontend-counter.php';

        // Core functionality
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/woocommerce-templates.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/breadcrumb-integration.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/assets.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/nav.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/filters/button-classes.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/shop-filter-bar.php';

        // Artist marketplace
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/artist-taxonomy.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/artist-product-meta.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/commission-settings.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/artist-storefront-manage-button.php';

        // Stripe Connect integration
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/stripe-connect.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/checkout-handler.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/webhooks.php';
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/payment-integration.php';

        // Artist notifications
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/artist-order-notifications.php';

        // Templates
        require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/templates/cart-icon.php';
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'extrachill-shop',
            false,
            dirname( EXTRACHILL_SHOP_PLUGIN_BASENAME ) . '/languages'
        );
    }

    public function activate() {
        update_option( 'extrachill_shop_activated', true );
        update_option( 'extrachill_shop_needs_ad_free_product_sync', 1 );
        flush_rewrite_rules();
    }

    public function deactivate() {
        delete_option( 'extrachill_shop_activated' );
        flush_rewrite_rules();
    }

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

/**
 * Render homepage content for shop.extrachill.com
 *
 * Hooked via extrachill_homepage_content action.
 */
function extrachill_shop_render_homepage() {
	include EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/templates/shop-homepage.php';
}
add_action( 'extrachill_homepage_content', 'extrachill_shop_render_homepage', 10 );