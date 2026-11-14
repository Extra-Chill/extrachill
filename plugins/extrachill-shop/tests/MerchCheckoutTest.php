<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Direct-charge merch regression tests using the shared Stripe doubles. */
require_once __DIR__ . '/fixtures/woo-refunds.php';

function wp_json_encode( $value ) { return json_encode( $value ); }
function wc_get_cart_url() { return 'https://shop.example/cart'; }
require_once dirname( __DIR__ ) . '/inc/stripe/payment-integration.php';

final class MerchCheckoutItem {
	public function get_product_id() { return 10; }
	public function get_total() { return 20; }
	public function get_total_tax() { return 0; }
	public function get_quantity() { return 2; }
	public function get_name() { return 'Shirt'; }
}
final class MerchCheckoutOrder {
	use WooRefundTracking;
	public $meta = array();
	public $paid = 0;
	public function get_id() { return 123; }
	public function get_items() { return array( new MerchCheckoutItem() ); }
	public function get_total() { return 25; }
	public $shipping = 5.0;
	public function get_shipping_total() { return $this->shipping; }
	public function get_shipping_tax() { return 0; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function get_currency() { return 'USD'; }
	public function get_billing_email() { return 'buyer@example.com'; }
	public function get_checkout_order_received_url() { return 'https://shop.example/received?key=secret'; }
	public $status = 'pending';
	public $notes  = array();
	public function get_payment_method() { return 'extrachill_stripe_checkout'; }
	public function get_order_number() { return (string) $this->get_id(); }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
	public function is_paid() { return $this->paid > 0; }
	public function payment_complete( $intent ) { ++$this->paid; $this->status = 'processing'; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
	public function update_status( $status, $note = '' ) { $this->status = $status; $this->notes[] = $note; }
}
final class MerchCheckoutTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		$GLOBALS['test_options']         = array();
		$GLOBALS['test_post_meta']       = array();
		$GLOBALS['stripe_session_ids']        = array();
		$GLOBALS['stripe_session_status']     = array();
		$GLOBALS['stripe_session_expired']    = array();
		$GLOBALS['stripe_session_retrieved']  = array();
		$GLOBALS['stripe_sessions_created']   = array();
		$GLOBALS['stripe_session_retrieve_fail'] = false;
		$GLOBALS['ticket_refund_fail']   = false;
		$GLOBALS['ticket_refunds']       = array();
		$GLOBALS['test_wc_refunds']      = array();
		$GLOBALS['test_orders']          = array();
		$GLOBALS['test_owner_callback']  = static fn() => array(
			'type' => 'venue',
			'id'   => 9911,
			'name' => 'Venue',
		);
		extrachill_shop_set_product_seller( 10, array(
			'type' => 'venue',
			'id'   => 9911,
		) );
		extrachill_shop_set_seller_stripe_account_record( array(
			'type' => 'venue',
			'id'   => 9911,
		), array( 'account_id' => 'acct_merch', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
	}
	public function test_gateway_registers_and_redirects_to_stripe(): void {
		$this->assertContains( 'ExtraChill_Merch_Gateway', extrachill_shop_register_merch_gateway( array() ) );
		$gateway                     = new ExtraChill_Merch_Gateway();
		$GLOBALS['test_orders'][123] = new MerchCheckoutOrder();
		$this->assertSame( array(
			'result'   => 'success',
			'redirect' => 'https://checkout.stripe.com/test',
		), $gateway->process_payment( 123 ) );
		$this->assertContains( 'refunds', $gateway->supports );
	}
	public function test_direct_charge_session(): void {
		$order = new MerchCheckoutOrder();
		$this->assertFalse( is_wp_error( extrachill_shop_start_merch_checkout( $order ) ) );
		list( $data, $options ) = $GLOBALS['ticket_session_request'];
		$this->assertSame( 'acct_merch', $options['stripe_account'] );
		// 10% of the $20 items plus the $5 shipping, which pays for the label.
		$this->assertSame( 700, $data['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( 500, $order->meta['_extrachill_shipping_hold_minor'] );
		$this->assertSame( array(
			'kind'     => 'extrachill_merch',
			'order_id' => '123',
		), $data['metadata'] );
		$this->assertSame( 2000, $data['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 500, $data['line_items'][1]['price_data']['unit_amount'] );
		$this->assertArrayNotHasKey( 'transfer_data', $data['payment_intent_data'] );
		$this->assertArrayNotHasKey( 'automatic_tax', $data );
	}
	/** A paid seller order, registered where wc_get_order() finds it. */
	private function paid_order(): MerchCheckoutOrder {
		$order = new MerchCheckoutOrder();
		extrachill_shop_start_merch_checkout( $order );
		$order->meta['_extrachill_payment_intent'] = 'pi_merch';
		$GLOBALS['test_orders'][123]               = $order;
		return $order;
	}
	public function test_full_refund_succeeds_after_woocommerce_saves_the_refund(): void {
		$order  = $this->paid_order();
		$refund = wc_create_refund( array( 'order_id' => 123, 'amount' => 25, 'reason' => 'Return', 'refund_payment' => true ) );
		$this->assertFalse( is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : '' );
		$this->assertSame( 0.0, $order->get_remaining_refund_amount() );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		list( $params, $options ) = $GLOBALS['ticket_refunds'][0];
		$this->assertSame( 'pi_merch', $params['payment_intent'] );
		$this->assertSame( 2500, $params['amount'] );
		$this->assertTrue( $params['refund_application_fee'] );
		$this->assertSame( 'acct_merch', $options['stripe_account'] );
		$this->assertSame( 'Return', $params['metadata']['reason'] );
	}
	public function test_partial_refunds_succeed_and_over_refund_is_rejected_by_woocommerce(): void {
		$order = $this->paid_order();
		foreach ( array( 10, 10 ) as $amount ) {
			$this->assertFalse( is_wp_error( wc_create_refund( array( 'order_id' => 123, 'amount' => $amount, 'refund_payment' => true ) ) ) );
		}
		$this->assertSame( 5.0, $order->get_remaining_refund_amount() );
		$over = wc_create_refund( array( 'order_id' => 123, 'amount' => 6, 'refund_payment' => true ) );
		$this->assertInstanceOf( WP_Error::class, $over );
		$this->assertSame( 'Invalid refund amount.', $over->get_error_message() );
		$this->assertCount( 2, $GLOBALS['ticket_refunds'] );
		$this->assertFalse( is_wp_error( wc_create_refund( array( 'order_id' => 123, 'amount' => 5, 'refund_payment' => true ) ) ) );
		$this->assertSame( 0.0, $order->get_remaining_refund_amount() );
		$this->assertSame( array( 1000, 1000, 500 ), array_map( static fn( $r ) => $r[0]['amount'], $GLOBALS['ticket_refunds'] ) );
		// Two equal partial refunds are distinct Stripe refunds, not an idempotent replay.
		$keys = array_map( static fn( $r ) => $r[1]['idempotency_key'], $GLOBALS['ticket_refunds'] );
		$this->assertCount( 3, array_unique( $keys ) );
		foreach ( $GLOBALS['ticket_refunds'] as list( $params, $options ) ) {
			$this->assertTrue( $params['refund_application_fee'] );
			$this->assertSame( 'acct_merch', $options['stripe_account'] );
		}
	}
	public function test_platform_product_refund_uses_platform_account(): void {
		$GLOBALS['test_post_meta'] = array();
		$order                     = $this->paid_order();
		$this->assertSame( '', $order->meta['_extrachill_stripe_account'] );
		$this->assertFalse( is_wp_error( wc_create_refund( array( 'order_id' => 123, 'amount' => 25, 'refund_payment' => true ) ) ) );
		list( $params, $options ) = $GLOBALS['ticket_refunds'][0];
		$this->assertSame( 2500, $params['amount'] );
		$this->assertArrayNotHasKey( 'stripe_account', $options );
		$this->assertArrayNotHasKey( 'refund_application_fee', $params );
	}
	public function test_failed_stripe_refund_rolls_back_and_retry_reuses_key(): void {
		$order                         = $this->paid_order();
		$GLOBALS['ticket_refund_fail'] = true;
		$this->assertInstanceOf( WP_Error::class, wc_create_refund( array( 'order_id' => 123, 'amount' => 25, 'refund_payment' => true ) ) );
		$this->assertSame( 25.0, $order->get_remaining_refund_amount() );
		$GLOBALS['ticket_refund_fail'] = false;
		$this->assertFalse( is_wp_error( wc_create_refund( array( 'order_id' => 123, 'amount' => 25, 'refund_payment' => true ) ) ) );
		$this->assertSame( 'ec-merch-refund-123-2500-2500', $GLOBALS['ticket_refunds'][0][1]['idempotency_key'] );
	}
	public function test_refund_without_payment_intent_is_refused(): void {
		$this->assertSame( 'refund_unavailable', extrachill_shop_refund_merch_payment( new MerchCheckoutOrder(), 10 )->get_error_code() );
	}
	public function test_platform_session_has_no_account_or_fee(): void {
		$GLOBALS['test_post_meta'] = array();
		extrachill_shop_start_merch_checkout( new MerchCheckoutOrder() );
		list( $data, $options ) = $GLOBALS['ticket_session_request'];
		$this->assertArrayNotHasKey( 'stripe_account', $options );
		$this->assertArrayNotHasKey( 'application_fee_amount', $data['payment_intent_data'] );
	}
	public function test_unready_seller_is_refused(): void {
		extrachill_shop_update_stripe_account_by_id( 'acct_merch', 'restricted', true );
		$this->assertSame( 'seller_payments_unavailable', extrachill_shop_start_merch_checkout( new MerchCheckoutOrder() )->get_error_code() );
	}
	public function test_webhook_is_account_bound_and_idempotent_on_both_endpoints(): void {
		foreach ( array( 'acct_merch', '' ) as $account ) {
			if ( ! $account ) {
				$GLOBALS['test_post_meta'] = array(); }
			$order                       = new MerchCheckoutOrder();
			$GLOBALS['test_orders'][123] = $order;
			$session                     = extrachill_shop_start_merch_checkout( $order );
			$session->payment_status     = 'paid';
			$session->payment_intent     = 'pi_merch';
			$session->metadata           = (object) array(
				'kind'     => 'extrachill_merch',
				'order_id' => '123',
			);
			$event                       = (object) array(
				'type'    => 'checkout.session.completed',
				'account' => $account,
				'data'    => (object) array( 'object' => $session ),
			);
			$this->assertInstanceOf( WP_Error::class, extrachill_shop_fulfil_merch_session( $session, 'acct_wrong' ) );
			$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
			$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
			$this->assertSame( 1, $order->paid );
			$this->assertSame( 'pi_merch', $order->meta['_extrachill_payment_intent'] );
		}
	}
}
