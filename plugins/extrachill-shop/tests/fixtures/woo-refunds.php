<?php
/**
 * WooCommerce refund model for merch refund tests.
 *
 * Mirrors the ordering in WooCommerce's `wc_create_refund()`: validate the
 * amount against the remaining refundable amount, save the refund row, then
 * call the gateway's `process_refund()`; delete the row if the gateway fails.
 * The gateway therefore runs while the order already counts this refund.
 *
 * @package ExtraChillShop
 */

// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
require_once dirname( __DIR__ ) . '/SellerCommerceTest.php';
require_once dirname( __DIR__ ) . '/TicketTest.php';
require_once dirname( __DIR__, 2 ) . '/inc/stripe/refunds.php';

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public $id, $method_title, $title, $description, $has_fields, $enabled, $supports;
	}
}
require_once dirname( __DIR__, 2 ) . '/inc/stripe/class-merch-gateway.php';

/** Refund bookkeeping for test orders, computed the way WC_Order does. */
trait WooRefundTracking {
	/** Saved refund rows, in major units. */
	public $refunds = array();

	public function get_total_refunded() {
		return round( array_sum( $this->refunds ), 2 );
	}

	public function get_remaining_refund_amount() {
		return round( (float) $this->get_total() - $this->get_total_refunded(), 2 );
	}
}

if ( ! function_exists( 'wc_create_refund' ) ) {
	function wc_create_refund( $args ) {
		$GLOBALS['test_wc_refunds'][] = $args;
		$order                        = wc_get_order( $args['order_id'] );
		$amount                       = round( (float) $args['amount'], 2 );
		if ( ! $order ) {
			return new WP_Error( 'error', 'Invalid order ID.' );
		}
		if ( 0 > $amount || $amount > $order->get_remaining_refund_amount() ) {
			return new WP_Error( 'error', 'Invalid refund amount.' );
		}
		// $refund->save(): the order now counts this refund.
		$order->refunds[] = $amount;
		$index            = array_key_last( $order->refunds );
		if ( ! empty( $args['refund_payment'] ) ) {
			$result = ( new ExtraChill_Merch_Gateway() )->process_refund( $order->get_id(), (string) $amount, $args['reason'] ?? '' );
			if ( ! $result || is_wp_error( $result ) ) {
				// $refund->delete().
				unset( $order->refunds[ $index ] );
				return new WP_Error( 'error', is_wp_error( $result ) ? $result->get_error_message() : 'Gateway refund failed.' );
			}
		}
		return (object) array( 'id' => 900 + $index );
	}
}
