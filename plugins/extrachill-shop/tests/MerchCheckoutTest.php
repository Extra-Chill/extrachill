<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Direct-charge merch regression tests using the shared Stripe doubles. */
require_once __DIR__ . '/SellerCommerceTest.php';
require_once __DIR__ . '/TicketTest.php';
require_once dirname( __DIR__ ) . '/inc/stripe/refunds.php';

function wp_json_encode( $value ) { return json_encode( $value ); }
function wc_get_cart_url() { return 'https://shop.example/cart'; }

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
require_once dirname( __DIR__ ) . '/inc/stripe/payment-integration.php';

final class MerchCheckoutItem {
	public function get_product_id() { return 10; }
	public function get_total() { return 20; }
	public function get_total_tax() { return 0; }
	public function get_quantity() { return 2; }
	public function get_name() { return 'Shirt'; }
}
final class MerchCheckoutOrder {
	public $meta = array();
	public $paid = 0;
	public function get_id() { return 123; }
	public function get_items() { return array( new MerchCheckoutItem() ); }
	public function get_total() { return 25; }
	public function get_currency() { return 'USD'; }
	public function get_billing_email() { return 'buyer@example.com'; }
	public function get_checkout_order_received_url() { return 'https://shop.example/received?key=secret'; }
	public function get_payment_method() { return 'extrachill_stripe_checkout'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
	public function is_paid() { return $this->paid > 0; }
	public function payment_complete( $intent ) { ++$this->paid; }
	public function get_remaining_refund_amount() { return 25; }
	public function get_total_refunded() { return 0; }
}
final class MerchCheckoutTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		$GLOBALS['test_options']         = array();
		$GLOBALS['test_post_meta']       = array();
		$GLOBALS['ticket_account_ready'] = true;
		$GLOBALS['ticket_refund_fail']   = false;
		$GLOBALS['ticket_refunds']       = array();
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
		), array( 'account_id' => 'acct_merch' ) );
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
	public function test_direct_charge_session_and_refund(): void {
		$order = new MerchCheckoutOrder();
		$this->assertFalse( is_wp_error( extrachill_shop_start_merch_checkout( $order ) ) );
		list( $data, $options ) = $GLOBALS['ticket_session_request'];
		$this->assertSame( 'acct_merch', $options['stripe_account'] );
		$this->assertSame( 200, $data['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( array(
			'kind'     => 'extrachill_merch',
			'order_id' => '123',
		), $data['metadata'] );
		$this->assertSame( 2000, $data['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 500, $data['line_items'][1]['price_data']['unit_amount'] );
		$this->assertArrayNotHasKey( 'transfer_data', $data['payment_intent_data'] );
		$this->assertArrayNotHasKey( 'automatic_tax', $data );
		$order->meta['_extrachill_payment_intent'] = 'pi_merch';
		$this->assertTrue( extrachill_shop_refund_merch_payment( $order, 10, 'Return' ) );
		list( $refund, $options ) = $GLOBALS['ticket_refunds'][0];
		$this->assertSame( 'acct_merch', $options['stripe_account'] );
		$this->assertTrue( $refund['refund_application_fee'] );
		$this->assertSame( 'pi_merch', $refund['payment_intent'] );
	}
	public function test_platform_session_has_no_account_or_fee(): void {
		$GLOBALS['test_post_meta'] = array();
		extrachill_shop_start_merch_checkout( new MerchCheckoutOrder() );
		list( $data, $options ) = $GLOBALS['ticket_session_request'];
		$this->assertArrayNotHasKey( 'stripe_account', $options );
		$this->assertArrayNotHasKey( 'application_fee_amount', $data['payment_intent_data'] );
	}
	public function test_unready_seller_is_refused(): void {
		$GLOBALS['ticket_account_ready'] = false;
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
