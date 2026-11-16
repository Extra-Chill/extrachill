<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/**
 * Merch sellers pay card processing (#1563).
 *
 * When Extra Chill pays processing on a seller's direct charges (Express,
 * `controller.fees.payer = application*`), the merch application fee is the
 * commission plus the processing estimate on the whole charge, plus the
 * shipping hold. When the account pays, or the payer is unknown, it is the
 * commission plus the hold. Refunds keep the processing part.
 */
namespace {
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/LabelParcelAndFeeRefundTest.php';

	/** A merch order line with a configurable total. */
	final class MerchProcessingItem {
		public function __construct( private float $total ) {}
		public function get_product_id() { return 10; }
		public function get_total() { return $this->total; }
		public function get_total_tax() { return 0; }
		public function get_quantity() { return 1; }
		public function get_name() { return 'Shirt'; }
	}

	/** A merch order with configurable items and shipping. */
	final class MerchProcessingOrder {
		use WooRefundTracking;
		public $meta  = array();
		public $notes = array();
		public function __construct( private float $items, private float $shipping ) {}
		public function get_id() { return 456; }
		public function get_items( $type = 'line_item' ) { return array( new MerchProcessingItem( $this->items ) ); }
		public function get_total() { return $this->items + $this->shipping; }
		public function get_shipping_total() { return $this->shipping; }
		public function get_shipping_tax() { return 0; }
		public function get_currency() { return 'USD'; }
		public function get_billing_email() { return 'buyer@example.com'; }
		public function get_checkout_order_received_url() { return 'https://shop.example/received'; }
		public function get_payment_method() { return 'extrachill_stripe_checkout'; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
		public function add_order_note( $note ) { $this->notes[] = $note; }
		public function save() {}
	}

	final class MerchProcessingFeeTest extends TestCase {
		private const VENUE = array( 'type' => 'venue', 'id' => 9911 );

		protected function setUp(): void {
			$GLOBALS['test_options']             = array();
			$GLOBALS['test_site_options']        = $GLOBALS['test_blog_options'] = array();
			$GLOBALS['test_post_meta']           = array();
			$GLOBALS['test_orders']              = array();
			$GLOBALS['test_transients']          = array();
			$GLOBALS['test_stripe_status']       = null;
			$GLOBALS['test_stripe_status_calls'] = array();
			$GLOBALS['stripe_session_ids']       = array();
			$GLOBALS['stripe_sessions_created']  = array();
			$GLOBALS['ticket_session_request']   = null;
			$GLOBALS['stripe_fee_calls']         = array();
			$GLOBALS['stripe_fee_fail']          = false;
			$GLOBALS['stripe_fee_refund_fail']   = false;
			$GLOBALS['ticket_refunds']           = array();
			$GLOBALS['ticket_refund_fail']       = false;
			$GLOBALS['test_wc_refunds']          = array();
			$GLOBALS['test_owner_callback']      = static fn() => array( 'type' => 'venue', 'id' => 9911, 'name' => 'Venue' );
			extrachill_shop_set_product_seller( 10, self::VENUE );
			$this->payer( 'application_express' );
		}

