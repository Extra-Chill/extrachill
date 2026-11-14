<?php
/**
 * WooCommerce Blocks payment method for the hosted Stripe Checkout merch gateway.
 *
 * Loaded only from the `woocommerce_blocks_payment_method_type_registration`
 * action, where WooCommerce Blocks has already loaded AbstractPaymentMethodType.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Makes `extrachill_stripe_checkout` selectable in the block checkout. Payment
 * itself runs through the classic gateway's process_payment() redirect.
 */
final class ExtraChill_Merch_Blocks_Payment_Method extends AbstractPaymentMethodType {
	/**
	 * Script handle for the client-side registration.
	 */
	const SCRIPT_HANDLE = 'extrachill-shop-blocks-stripe-checkout';

	/**
	 * Payment method name; must match the classic gateway ID.
	 *
	 * @var string
	 */
	protected $name = 'extrachill_stripe_checkout';

	/**
	 * Load the gateway's saved settings.
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_' . $this->name . '_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Mirror the gateway's enabled setting, which defaults to enabled.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'yes' );
	}

	/**
	 * Register the dependency-only client script (no build step).
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			self::SCRIPT_HANDLE,
			EXTRACHILL_SHOP_PLUGIN_URL . 'assets/js/blocks-stripe-checkout.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			EXTRACHILL_SHOP_VERSION,
			true
		);
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Data exposed to the script as `extrachill_stripe_checkout_data`.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', 'Pay securely with Stripe' ),
			'description' => $this->get_setting( 'description', "You'll be redirected to Stripe to pay securely." ),
			'supports'    => $this->get_supported_features(),
		);
	}
}
