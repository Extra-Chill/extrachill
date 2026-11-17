<?php
/**
 * Refund bookkeeping shared by WooCommerce order doubles.
 *
 * @package ExtraChillShop
 */

// phpcs:disable -- Test doubles.
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

	public function get_refunds() {
		return $GLOBALS['test_wc_refund_objects'][ $this->get_id() ] ?? array();
	}
}

