<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/**
 * Seller UX (#1442): fee rates shown to sellers, disputed orders held from
 * fulfilment, dashboard refunds reconciled from `charge.refunded`, partial
 * refunds, and the seller's shipping and return policy.
 */
namespace {
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/ShippingRecoveryTest.php';
	require_once __DIR__ . '/CheckoutRobustnessTest.php';
	require_once __DIR__ . '/fixtures/class-stripe-refund.php';
	require_once dirname( __DIR__ ) . '/inc/core/seller-storefront.php';
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {}
	}
	require_once dirname( __DIR__ ) . '/inc/abilities/shop-stripe-status.php';
	require_once dirname( __DIR__ ) . '/inc/abilities/shop-refund-order.php';

	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		function sanitize_textarea_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
	}
	if ( ! function_exists( 'esc_html_e' ) ) {
		function esc_html_e( $text ) {
			echo esc_html( $text );
		}
	}
	if ( ! function_exists( 'wpautop' ) ) {
		function wpautop( $text ) {
			return '<p>' . str_replace( "\n", "<br />\n", (string) $text ) . "</p>\n";
		}
	}

	final class SellerUxTest extends TestCase {
		private const VENUE = array( 'type' => 'venue', 'id' => 9911 );

		protected function setUp(): void {
			$GLOBALS['test_options']             = array( 'admin_email' => 'admin@extrachill.com' );
			$GLOBALS['test_site_options']        = array();
			$GLOBALS['test_blog_options']        = array();
			$GLOBALS['test_orders']              = array();
			$GLOBALS['test_wc_refunds']          = array();
			$GLOBALS['test_wc_refund_objects']   = array();
			$GLOBALS['ticket_refunds']           = array();
			$GLOBALS['ticket_refund_fail']       = false;
			$GLOBALS['stripe_fee']               = array( 'id' => 'fee_merch', 'amount' => 250, 'amount_refunded' => 0 );
			$GLOBALS['stripe_fee_calls']         = array();
			$GLOBALS['stripe_fee_fail']          = false;
			$GLOBALS['stripe_refund_list']       = array();
			$GLOBALS['stripe_refund_list_calls'] = array();
			$GLOBALS['shippo_label_result']      = null;
			$GLOBALS['shippo_labels_bought']     = 0;
			$GLOBALS['notification_mail']        = array();
			$GLOBALS['test_user_id']             = 812;
			$GLOBALS['test_owner_callback']      = static fn() => array( 'type' => 'venue', 'id' => 9911, 'name' => 'Venue', 'can_manage_finances' => true );
			$GLOBALS['test_wc_get_orders_callback'] = static function ( $args ) {
				return array_values( array_filter( $GLOBALS['test_orders'], static fn( $order ) => method_exists( $order, 'get_payment_method' ) && $order->get_meta( $args['meta_key'] ) === $args['meta_value'] ) );
			};
			unset( $GLOBALS['extrachill_shop_pending_refund_id'] );
			extrachill_shop_reset_seller_permission_cache();
			extrachill_shop_set_product_seller( 10, self::VENUE );
			extrachill_shop_set_seller_stripe_account_record( self::VENUE, array( 'account_id' => 'acct_merch', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
			extrachill_shop_set_seller_shipping_address( self::VENUE, $this->address() );
		}

		protected function tearDown(): void {
			unset( $GLOBALS['test_wc_get_orders_callback'] );
		}

		private function address(): array {
			return array( 'name' => 'Venue', 'street1' => '1 Main', 'street2' => '', 'city' => 'Charleston', 'state' => 'SC', 'zip' => '29401', 'country' => 'US' );
		}

		// ── Fees ───────────────────────────────────────────────────────────────

		public function test_default_fee_rates_and_wording(): void {
			$fees = extrachill_shop_seller_fee_rates();
			$this->assertSame( 10.0, $fees['merch_percent'] );
			$this->assertSame( 0.0, $fees['ticket_percent'] );
			$this->assertSame( 'Extra Chill fee: 10% of the item price (shipping not included).', $fees['merch_summary'] );
			$this->assertSame( 'Extra Chill fee on tickets: 0% of the ticket price.', $fees['ticket_summary'] );
		}

		public function test_fee_rates_follow_the_network_setting_checkout_charges_with(): void {
			$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'merch_percent' => 12.5, 'ticket_percent' => 2, 'ticket_buyer_fee_flat_minor' => 100, 'ticket_buyer_fee_percent' => 3 );
			$fees = extrachill_shop_seller_fee_rates();
			$this->assertSame( 'Extra Chill fee: 12.5% of the item price (shipping not included).', $fees['merch_summary'] );
			$this->assertSame( 1.0, $fees['ticket_buyer_fee_flat'] );
			$this->assertSame( 'Extra Chill fee on tickets: 2% of the ticket price. Buyers also pay a service fee of $1.00 plus 3% per order.', $fees['ticket_summary'] );
			// The rate shown is the rate charged.
			$this->assertSame( 250, extrachill_commerce_fee( 'extrachill_merch', 2000, self::VENUE )['application_fee'] );
		}

		public function test_payout_status_carries_the_fee_rates_connected_or_not(): void {
			$this->assertSame( extrachill_shop_seller_fee_rates(), extrachill_shop_ability_stripe_status( array( 'seller' => array( 'type' => 'venue', 'id' => 4321 ) ) )['fees'] );
			$GLOBALS['test_stripe_status'] = array( 'success' => true, 'status' => 'active', 'can_receive_payments' => true, 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true );
			$connected = extrachill_shop_ability_stripe_status( array( 'seller' => self::VENUE ) );
			$this->assertTrue( $connected['connected'] );
			$this->assertSame( 'Extra Chill fee: 10% of the item price (shipping not included).', $connected['fees']['merch_summary'] );
		}

		// ── Disputed orders ────────────────────────────────────────────────────

		private function disputed_order(): ShippingRecoveryOrder {
			$order         = new ShippingRecoveryOrder();
			$order->status = 'on-hold';
			$order->meta   = array(
				'_extrachill_stripe_account'      => 'acct_merch',
				'_extrachill_payment_intent'      => 'pi_merch',
				'_extrachill_shipping_hold_minor' => 500,
				'_extrachill_seller'              => 'venue:9911',
				'_extrachill_application_fee'     => 700,
				'_extrachill_dispute'             => 'dp_1',
			);
			$GLOBALS['test_orders'][321] = $order;
			return $order;
		}

		public function test_disputed_order_cannot_be_marked_shipped(): void {
			$order  = $this->disputed_order();
			$result = extrachill_shop_ability_update_order_status( array( 'id' => 321, 'seller' => self::VENUE, 'status' => 'completed', 'tracking_number' => '9400' ) );
			$this->assertSame( 'order_disputed', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
			$this->assertSame( 'on-hold', $order->status );
			$this->assertSame( array(), $GLOBALS['stripe_fee_calls'] );
		}

		public function test_disputed_order_cannot_get_a_label(): void {
			$this->disputed_order();
			$GLOBALS['shippo_label_result'] = array( 'tracking_number' => '9400', 'label_url' => 'https://label', 'tracking_url' => '', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'cost' => 4.35, 'rate_id' => 'r', 'transaction_id' => 't' );
			$result = extrachill_shop_ability_create_shipping_label( array( 'order_id' => 321, 'seller' => self::VENUE ) );
			$this->assertSame( 'order_disputed', $result->get_error_code() );
			$this->assertSame( 0, $GLOBALS['shippo_labels_bought'] );
		}

		public function test_disputed_order_cannot_be_refunded_from_the_shop_manager(): void {
			$order  = $this->disputed_order();
			$result = extrachill_shop_refund_seller_portion( $order, self::VENUE, '', 5 );
			$this->assertSame( 'order_disputed', $result->get_error_code() );
			$this->assertSame( array(), $GLOBALS['test_wc_refunds'] );
		}

		public function test_order_record_flags_the_dispute_and_refundable_amount(): void {
			$order  = $this->disputed_order();
			$record = extrachill_shop_ability_build_order_response( $order );
			$this->assertTrue( $record['disputed'] );
			$this->assertSame( 25.0, $record['refundable'] );
			$this->assertSame( 0.0, $record['refunded_total'] );
			// A hold for another reason, or a settled dispute, is not a dispute.
			$order->status = 'processing';
			$this->assertFalse( extrachill_shop_ability_build_order_response( $order )['disputed'] );
			$order->status = 'on-hold';
			unset( $order->meta['_extrachill_dispute'] );
			$this->assertFalse( extrachill_shop_order_is_disputed( $order ) );
		}

		public function test_missing_address_error_points_to_the_shipping_tab(): void {
			$this->disputed_order();
			unset( $GLOBALS['test_orders'][321]->meta['_extrachill_dispute'] );
			unset( $GLOBALS['test_options']['extrachill_shop_shipping_seller_venue:9911'] );
			$result = extrachill_shop_ability_create_shipping_label( array( 'order_id' => 321, 'seller' => self::VENUE ) );
			$this->assertStringContainsString( 'Shipping tab', $result->get_error_message() );
			$this->assertStringNotContainsString( 'Settings tab', $result->get_error_message() );
		}

		// ── Partial refunds ────────────────────────────────────────────────────

		public function test_refund_amount_validation(): void {
			$this->assertSame( 25.0, extrachill_shop_validate_refund_amount( null, 25 ) );
			$this->assertSame( 25.0, extrachill_shop_validate_refund_amount( '', 25 ) );
			$this->assertSame( 10.01, extrachill_shop_validate_refund_amount( '10.005', 25 ) );
			$this->assertSame( 25.0, extrachill_shop_validate_refund_amount( 25, 25 ) );
			foreach ( array( 0, '0.001', -5, 'ten' ) as $bad ) {
				$this->assertSame( 'invalid_refund_amount', extrachill_shop_validate_refund_amount( $bad, 25 )->get_error_code() );
			}
			$over = extrachill_shop_validate_refund_amount( 25.01, 25 );
			$this->assertSame( 'refund_exceeds_remaining', $over->get_error_code() );
			$this->assertSame( 'You can refund up to $25.00 on this order.', $over->get_error_message() );
		}

		private function merch_order(): MerchCheckoutOrder {
			$order       = new MerchCheckoutOrder();
			$order->meta = array(
				'_extrachill_payment_intent'  => 'pi_merch',
				'_extrachill_stripe_account'  => 'acct_merch',
				'_extrachill_seller'          => 'venue:9911',
				'_extrachill_application_fee' => 250,
			);
			$order->status               = 'processing';
			$GLOBALS['test_orders'][123] = $order;
			return $order;
		}

		public function test_partial_refund_refunds_the_amount_and_a_share_of_the_fee(): void {
			$order  = $this->merch_order();
			$result = extrachill_shop_refund_seller_portion( $order, self::VENUE, 'One shirt missing', '10' );
			$this->assertSame( 10.0, $result['refund_amount'] );
			$this->assertSame( 15.0, $result['remaining'] );
			$this->assertSame( 10.0, $GLOBALS['test_wc_refunds'][0]['amount'] );
			$this->assertSame( 1000, $GLOBALS['ticket_refunds'][0][0]['amount'] );
			$fee_refunds = array_values( array_filter( $GLOBALS['stripe_fee_calls'], static fn( $c ) => 'fee.refund' === $c[0] ) );
			$this->assertSame( 100, $fee_refunds[0][2]['amount'] );
			// The rest can still be refunded; more than the rest cannot.
			$this->assertSame( 'refund_exceeds_remaining', extrachill_shop_refund_seller_portion( $order, self::VENUE, '', 15.01 )->get_error_code() );
			$this->assertCount( 1, $GLOBALS['test_wc_refunds'] );
			$this->assertSame( 15.0, extrachill_shop_refund_seller_portion( $order, self::VENUE )['refund_amount'] );
			$this->assertSame( 'already_refunded', extrachill_shop_refund_seller_portion( $order, self::VENUE )->get_error_code() );
		}

		public function test_refund_ability_accepts_an_amount(): void {
			$GLOBALS['test_abilities'] = array();
			extrachill_shop_register_refund_order_ability();
			$ability = $GLOBALS['test_abilities']['extrachill/shop-refund-order'] ?? reset( $GLOBALS['test_abilities'] );
			$this->assertSame( 'number', $ability['input_schema']['properties']['amount']['type'] );
			$this->assertNotContains( 'amount', $ability['input_schema']['required'] );
		}

		// ── charge.refunded for merch ─────────────────────────────────────────

		private function refunded_event( int $amount_refunded, array $refunds, bool $has_more = false, string $account = 'acct_merch' ) {
			return (object) array(
				'type'    => 'charge.refunded',
				'account' => $account,
				'data'    => (object) array(
					'object' => (object) array(
						'id'              => 'ch_merch',
						'payment_intent'  => 'pi_merch',
						'amount_refunded' => $amount_refunded,
						'refunds'         => (object) array( 'has_more' => $has_more, 'data' => $refunds ),
						'metadata'        => (object) array(),
					),
				),
			);
		}

		private static function stripe_refund( string $id, int $amount, array $metadata = array() ) {
			return (object) array( 'id' => $id, 'amount' => $amount, 'status' => 'succeeded', 'metadata' => (object) $metadata );
		}

		private function fee_refunds(): array {
			return array_values( array_filter( $GLOBALS['stripe_fee_calls'], static fn( $c ) => 'fee.refund' === $c[0] ) );
		}

		public function test_dashboard_refund_is_recorded_once_with_a_note_and_fee_share(): void {
			$order = $this->merch_order();
			$event = $this->refunded_event( 1000, array( self::stripe_refund( 're_dash', 1000 ) ) );
			$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
			$this->assertCount( 1, $GLOBALS['test_wc_refunds'] );
			$this->assertSame( 10.0, $GLOBALS['test_wc_refunds'][0]['amount'] );
			$this->assertFalse( $GLOBALS['test_wc_refunds'][0]['refund_payment'] );
			$this->assertSame( array(), $GLOBALS['ticket_refunds'] );
			$this->assertSame( 're_dash', $order->get_refunds()[0]->get_meta( '_extrachill_stripe_refund_id' ) );
			$this->assertStringContainsString( '$10.00 from their payout dashboard', end( $order->notes ) );
			// #1460 fee math: 10 of 25 refunded returns 10/25 of the $2.50 fee.
			$this->assertSame( 100, $this->fee_refunds()[0][2]['amount'] );

			// Stripe retries the same event: nothing more is recorded or refunded.
			$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
			$this->assertCount( 1, $GLOBALS['test_wc_refunds'] );
			$this->assertCount( 1, $this->fee_refunds() );
			$this->assertSame( 15.0, $order->get_remaining_refund_amount() );
		}

		public function test_second_dashboard_refund_records_only_the_new_amount(): void {
			$order = $this->merch_order();
			extrachill_shop_route_webhook_event( $this->refunded_event( 1000, array( self::stripe_refund( 're_dash', 1000 ) ) ) );
			// Stripe lists newest first; the charge now shows the full $25 refunded.
			extrachill_shop_route_webhook_event( $this->refunded_event( 2500, array( self::stripe_refund( 're_dash2', 1500 ), self::stripe_refund( 're_dash', 1000 ) ) ) );
			$this->assertSame( array( 10.0, 15.0 ), array_column( $GLOBALS['test_wc_refunds'], 'amount' ) );
			$this->assertSame( 0.0, $order->get_remaining_refund_amount() );
			$this->assertSame( array( 100, 150 ), array_map( static fn( $c ) => $c[2]['amount'], $this->fee_refunds() ) );
		}

		public function test_our_own_refund_is_not_recorded_twice(): void {
			$order = $this->merch_order();
			extrachill_shop_refund_seller_portion( $order, self::VENUE, '', 20 );
			$this->assertCount( 1, $GLOBALS['test_wc_refunds'] );
			// The WooCommerce refund is tagged with the Stripe refund it issued.
			$this->assertSame( 're_ticket', $order->get_refunds()[0]->get_meta( '_extrachill_stripe_refund_id' ) );
			$fee_calls = count( $this->fee_refunds() );

			// Our refund's webhook arrives (the stub refund is $20 for order 123).
			$own = self::stripe_refund( 're_ticket', 2000, array( 'order_id' => '123' ) );
			$this->assertTrue( extrachill_shop_route_webhook_event( $this->refunded_event( 2000, array( $own ) ) ) );
			$this->assertCount( 1, $GLOBALS['test_wc_refunds'] );
			$this->assertCount( $fee_calls, $this->fee_refunds() );

			// A dashboard refund on top is recorded; our refund still is not.
			$this->assertTrue( extrachill_shop_route_webhook_event( $this->refunded_event( 2300, array( self::stripe_refund( 're_dash', 300 ), $own ) ) ) );
			$this->assertSame( array( 20.0, 3.0 ), array_column( $GLOBALS['test_wc_refunds'], 'amount' ) );
		}

		public function test_own_refund_without_the_tag_is_skipped_by_its_metadata(): void {
			$order = $this->merch_order();
			// The webhook can beat the refund tag; the amount check and the
			// refund's order metadata both stop a double record.
			$order->refunds[] = 5.0;
			$this->assertTrue( extrachill_shop_route_webhook_event( $this->refunded_event( 800, array( self::stripe_refund( 're_dash', 300 ), self::stripe_refund( 're_own', 500, array( 'order_id' => '123' ) ) ) ) ) );
			$this->assertSame( array( 3.0 ), array_column( $GLOBALS['test_wc_refunds'], 'amount' ) );
		}

		public function test_truncated_refund_list_is_paged_from_stripe(): void {
			$this->merch_order();
			$GLOBALS['stripe_refund_list'] = array( self::stripe_refund( 're_dash', 700 ) );
			$this->assertTrue( extrachill_shop_route_webhook_event( $this->refunded_event( 700, array(), true ) ) );
			$this->assertSame( array( 'charge' => 'ch_merch', 'limit' => 100 ), $GLOBALS['stripe_refund_list_calls'][0][0] );
			$this->assertSame( array( 'stripe_account' => 'acct_merch' ), $GLOBALS['stripe_refund_list_calls'][0][1] );
			$this->assertSame( 7.0, $GLOBALS['test_wc_refunds'][0]['amount'] );
		}

		public function test_refund_on_another_account_or_unknown_charge_changes_nothing(): void {
			$order = $this->merch_order();
			$log   = ini_set( 'error_log', '/dev/null' ); // The handler logs the miss for operators.
			$this->assertTrue( extrachill_shop_route_webhook_event( $this->refunded_event( 1000, array( self::stripe_refund( 're_x', 1000 ) ), false, 'acct_other' ) ) );
			$event                                 = $this->refunded_event( 1000, array( self::stripe_refund( 're_x', 1000 ) ) );
			$event->data->object->payment_intent   = 'pi_unknown';
			$this->assertTrue( extrachill_shop_route_webhook_event( $event ) );
			ini_set( 'error_log', (string) $log );
			$this->assertSame( array(), $GLOBALS['test_wc_refunds'] );
			$this->assertSame( array(), $order->notes );
		}

		// ── Shipping and return policy ────────────────────────────────────────

		public function test_policy_saves_alone_and_keeps_the_address(): void {
			$result = extrachill_shop_ability_update_shipping_address( array( 'seller' => self::VENUE, 'return_policy' => " Ships in 3 days.\n<b>Returns</b> within 30 days. " ) );
			$this->assertTrue( $result['success'] );
			$this->assertSame( "Ships in 3 days.\nReturns within 30 days.", $result['return_policy'] );
			$this->assertSame( $this->address(), $result['address'] );

			$read = extrachill_shop_ability_get_shipping_address( array( 'seller' => self::VENUE ) );
			$this->assertSame( "Ships in 3 days.\nReturns within 30 days.", $read['return_policy'] );
			$this->assertSame( $this->address(), $read['address'] );
			$this->assertTrue( $read['is_set'] );

			// Saving the address again keeps the policy.
			extrachill_shop_ability_update_shipping_address( array_merge( array( 'seller' => self::VENUE ), $this->address(), array( 'name' => 'New Name' ) ) );
			$this->assertSame( "Ships in 3 days.\nReturns within 30 days.", extrachill_shop_get_seller_return_policy( self::VENUE ) );
			$this->assertSame( 'New Name', extrachill_shop_get_seller_shipping_address( self::VENUE )['name'] );

			// And an empty policy clears it.
			extrachill_shop_ability_update_shipping_address( array( 'seller' => self::VENUE, 'return_policy' => '' ) );
			$this->assertSame( '', extrachill_shop_get_seller_return_policy( self::VENUE ) );
		}

		public function test_policy_only_record_is_not_a_ship_from_address(): void {
			$seller = array( 'type' => 'venue', 'id' => 5150 );
			extrachill_shop_set_seller_return_policy( $seller, 'No returns.' );
			$read = extrachill_shop_ability_get_shipping_address( array( 'seller' => $seller ) );
			$this->assertFalse( $read['is_set'] );
			$this->assertSame( 'No returns.', $read['return_policy'] );
		}

		public function test_policy_is_capped(): void {
			$this->assertSame( EXTRACHILL_SHOP_RETURN_POLICY_MAX, strlen( extrachill_shop_sanitize_return_policy( str_repeat( 'a', 2500 ) ) ) );
		}

		public function test_policy_renders_escaped_on_the_storefront_and_product_tab(): void {
			extrachill_shop_set_seller_return_policy( self::VENUE, "Ships in 3 days & <script>x</script>\nNo returns." );
			$html = extrachill_shop_return_policy_html( extrachill_shop_get_seller_return_policy( self::VENUE ) );
			$this->assertStringContainsString( 'Ships in 3 days &amp; &lt;script&gt;', $html );
			$this->assertStringNotContainsString( '<script>', $html );

			$GLOBALS['test_post_meta'][10] = array( '_extrachill_shop_seller_type' => 'venue', '_extrachill_shop_seller_id' => 9911 );
			$GLOBALS['product']            = new class() extends WC_Product { public function get_id() { return 10; } };
			$tabs                          = extrachill_shop_product_return_policy_tab( array( 'description' => array( 'title' => 'Description' ) ) );
			$this->assertSame( 'Shipping & Returns', $tabs['extrachill_shipping_returns']['title'] );
			ob_start();
			call_user_func( $tabs['extrachill_shipping_returns']['callback'] );
			$this->assertStringContainsString( 'No returns.', ob_get_clean() );

			// Sellers without a policy get no tab.
			extrachill_shop_set_seller_return_policy( self::VENUE, '' );
			$this->assertArrayNotHasKey( 'extrachill_shipping_returns', extrachill_shop_product_return_policy_tab( array() ) );
			unset( $GLOBALS['product'] );
		}

		public function test_storefront_header_shows_the_policy(): void {
			// A venue no other suite resolves, so the per-request identity memo is cold.
			$seller                          = array( 'type' => 'venue', 'id' => 7741 );
			$GLOBALS['test_site_transients'] = array();
			extrachill_shop_set_seller_return_policy( $seller, 'Ships within a week.' );
			$GLOBALS['test_owner_callback'] = static fn() => array( 'type' => 'venue', 'id' => 7741, 'name' => 'Policy Venue', 'slug' => 'policy-venue', 'url' => '', 'can_manage_finances' => false );
			ob_start();
			extrachill_shop_render_storefront_header( $seller );
			$html = ob_get_clean();
			$this->assertStringContainsString( 'Shipping &amp; returns', $html );
			$this->assertStringContainsString( 'Ships within a week.', $html );
		}
	}
}
