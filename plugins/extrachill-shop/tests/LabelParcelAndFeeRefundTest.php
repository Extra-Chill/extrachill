<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/**
 * Label parcels, label cost against the shipping hold, and application fee
 * refunds that keep shipping already spent on a label (#1441).
 */
namespace {
	use PHPUnit\Framework\TestCase;

	// OrderSellerRecordTest pins its own current_time() double; load it first.
	require_once __DIR__ . '/OrderSellerRecordTest.php';
	require_once __DIR__ . '/ShippingRecoveryTest.php';

	/** An order line with a configurable product and quantity. */
	final class LabelParcelItem {
		public function __construct( private int $product_id, private int $quantity ) {}
		public function get_product_id() { return $this->product_id; }
		public function get_variation_id() { return 0; }
		public function get_quantity() { return $this->quantity; }
		public function get_total() { return 10; }
	}

	/** An order holding only line items, for parcel defaults. */
	final class LabelParcelOrder {
		public function __construct( private array $items ) {}
		public function get_items( $type = 'line_item' ) { return $this->items; }
	}

	final class LabelParcelAndFeeRefundTest extends TestCase {
		private const VENUE = array( 'type' => 'venue', 'id' => 9911 );

		protected function setUp(): void {
			$GLOBALS['test_options']            = array();
			$GLOBALS['test_site_options']       = $GLOBALS['test_blog_options'] = array();
			$GLOBALS['test_post_meta']          = array();
			$GLOBALS['test_orders']             = array();
			$GLOBALS['test_product_dims']       = array();
			$GLOBALS['operator_caps']           = array();
			$GLOBALS['stripe_fee_calls']        = array();
			$GLOBALS['stripe_fee_fail']         = false;
			$GLOBALS['stripe_fee_refund_fail']  = false;
			$GLOBALS['stripe_fee']              = array( 'id' => 'fee_1', 'amount' => 700, 'amount_refunded' => 0 );
			$GLOBALS['ticket_refunds']          = array();
			$GLOBALS['ticket_refund_fail']      = false;
			$GLOBALS['test_wc_refunds']         = array();
			$GLOBALS['shippo_label_result']     = null;
			$GLOBALS['shippo_quote_cost']       = null;
			$GLOBALS['shippo_labels_bought']    = 0;
			$GLOBALS['shipping_parcel']         = null;
			$GLOBALS['notification_mail']       = array();
			$GLOBALS['test_user_id']            = 812;
			$GLOBALS['test_owner_callback']     = static fn() => array( 'type' => 'venue', 'id' => 9911, 'name' => 'Venue', 'can_manage_finances' => true );
			extrachill_shop_set_product_seller( 10, self::VENUE );
			extrachill_shop_set_seller_stripe_account_record( self::VENUE, array( 'account_id' => 'acct_merch', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
			extrachill_shop_set_seller_shipping_address( self::VENUE, array( 'name' => 'Venue', 'street1' => '1 Main', 'street2' => '', 'city' => 'Charleston', 'state' => 'SC', 'zip' => '29401', 'country' => 'US' ) );
		}

		protected function tearDown(): void {
			unset( $GLOBALS['shippo_quote_cost'] );
			$GLOBALS['operator_caps'] = array();
		}

		// ─── Parcel defaults ──────────────────────────────────────────────

		public function test_parcel_defaults_to_the_standard_box_without_product_data(): void {
			$parcel = extrachill_shop_order_default_parcel( new LabelParcelOrder( array( new LabelParcelItem( 10, 1 ) ) ) );
			$this->assertSame( extrachill_shop_get_default_parcel(), $parcel );
			$this->assertSame( 16.0, $parcel['weight'] );
			$this->assertSame( 'oz', $parcel['mass_unit'] );
		}

		public function test_parcel_sums_weights_and_takes_the_largest_dimensions(): void {
			$GLOBALS['test_options']['woocommerce_weight_unit']    = 'lbs';
			$GLOBALS['test_options']['woocommerce_dimension_unit'] = 'in';
			$GLOBALS['test_product_dims'] = array(
				10 => array( 'weight' => '0.5', 'length' => '12', 'width' => '9', 'height' => '1' ),
				11 => array( 'weight' => '0.25', 'length' => '6', 'width' => '6', 'height' => '6' ),
				12 => array(), // No data: adds nothing.
			);
			$parcel = extrachill_shop_order_default_parcel(
				new LabelParcelOrder( array( new LabelParcelItem( 10, 2 ), new LabelParcelItem( 11, 1 ), new LabelParcelItem( 12, 3 ) ) )
			);
			$this->assertSame( 20.0, $parcel['weight'] ); // (0.5 × 2 + 0.25) lb.
			$this->assertSame( array( 12.0, 9.0, 6.0 ), array( $parcel['length'], $parcel['width'], $parcel['height'] ) );
		}

		public function test_parcel_converts_metric_store_units(): void {
			// WooCommerce defaults: kg and cm.
			$GLOBALS['test_product_dims'] = array( 10 => array( 'weight' => '0.2', 'length' => '30', 'width' => '20', 'height' => '5' ) );
			$parcel = extrachill_shop_order_default_parcel( new LabelParcelOrder( array( new LabelParcelItem( 10, 1 ) ) ) );
			$this->assertSame( 7.1, $parcel['weight'] );
			$this->assertSame( array( 11.9, 7.9, 2.0 ), array( $parcel['length'], $parcel['width'], $parcel['height'] ) );
		}

		public function test_parcel_weight_only_keeps_the_default_box(): void {
			$GLOBALS['test_options']['woocommerce_weight_unit'] = 'oz';
			$GLOBALS['test_product_dims'] = array( 10 => array( 'weight' => '40' ) );
			$parcel = extrachill_shop_order_default_parcel( new LabelParcelOrder( array( new LabelParcelItem( 10, 1 ) ) ) );
			$this->assertSame( 40.0, $parcel['weight'] );
			$this->assertSame( array( 10.0, 8.0, 4.0 ), array( $parcel['length'], $parcel['width'], $parcel['height'] ) );
		}

		public function test_entered_parcels_are_validated(): void {
			$ok = extrachill_shop_normalize_parcel( array( 'weight' => '2.5', 'mass_unit' => 'lb', 'length' => 14, 'width' => 10, 'height' => 3 ) );
			$this->assertSame( array( 'weight' => 2.5, 'mass_unit' => 'lb', 'distance_unit' => 'in', 'length' => 14.0, 'width' => 10.0, 'height' => 3.0 ), $ok );
			foreach ( array(
				array( 'weight' => 0, 'mass_unit' => 'oz', 'length' => 1, 'width' => 1, 'height' => 1 ),
				array( 'weight' => 1, 'mass_unit' => 'kg', 'length' => 1, 'width' => 1, 'height' => 1 ),
				array( 'weight' => 71, 'mass_unit' => 'lb', 'length' => 1, 'width' => 1, 'height' => 1 ),
				array( 'weight' => 1, 'mass_unit' => 'oz', 'length' => 200, 'width' => 1, 'height' => 1 ),
				array( 'weight' => 1, 'mass_unit' => 'oz', 'length' => 5, 'width' => 5 ),
				'heavy',
			) as $bad ) {
				$this->assertSame( 'invalid_parcel', extrachill_shop_normalize_parcel( $bad )->get_error_code() );
			}
		}

		// ─── Label purchase ───────────────────────────────────────────────

		private function paid_order( int $hold = 500 ): ShippingRecoveryOrder {
			$order       = new ShippingRecoveryOrder();
			$order->meta = array(
				'_extrachill_stripe_account'      => 'acct_merch',
				'_extrachill_payment_intent'      => 'pi_merch',
				'_extrachill_shipping_hold_minor' => $hold,
				'_extrachill_seller'              => 'venue:9911',
				'_extrachill_application_fee'     => 200 + $hold,
			);
			$GLOBALS['test_orders'][321] = $order;
			return $order;
		}

		private function label( float $cost ): array {
			return array( 'tracking_number' => '9400', 'label_url' => 'https://label', 'tracking_url' => '', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'cost' => $cost, 'rate_id' => 'rate_test', 'transaction_id' => 't' );
		}

		private function buy( array $extra = array() ) {
			return extrachill_shop_ability_create_shipping_label( array_merge( array( 'order_id' => 321, 'seller' => self::VENUE ), $extra ) );
		}

		public function test_custom_parcel_is_sent_with_the_label_request_and_recorded(): void {
			$order                          = $this->paid_order();
			$GLOBALS['shippo_label_result'] = $this->label( 4.80 );
			$result = $this->buy( array( 'parcel' => array( 'weight' => 2.5, 'mass_unit' => 'lb', 'length' => 14, 'width' => 10, 'height' => 3 ) ) );
			$this->assertSame( '9400', $result['tracking_number'] );
			$this->assertSame(
				array( 'length' => '14', 'width' => '10', 'height' => '3', 'distance_unit' => 'in', 'weight' => '2.5', 'mass_unit' => 'lb' ),
				$GLOBALS['shipping_parcel']
			);
			$shipment = $order->meta['_extrachill_shipment'];
			$this->assertSame( 2.5, $shipment['parcel']['weight'] );
			$this->assertSame( 4.8, $shipment['cost'] );
			$this->assertEquals( 5.0, $shipment['shipping_held'] );
			$this->assertEquals( 0.0, $shipment['overage'] );
		}

		public function test_label_without_a_parcel_uses_the_product_defaults(): void {
			$this->paid_order();
			$GLOBALS['test_options']['woocommerce_weight_unit']    = 'oz';
			$GLOBALS['test_options']['woocommerce_dimension_unit'] = 'in';
			$GLOBALS['test_product_dims'] = array( 10 => array( 'weight' => '12', 'length' => '11', 'width' => '9', 'height' => '2' ) );
			$GLOBALS['shippo_label_result'] = $this->label( 4.35 );
			$this->buy();
			$this->assertSame(
				array( 'length' => '11', 'width' => '9', 'height' => '2', 'distance_unit' => 'in', 'weight' => '12', 'mass_unit' => 'oz' ),
				$GLOBALS['shipping_parcel']
			);
		}

		public function test_order_view_prefills_the_parcel_and_shows_the_hold(): void {
			$order = $this->paid_order();
			$view  = extrachill_shop_ability_build_order_response( $order );
			$this->assertSame( extrachill_shop_get_default_parcel(), $view['label_parcel'] );
			$this->assertSame( 5.0, $view['shipping_held'] );
			$this->assertSame( 0.0, $view['label_cost'] );
		}

		public function test_quote_prices_the_label_against_the_hold_without_buying(): void {
			$this->paid_order();
			$GLOBALS['shippo_quote_cost'] = 4.35;
			$quote = $this->buy( array( 'quote' => true ) );
			$this->assertTrue( $quote['quote'] );
			$this->assertSame( 4.35, $quote['cost'] );
			$this->assertSame( 5.0, $quote['shipping_held'] );
			$this->assertFalse( $quote['exceeds_shipping'] );
			$this->assertTrue( $quote['can_purchase'] );
			$this->assertFalse( $quote['requires_confirmation'] );
			$this->assertSame( 0, $GLOBALS['shippo_labels_bought'] );
		}

		public function test_label_above_the_hold_is_blocked_for_sellers(): void {
			$order                          = $this->paid_order();
			$GLOBALS['shippo_quote_cost']   = 7.80;
			$GLOBALS['shippo_label_result'] = $this->label( 7.80 );

			$quote = $this->buy( array( 'quote' => true ) );
			$this->assertTrue( $quote['exceeds_shipping'] );
			$this->assertFalse( $quote['can_purchase'] );

			// Confirming does not help a seller.
			$result = $this->buy( array( 'confirm_overage' => true ) );
			$this->assertSame( 'label_exceeds_shipping', $result->get_error_code() );
			$this->assertStringContainsString( 'Ship the order yourself', $result->get_error_message() );
			$this->assertSame( 7.8, $result->get_error_data()['cost'] );
			$this->assertSame( 0, $GLOBALS['shippo_labels_bought'] );
			$this->assertArrayNotHasKey( '_extrachill_shipment', $order->meta );
			$this->assertSame( 'processing', $order->status );
		}

		public function test_shop_manager_can_buy_above_the_hold_after_confirming(): void {
			$order                          = $this->paid_order();
			$GLOBALS['operator_caps']       = array( 'manage_woocommerce' );
			$GLOBALS['shippo_quote_cost']   = 7.80;
			$GLOBALS['shippo_label_result'] = $this->label( 7.80 );

			$quote = $this->buy( array( 'quote' => true ) );
			$this->assertTrue( $quote['can_purchase'] );
			$this->assertTrue( $quote['requires_confirmation'] );

			$this->assertSame( 'label_overage_unconfirmed', $this->buy()->get_error_code() );
			$this->assertSame( 0, $GLOBALS['shippo_labels_bought'] );

			$result = $this->buy( array( 'confirm_overage' => true ) );
			$this->assertSame( '9400', $result['tracking_number'] );
			$this->assertSame( 1, $GLOBALS['shippo_labels_bought'] );
			$this->assertEquals( 2.8, $order->meta['_extrachill_shipment']['overage'] );
			$this->assertStringContainsString( 'Extra Chill pays the $2.80 difference', implode( ' ', $order->notes ) );
		}

		// ─── Refunds ──────────────────────────────────────────────────────

		/** A paid $25 seller order: $2 commission + $5 shipping hold = $7 application fee. */
		private function refundable_order( bool $label = false ): MerchCheckoutOrder {
			$order = new MerchCheckoutOrder();
			extrachill_shop_start_merch_checkout( $order );
			$order->meta['_extrachill_payment_intent'] = 'pi_merch';
			if ( $label ) {
				$order->meta['_extrachill_shipment'] = array( 'label_url' => 'https://label', 'cost' => 4.35, 'shipping_held' => 5.0 );
			}
			$GLOBALS['test_orders'][123] = $order;
			return $order;
		}

		private function refund( float $amount ) {
			return wc_create_refund( array( 'order_id' => 123, 'amount' => $amount, 'refund_payment' => true ) );
		}

		private function fee_refunds(): array {
			return array_map(
				static fn( $call ) => $call[2]['amount'],
				array_values( array_filter( $GLOBALS['stripe_fee_calls'], static fn( $call ) => 'fee.refund' === $call[0] ) )
			);
		}

		public function test_full_refund_without_a_label_refunds_the_whole_fee(): void {
			$order = $this->refundable_order();
			$this->assertSame( 700, $order->meta['_extrachill_application_fee'] );
			$this->assertFalse( is_wp_error( $this->refund( 25 ) ) );
			$this->assertFalse( $GLOBALS['ticket_refunds'][0][0]['refund_application_fee'] );
			$this->assertSame( array( 700 ), $this->fee_refunds() );
			$this->assertSame( 700, $GLOBALS['stripe_fee']['amount_refunded'] );
			// The charge is read on the seller's account; the fee is refunded on the platform.
			$this->assertSame( array( 'stripe_account' => 'acct_merch' ), $GLOBALS['stripe_fee_calls'][0][2] );
			$this->assertNull( end( $GLOBALS['stripe_fee_calls'] )[3]['stripe_account'] ?? null );
		}

		public function test_full_refund_after_a_label_keeps_the_shipping_hold(): void {
			$this->refundable_order( true );
			$this->assertFalse( is_wp_error( $this->refund( 25 ) ) );
			$this->assertSame( array( 200 ), $this->fee_refunds() );
		}

		public function test_partial_refunds_prorate_only_the_commission(): void {
			$this->refundable_order();
			$this->refund( 10 );
			// 40% of the charge refunded: 40% of the $2 commission, none of the unspent shipping yet.
			$this->assertSame( array( 80 ), $this->fee_refunds() );
			$this->refund( 15 );
			// Fully refunded with no label bought: the rest of the fee, shipping included.
			$this->assertSame( array( 80, 620 ), $this->fee_refunds() );
		}

		public function test_partial_refunds_after_a_label_never_return_the_hold(): void {
			$this->refundable_order( true );
			$this->refund( 10 );
			$this->refund( 15 );
			$this->assertSame( array( 80, 120 ), $this->fee_refunds() );
			$this->assertSame( 200, $GLOBALS['stripe_fee']['amount_refunded'] );
		}

		public function test_refund_after_shipping_was_returned_to_the_seller_returns_only_commission(): void {
			$order = $this->refundable_order();
			$this->assertSame( 500, extrachill_shop_release_shipping_hold( $order ) );
			$this->refund( 25 );
			$this->assertSame( array( 500, 200 ), $this->fee_refunds() );
		}

		public function test_fee_refund_tops_up_and_never_repeats(): void {
			$order = $this->refundable_order();
			$this->refund( 25 );
			$this->assertSame( 0, extrachill_shop_refund_merch_application_fee( $order ) );
			$this->assertSame( array( 700 ), $this->fee_refunds() );
		}

		public function test_failed_fee_refund_keeps_the_buyer_refund_and_notes_it(): void {
			$order                             = $this->refundable_order();
			$GLOBALS['stripe_fee_refund_fail'] = true;
			$this->assertFalse( is_wp_error( $this->refund( 25 ) ) );
			$this->assertSame( 0.0, $order->get_remaining_refund_amount() );
			$this->assertStringContainsString( 'returning the Extra Chill application fee to the seller failed', implode( ' ', $order->notes ) );
		}

		public function test_platform_product_refund_is_unchanged(): void {
			$GLOBALS['test_post_meta'] = array(); // No seller: an Extra Chill product.
			$order                     = $this->refundable_order();
			$this->assertSame( '', $order->meta['_extrachill_stripe_account'] );
			$this->assertFalse( is_wp_error( $this->refund( 25 ) ) );
			list( $params, $options ) = $GLOBALS['ticket_refunds'][0];
			$this->assertSame( 2500, $params['amount'] );
			$this->assertArrayNotHasKey( 'refund_application_fee', $params );
			$this->assertArrayNotHasKey( 'stripe_account', $options );
			$this->assertSame( array(), $GLOBALS['stripe_fee_calls'] );
		}
	}
}
