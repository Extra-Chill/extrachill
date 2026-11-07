<?php
/** Hosted direct-charge merch gateway. */
defined( 'ABSPATH' ) || exit;

class ExtraChill_Merch_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id           = 'extrachill_stripe_checkout';
		$this->method_title = 'Extra Chill Stripe Checkout';
		$this->title        = 'Pay securely with Stripe';
		$this->description  = 'Complete payment securely on Stripe.';
		$this->has_fields   = false;
		$this->enabled      = 'yes';
		$this->supports     = array( 'products', 'refunds' );
	}

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

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return extrachill_shop_refund_merch_payment( wc_get_order( $order_id ), $amount, $reason );
	}
}
