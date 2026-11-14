<?php
/**
 * Hosted direct-charge merch gateway.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classic WooCommerce gateway that redirects buyers to Stripe Checkout.
 */
class ExtraChill_Merch_Gateway extends WC_Payment_Gateway {
	/**
	 * Gateway ID, shared with the blocks payment method integration.
	 */
	const ID = 'extrachill_stripe_checkout';

	/**
	 * Set up gateway identity and load admin-managed settings.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'Extra Chill Stripe Checkout';
		$this->method_description = 'Redirects buyers to Stripe Checkout to pay for merch.';
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled', 'yes' );
		$this->title       = $this->get_option( 'title', 'Pay securely with Stripe' );
		$this->description = $this->get_option( 'description', "You'll be redirected to Stripe to pay securely." );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings. Enabled by default so the shop keeps a payment method until an admin opts out.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable Extra Chill Stripe Checkout',
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => 'Title',
				'type'    => 'text',
				'default' => 'Pay securely with Stripe',
			),
			'description' => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' => "You'll be redirected to Stripe to pay securely.",
			),
		);
	}

	/**
	 * Create a Stripe Checkout session and redirect the buyer to it.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		$session = extrachill_shop_start_merch_checkout( $order );
		if ( is_wp_error( $session ) ) {
			wc_add_notice( $session->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}
		return array(
			'result'   => 'success',
			'redirect' => $session->url,
		);
	}

	/**
	 * Refund through the connected Stripe account.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return extrachill_shop_refund_merch_payment( wc_get_order( $order_id ), $amount, $reason );
	}
}
