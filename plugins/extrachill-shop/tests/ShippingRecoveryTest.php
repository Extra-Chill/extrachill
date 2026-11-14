<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/**
 * Shipping recovery (#1403): the buyer's shipping is taken in the application
 * fee and pays for an Extra Chill label, or goes back to a seller who ships
 * the order themselves.
 */
namespace Stripe {
	class PaymentIntent {
		public static function retrieve( $params, $options = null ) {
			if ( ! empty( $GLOBALS['stripe_fee_fail'] ) ) { throw new \RuntimeException( 'Stripe unavailable' ); }
			$GLOBALS['stripe_fee_calls'][] = array( 'pi.retrieve', $params, $options );
			return (object) array( 'id' => $params['id'], 'latest_charge' => (object) array( 'id' => 'ch_merch', 'application_fee' => $GLOBALS['stripe_fee']['id'] ?? null ) );
		}
	}
	class ApplicationFee {
		public static function retrieve( $id, $options = null ) {
			$GLOBALS['stripe_fee_calls'][] = array( 'fee.retrieve', $id, $options );
			return (object) $GLOBALS['stripe_fee'];
		}
		public static function createRefund( $id, $params = null, $options = null ) {
			$GLOBALS['stripe_fee_calls'][] = array( 'fee.refund', $id, $params, $options );
			return (object) array( 'id' => 'fr_1', 'amount' => $params['amount'] );
		}
	}
}
namespace {
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/MerchCheckoutTest.php';
	require_once __DIR__ . '/SellerOrderNotificationsTest.php';
	require_once __DIR__ . '/SellerListingTest.php';
	require_once __DIR__ . '/ShippingAddressTest.php';
	require_once __DIR__ . '/ShopManagerTest.php';

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) { return '2026-11-20 12:00:00'; }
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ) { return (string) $url; }
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() { return (object) array( 'display_name' => 'Seller' ); }
	}

	/** A paid venue order with everything the label and status abilities read. */
	final class ShippingRecoveryOrder {
		public $meta   = array();
		public $notes  = array();
		public $status = 'processing';
		public $saved  = 0;
		public function get_id() { return 321; }
		public function get_order_number() { return '321'; }
		public function get_meta( $key, $single = true ) {
			if ( '_extrachill_order_seller' === $key && ! $single ) {
				return array( (object) array( 'value' => 'venue:9911' ) );
			}
			return $this->meta[ $key ] ?? '';
		}
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function add_order_note( $note ) { $this->notes[] = $note; }
		public function set_status( $status, $note = '' ) { $this->status = $status; $this->notes[] = $note; }
		public function get_status() { return $this->status; }
		public function save() { ++$this->saved; }
		public function get_items( $type = 'line_item' ) { return array(); }
		public function get_address( $type ) { return array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '456 Destination', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US' ); }
		public function get_date_created() { return null; }
		public function get_billing_first_name() { return 'Test'; }
		public function get_billing_last_name() { return 'Buyer'; }
		public function get_billing_email() { return 'buyer@example.com'; }
	}

	final class ShippingRecoveryTest extends TestCase {
		private const VENUE = array( 'type' => 'venue', 'id' => 9911 );

		protected function setUp(): void {
			$GLOBALS['test_options']        = array();
			$GLOBALS['test_site_options']   = $GLOBALS['test_blog_options'] = array();
			$GLOBALS['test_orders']         = array();
			$GLOBALS['stripe_fee_calls']    = array();
			$GLOBALS['stripe_fee_fail']     = false;
			$GLOBALS['stripe_fee']          = array( 'id' => 'fee_1', 'amount' => 700, 'amount_refunded' => 0 );
			$GLOBALS['shippo_label_result'] = null;
			$GLOBALS['shippo_labels_bought'] = 0;
			$GLOBALS['notification_mail']   = array();
			$GLOBALS['test_user_id']        = 812;
			$GLOBALS['test_owner_callback'] = static fn() => array( 'type' => 'venue', 'id' => 9911, 'name' => 'Venue', 'can_manage_finances' => true );
			extrachill_shop_set_product_seller( 10, self::VENUE );
			extrachill_shop_set_seller_stripe_account_record( self::VENUE, array( 'account_id' => 'acct_merch', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
			extrachill_shop_set_seller_shipping_address( self::VENUE, array( 'name' => 'Venue', 'street1' => '1 Main', 'street2' => '', 'city' => 'Charleston', 'state' => 'SC', 'zip' => '29401', 'country' => 'US' ) );
		}

		/** A paid order holding $5 of shipping in a $7 application fee. */
		private function paid_order( int $hold = 500 ): ShippingRecoveryOrder {
			$order       = new ShippingRecoveryOrder();
			$order->meta = array(
				'_extrachill_stripe_account'      => 'acct_merch',
				'_extrachill_payment_intent'      => 'pi_merch',
				'_extrachill_shipping_hold_minor' => $hold,
				'_seller_payouts'                 => array( 'venue:9911' => array( 'items' => array( array( 'product_id' => 10, 'quantity' => 1 ) ) ) ),
			);
			$GLOBALS['test_orders'][321] = $order;
			return $order;
		}

		private function fee_refunds(): array {
			return array_values( array_filter( $GLOBALS['stripe_fee_calls'], static fn( $call ) => 'fee.refund' === $call[0] ) );
		}

		private function mark_shipped( array $extra = array() ) {
			return extrachill_shop_ability_update_order_status( array_merge( array( 'id' => 321, 'seller' => self::VENUE, 'status' => 'completed' ), $extra ) );
		}

		public function test_ships_free_order_holds_nothing(): void {
			$order           = new MerchCheckoutOrder();
			$order->shipping = 0.0;
			$order->meta     = array();
			// Total stays $25, so the $5 left is order fees, not shipping.
			extrachill_shop_start_merch_checkout( $order );
			$this->assertSame( 200, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
			$this->assertSame( 0, $order->meta['_extrachill_shipping_hold_minor'] );
		}

		public function test_checkout_payout_record_shows_the_held_shipping(): void {
			$order = new MerchCheckoutOrder();
			extrachill_shop_start_merch_checkout( $order );
			$payout = $order->meta['_seller_payouts']['venue:9911'];
			$this->assertEquals( 7.0, $payout['application_fee'] );
			$this->assertEquals( 5.0, $payout['shipping_held'] );
			$this->assertEquals( 18.0, $payout['artist_payout'] );
		}

		public function test_platform_product_holds_nothing_and_has_no_fee(): void {
			$GLOBALS['test_post_meta'] = array(); // No seller: an Extra Chill product.
			$order = new MerchCheckoutOrder();
			extrachill_shop_start_merch_checkout( $order );
			$this->assertArrayNotHasKey( 'application_fee_amount', $GLOBALS['ticket_session_request'][0]['payment_intent_data'] );
			$this->assertSame( 0, $order->meta['_extrachill_shipping_hold_minor'] );
		}

		public function test_shipping_without_a_label_returns_the_shipping_to_the_seller(): void {
			$order  = $this->paid_order();
			$result = $this->mark_shipped( array( 'tracking_number' => '9400' ) );
			$this->assertFalse( is_wp_error( $result ) );
			$this->assertSame( 'completed', $order->status );
			$refunds = $this->fee_refunds();
			$this->assertCount( 1, $refunds );
			$this->assertSame( 'fee_1', $refunds[0][1] );
			$this->assertSame( 500, $refunds[0][2]['amount'] );
			$this->assertSame( array( 'idempotency_key' => 'ec-shipping-release-321' ), $refunds[0][3] );
			// The charge is read on the seller's account; the fee lives on the platform.
			$this->assertSame( array( 'stripe_account' => 'acct_merch' ), $GLOBALS['stripe_fee_calls'][0][2] );
			$this->assertNull( $GLOBALS['stripe_fee_calls'][1][2] );
			$this->assertSame( 500, $order->meta['_extrachill_shipping_released_minor'] );
			$this->assertStringContainsString( '$5.00 shipping returned', implode( ' ', $order->notes ) );
		}

		public function test_release_happens_once(): void {
			$order = $this->paid_order();
			$this->mark_shipped();
			$this->mark_shipped( array( 'tracking_number' => '9400' ) );
			$this->assertCount( 1, $this->fee_refunds() );
			$this->assertSame( 0, extrachill_shop_order_shipping_hold( $order ) );
		}

		public function test_partly_refunded_order_returns_at_most_the_unrefunded_fee(): void {
			$this->paid_order();
			$GLOBALS['stripe_fee']['amount_refunded'] = 400;
			$this->mark_shipped();
			$this->assertSame( 300, $this->fee_refunds()[0][2]['amount'] );
		}

		public function test_fully_refunded_order_returns_nothing_more(): void {
			$order                                    = $this->paid_order();
			$GLOBALS['stripe_fee']['amount_refunded'] = 700;
			$this->mark_shipped();
			$this->assertSame( array(), $this->fee_refunds() );
			$this->assertSame( 0, $order->meta['_extrachill_shipping_released_minor'] );
		}

		public function test_stripe_failure_leaves_the_order_unshipped_for_a_retry(): void {
			$order                      = $this->paid_order();
			$GLOBALS['stripe_fee_fail'] = true;
			$result                     = $this->mark_shipped();
			$this->assertSame( 'shipping_release_failed', $result->get_error_code() );
			$this->assertSame( 'processing', $order->status );
			$this->assertSame( 0, $order->saved );
			$this->assertArrayNotHasKey( '_extrachill_shipping_released_minor', $order->meta );

			$GLOBALS['stripe_fee_fail'] = false;
			$this->assertFalse( is_wp_error( $this->mark_shipped() ) );
			$this->assertCount( 1, $this->fee_refunds() );
		}

		public function test_order_without_a_hold_makes_no_stripe_call(): void {
			$this->paid_order( 0 );
			$this->mark_shipped();
			$this->assertSame( array(), $GLOBALS['stripe_fee_calls'] );
		}

		public function test_extra_chill_label_keeps_the_shipping(): void {
			$order                          = $this->paid_order();
			$GLOBALS['shippo_label_result'] = array( 'tracking_number' => '9400', 'label_url' => 'https://label', 'tracking_url' => '', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'cost' => 4.35, 'rate_id' => 'r', 'transaction_id' => 't' );
			$result                         = extrachill_shop_ability_create_shipping_label( array( 'order_id' => 321, 'seller' => self::VENUE ) );
			$this->assertSame( '9400', $result['tracking_number'] );
			$this->assertEquals( 5.0, $order->meta['_seller_label_data_venue:9911']['shipping_held'] );
			$this->assertStringContainsString( 'Label $4.35, covered by $5.00 shipping charged', implode( ' ', $order->notes ) );

			// Marking the labelled order shipped afterwards keeps the shipping with the platform.
			$this->mark_shipped();
			$this->assertSame( array(), $GLOBALS['stripe_fee_calls'] );
			$this->assertSame( 500, extrachill_shop_order_shipping_hold( $order ) );
		}

		public function test_no_extra_chill_label_after_the_shipping_was_returned(): void {
			$this->paid_order();
			$this->mark_shipped();
			$result = extrachill_shop_ability_create_shipping_label( array( 'order_id' => 321, 'seller' => self::VENUE ) );
			$this->assertSame( 'shipping_released', $result->get_error_code() );
			$this->assertSame( 0, $GLOBALS['shippo_labels_bought'] );
		}
	}
}
