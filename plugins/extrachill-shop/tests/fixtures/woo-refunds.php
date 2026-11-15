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
require_once __DIR__ . '/class-stripe-application-fee.php';
require_once dirname( __DIR__, 2 ) . '/inc/stripe/refunds.php';

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public $id, $method_title, $method_description, $title, $description, $has_fields, $enabled, $supports;
		public $form_fields = array();
		public $settings    = array();
		public function init_settings() {
			$this->settings = get_option( 'woocommerce_' . $this->id . '_settings', null );
			if ( ! is_array( $this->settings ) ) {
				$this->settings = array_map( static fn( $field ) => $field['default'] ?? '', $this->form_fields );
			}
		}
		public function get_option( $key, $empty_value = null ) {
			$value = $this->settings[ $key ] ?? ( $this->form_fields[ $key ]['default'] ?? '' );
			return ( '' === $value && null !== $empty_value ) ? $empty_value : $value;
		}
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

	public function get_refunds() {
		return $GLOBALS['test_wc_refund_objects'][ $this->get_id() ] ?? array();
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
		$refund = new TestWcRefund( 900 + $index, $amount );
		// Saved WooCommerce refunds, as WC_Order::get_refunds() returns them.
		$GLOBALS['test_wc_refund_objects'][ $order->get_id() ][] = $refund;
		$GLOBALS['test_orders'][ $refund->get_id() ]              = $refund;
		// do_action( 'woocommerce_refund_created' ).
		extrachill_shop_tag_refund_with_stripe_id( $refund->get_id() );
		return $refund;
	}
}

/** A saved WooCommerce refund row. */
final class TestWcRefund {
	public $meta  = array();
	public $saves = 0;
	public function __construct( private int $id, public float $amount ) {}
	public function get_id() { return $this->id; }
	public function get_amount() { return $this->amount; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { ++$this->saves; return $this->id; }
}