		private function payer( string $payer ): void {
			// A save without a payer keeps the stored one, so start clean.
			$GLOBALS['test_options'] = array();
			extrachill_shop_set_seller_stripe_account_record( self::VENUE, array( 'account_id' => 'acct_merch', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00', 'fee_payer' => $payer ) );
		}

		/**
		 * Worked orders at the shipped defaults (10%, 2.9% + 30c).
		 *
		 * [ items, shipping, payer, commission, processing, application fee ]
		 */
		public static function worked_orders(): array {
			return array(
				// $30 charge: 2.9% = 87c, + 30c = $1.17.
				'$25 + $5, platform pays' => array( 2500, 500, 'application_express', 250, 117, 867 ),
				'$25 + $5, account pays'  => array( 2500, 500, 'account', 250, 0, 750 ),
				// $15 charge: 2.9% = 43.5c, rounded up to 44c, + 30c = 74c.
				'$10 + $5, platform pays' => array( 1000, 500, 'application_express', 100, 74, 674 ),
				'$10 + $5, account pays'  => array( 1000, 500, 'account', 100, 0, 600 ),
				// $3 charge: 2.9% = 8.7c, rounded up to 9c, + 30c = 39c.
				'$3 free shipping, platform pays' => array( 300, 0, 'application', 30, 39, 69 ),
				'$3 free shipping, account pays'  => array( 300, 0, 'account', 30, 0, 30 ),
			);
		}

		/** @dataProvider worked_orders */
		public function test_worked_orders( int $items, int $shipping, string $payer, int $commission, int $processing, int $fee ): void {
			$total = $items + $shipping;
			$parts = extrachill_commerce_merch_fee( $items, $total, self::VENUE, $payer, $shipping );
			$this->assertSame( array( $commission, $processing, $shipping, $fee ), array( $parts['commission'], $parts['processing'], $parts['shipping_hold'], $parts['application_fee'] ) );

			// Real processing at 2.9% + 30c, rounded to the nearest cent.
			$real = (int) round( $total * 0.029 ) + 30;
			if ( 'account' === $payer ) {
				// The processor takes processing from the seller: charged once, not twice.
				$seller_items = $total - $fee - $real;
				$extra_chill  = $fee - $shipping;
			} else {
				// The processor takes processing from Extra Chill, out of the fee.
				$seller_items = $total - $fee;
				$extra_chill  = $fee - $shipping - $real;
			}
			// Either way the seller keeps the items minus 10% minus processing on
			// the whole charge (the shipping pays for the label or comes back to
			// them), and Extra Chill keeps its 10%, up to a cent of rounding.
			$this->assertEqualsWithDelta( $items - $commission - $real, $seller_items, 1 );
			$this->assertEqualsWithDelta( $commission, $extra_chill, 1 );
			$this->assertGreaterThanOrEqual( $commission, $extra_chill, 'The estimate rounds up, so Extra Chill is never short.' );
		}

		public function test_unknown_payer_takes_the_commission_only(): void {
			foreach ( array( '', 'something_new' ) as $payer ) {
				$parts = extrachill_commerce_merch_fee( 2500, 3000, self::VENUE, $payer, 500 );
				$this->assertSame( 750, $parts['application_fee'], "payer '$payer'" );
				$this->assertSame( 0, $parts['processing'] );
				$this->assertSame( 'account', $parts['processing_payer'] );
			}
		}

		public function test_fee_is_capped_at_the_charge_total(): void {
			// 20c sticker, free shipping: the 30c fixed estimate alone exceeds the charge.
			$parts = extrachill_commerce_merch_fee( 20, 20, self::VENUE, 'application_express' );
			$this->assertSame( array( 0, 20, 20 ), array( $parts['commission'], $parts['processing'], $parts['application_fee'] ) );
			// With shipping, the hold always fits; processing then commission give way.
			$parts = extrachill_commerce_merch_fee( 10, 25, self::VENUE, 'application_express', 15 );
			$this->assertSame( array( 0, 10, 15, 25 ), array( $parts['commission'], $parts['processing'], $parts['shipping_hold'], $parts['application_fee'] ) );
			// Platform products and empty charges have no fee.
			$this->assertSame( 0, extrachill_commerce_merch_fee( 2500, 3000, null, 'application_express', 500 )['application_fee'] );
			$this->assertSame( 0, extrachill_commerce_merch_fee( 0, 0, self::VENUE, 'application_express' )['application_fee'] );
		}

		public function test_checkout_charges_the_processing_and_records_it(): void {
			$order = new MerchProcessingOrder( 25, 5 );
			$this->assertFalse( is_wp_error( extrachill_shop_start_merch_checkout( $order ) ) );
			$this->assertSame( 867, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
			$this->assertSame( 867, $order->meta['_extrachill_application_fee'] );
			$this->assertSame( 117, $order->meta[ EXTRACHILL_SHOP_PROCESSING_FEE_META ] );
			$this->assertSame( 'platform', $order->meta['_extrachill_processing_payer'] );
			// The label hold is unchanged.
			$this->assertSame( 500, $order->meta[ EXTRACHILL_SHOP_SHIPPING_HOLD_META ] );
			// The seller's order view shows what they actually receive.
			$this->assertSame( 21.33, extrachill_shop_get_order_seller_record( $order )['seller_payout'] );
		}

		public function test_checkout_for_an_account_payer_takes_the_commission_only(): void {
			$this->payer( 'account' );
			$order = new MerchProcessingOrder( 25, 5 );
			extrachill_shop_start_merch_checkout( $order );
			$this->assertSame( 750, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
			$this->assertSame( 0, $order->meta[ EXTRACHILL_SHOP_PROCESSING_FEE_META ] );
			$this->assertSame( 'account', $order->meta['_extrachill_processing_payer'] );
			$this->assertSame( 500, $order->meta[ EXTRACHILL_SHOP_SHIPPING_HOLD_META ] );
		}

		public function test_free_shipping_order_has_no_hold(): void {
			$order = new MerchProcessingOrder( 3, 0 );
			extrachill_shop_start_merch_checkout( $order );
			$this->assertSame( 69, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
			$this->assertSame( 0, $order->meta[ EXTRACHILL_SHOP_SHIPPING_HOLD_META ] );
		}

		public function test_unknown_payer_is_looked_up_once_at_checkout_and_saved(): void {
			$this->payer( '' );
			$GLOBALS['test_stripe_status'] = array( 'success' => true, 'status' => 'active', 'can_receive_payments' => true, 'fee_payer' => 'application_express' );
			extrachill_shop_start_merch_checkout( new MerchProcessingOrder( 25, 5 ) );
			$this->assertSame( 867, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
			$this->assertSame( array( 'acct_merch' ), $GLOBALS['test_stripe_status_calls'] );
			$this->assertSame( 'application_express', extrachill_shop_get_seller_stripe_account_record( self::VENUE )['fee_payer'] );
			extrachill_shop_start_merch_checkout( new MerchProcessingOrder( 25, 5 ) );
			$this->assertCount( 1, $GLOBALS['test_stripe_status_calls'] );
		}

		public function test_failed_payer_lookup_falls_back_to_the_commission_only(): void {
			$this->payer( '' );
			extrachill_shop_start_merch_checkout( new MerchProcessingOrder( 25, 5 ) );
			$this->assertSame( 750, $GLOBALS['ticket_session_request'][0]['payment_intent_data']['application_fee_amount'] );
			extrachill_shop_start_merch_checkout( new MerchProcessingOrder( 25, 5 ) );
			$this->assertCount( 1, $GLOBALS['test_stripe_status_calls'], 'A failed lookup is not retried within the TTL.' );
		}

		// ─── Refunds ──────────────────────────────────────────────────────

		private function paid_order( bool $label = false ): MerchProcessingOrder {
			$order = new MerchProcessingOrder( 25, 5 );
			extrachill_shop_start_merch_checkout( $order );
			$order->meta['_extrachill_payment_intent'] = 'pi_merch';
			if ( $label ) {
				$order->meta['_extrachill_shipment'] = array( 'label_url' => 'https://label', 'cost' => 4.35, 'shipping_held' => 5.0 );
			}
			$GLOBALS['test_orders'][456] = $order;
			$GLOBALS['stripe_fee']       = array( 'id' => 'fee_1', 'amount' => (int) $order->meta['_extrachill_application_fee'], 'amount_refunded' => 0 );
			return $order;
		}

		private function refund( float $amount ) {
			return wc_create_refund( array( 'order_id' => 456, 'amount' => $amount, 'refund_payment' => true ) );
		}

		private function fee_refunds(): array {
			return array_map(
				static fn( $call ) => $call[2]['amount'],
				array_values( array_filter( $GLOBALS['stripe_fee_calls'], static fn( $call ) => 'fee.refund' === $call[0] ) )
			);
		}

		public function test_full_refund_keeps_the_processing(): void {
			$this->paid_order();
			$this->assertFalse( is_wp_error( $this->refund( 30 ) ) );
			// Commission $2.50 + unspent shipping $5.00; the $1.17 processing stays.
			$this->assertSame( array( 750 ), $this->fee_refunds() );
			$this->assertSame( 117, $GLOBALS['stripe_fee']['amount'] - $GLOBALS['stripe_fee']['amount_refunded'] );
		}

		public function test_full_refund_after_a_label_keeps_the_hold_and_the_processing(): void {
			$this->paid_order( true );
			$this->refund( 30 );
			$this->assertSame( array( 250 ), $this->fee_refunds() );
		}

		public function test_partial_refunds_prorate_only_the_commission(): void {
			$this->paid_order();
			$this->refund( 15 );
			// Half the charge: half the $2.50 commission.
			$this->assertSame( array( 125 ), $this->fee_refunds() );
			$this->refund( 15 );
			$this->assertSame( array( 125, 625 ), $this->fee_refunds() );
			$this->assertSame( 117, $GLOBALS['stripe_fee']['amount'] - $GLOBALS['stripe_fee']['amount_refunded'] );
		}

		public function test_shipping_release_then_refund_still_keeps_the_processing(): void {
			$order = $this->paid_order();
			$this->assertSame( 500, extrachill_shop_release_shipping_hold( $order ) );
			$this->refund( 30 );
			$this->assertSame( array( 500, 250 ), $this->fee_refunds() );
			$this->assertSame( 117, $GLOBALS['stripe_fee']['amount'] - $GLOBALS['stripe_fee']['amount_refunded'] );
		}

		public function test_account_payer_refund_returns_the_whole_commission_as_before(): void {
			$this->payer( 'account' );
			$this->paid_order();
			$this->refund( 30 );
			$this->assertSame( array( 750 ), $this->fee_refunds() );
		}

		public function test_tickets_are_unchanged(): void {
			// #1553 / #1560 worked examples at the shared estimate.
			$this->assertSame( array( 'buyer_fee' => 194, 'application_fee' => 100 ), extrachill_commerce_fee( 'extrachill_ticket', 2000, self::VENUE, 1, 'account' ) );
			$this->assertSame( array( 'buyer_fee' => 194, 'application_fee' => 194 ), extrachill_commerce_fee( 'extrachill_ticket', 2000, self::VENUE, 1, 'application_express' ) );
			$this->assertSame( array( 'buyer_fee' => 682, 'application_fee' => 682 ), extrachill_commerce_fee( 'extrachill_ticket', 8000, self::VENUE, 4, 'application_express' ) );
		}
	}
}
