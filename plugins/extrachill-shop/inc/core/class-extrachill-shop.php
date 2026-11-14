<?php
/**
 * Extra Chill Shop plugin bootstrap class.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

class ExtraChillShop {

	private static ?ExtraChillShop $instance = null;

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
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		register_activation_hook( EXTRACHILL_SHOP_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( EXTRACHILL_SHOP_PLUGIN_FILE, array( $this, 'deactivate' ) );
	}

	public function init() {
		$this->load_includes();
	}

	private function load_includes() {
		// Product customizations
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/product-provisioning.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/lifetime-membership-product.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/lifetime-membership.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/priority-boost-product.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/products/priority-boost.php';

		// Core functionality
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/commerce-state.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/seller-permissions.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/legal-documents.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/checkout-terms.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/owned-state-migration.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/order-seller-index.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/shop-manager.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/woocommerce-templates.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/breadcrumb-integration.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/assets.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/nav.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/filters/button-classes.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/shop-filter-bar.php';

		// Artist marketplace
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/artist-taxonomy.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/artist-product-meta.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/marketplace-settings.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/commerce-fees.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/seller-storefront.php';

		// Commerce credential resolvers. The provider classes + DM registration +
		// read-filter contract now live at the network layer
		// (extrachill-multisite, inc/commerce/auth/) so they load in network-admin
		// (write path) AND blog 3 (read path). These resolvers return an empty
		// fallback; the decrypted values flow via apply_filters() callbacks
		// registered by multisite. See extrachill-multisite#92.

		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/ticket-service-authority.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/tickets.php';

		// Stripe Connect integration
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/stripe-connect.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/checkout-handler.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/webhooks.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/payment-integration.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/stripe/refunds.php';

		// Shipping integration
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/shipping/shipping-settings.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/shipping/shippo-client.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/shipping/shipping-recovery.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/shipping/checkout-shipping.php';
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/shipping/shipping-zone-provisioning.php';

		// Artist notifications
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/core/seller-order-notifications.php';

		// Templates
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/templates/cart-icon.php';

		// Abilities
		require_once EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/abilities/register.php';
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'extrachill-shop',
			false,
			dirname( plugin_basename( EXTRACHILL_SHOP_PLUGIN_FILE ) ) . '/languages'
		);
	}

	public function activate() {
		update_option( 'extrachill_shop_activated', true );
		update_option( 'extrachill_shop_needs_lifetime_membership_product_sync', 1 );
		update_option( 'extrachill_shop_needs_priority_boost_product_sync', 1 );
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
