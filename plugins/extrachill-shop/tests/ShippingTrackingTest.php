<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/**
 * Buyers see their seller and tracking (#1439): in WooCommerce's customer
 * emails and on the order view.
 */
namespace {
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/ShippingRecoveryTest.php';
	require_once dirname( __DIR__ ) . '/inc/core/artist-taxonomy.php';
	require_once dirname( __DIR__ ) . '/inc/core/buyer-order-details.php';

	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text ) { return htmlspecialchars( (string) $text ); }
	}

	/**
	 * A paid order. Moving into `completed` is when WooCommerce sends its
	 * Completed order email; a customer note sends the Customer note email.
	 */
	final class BuyerOrderDetailsOrder {
		public $meta           = array();
		public $notes          = array();
		public $customer_notes = array();
		public $status         = 'processing';
		public $buyer_emails   = 0;
		public function get_id() { return 654; }
		public function get_order_number() { return '654'; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function add_order_note( $note, $is_customer_note = 0 ) {
			$this->notes[] = $note;
			if ( $is_customer_note ) {
				$this->customer_notes[] = $note;
				++$this->buyer_emails;
			}
		}
		public function set_status( $status, $note = '' ) {
			if ( 'completed' === $status && 'completed' !== $this->status ) {
				++$this->buyer_emails;
			}
			$this->status  = $status;
			$this->notes[] = $note;
		}
		public function get_status() { return $this->status; }
		public function save() {}
		public function get_items( $type = 'line_item' ) { return array( new ShippingRecoveryItem() ); }
		public function get_total() { return 25; }
		public function get_address( $type ) { return array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '456 Destination', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US' ); }
		public function get_date_created() { return null; }
		public function get_billing_first_name() { return 'Test'; }
		public function get_billing_last_name() { return 'Buyer'; }
		public function get_billing_email() { return 'buyer@example.com'; }
	}

	final class ShippingTrackingTest extends TestCase {
		private const VENUE = array( 'type' => 'venue', 'id' => 9922 );

		protected function setUp(): void {
			$GLOBALS['test_options']         = array();
			$GLOBALS['test_site_options']    = $GLOBALS['test_blog_options'] = array();
			$GLOBALS['test_site_transients'] = array();
			$GLOBALS['test_orders']          = array();
			$GLOBALS['stripe_fee_calls']     = array();
			$GLOBALS['stripe_fee_fail']      = false;
			$GLOBALS['stripe_fee']           = array( 'id' => 'fee_1', 'amount' => 700, 'amount_refunded' => 0 );
			$GLOBALS['shippo_label_result']  = null;
			$GLOBALS['shippo_labels_bought'] = 0;
			$GLOBALS['notification_mail']    = array();
			$GLOBALS['test_user_id']         = 813;
			$GLOBALS['test_owner_callback']  = static fn() => array(
				'type'                => 'venue',
				'id'                  => 9922,
				'name'                => 'Lo-Fi & Friends',
				'slug'                => 'lo-fi',
				'url'                 => 'https://events.extrachill.com/venue/lo-fi/',
				'can_manage_finances' => true,
			);
			extrachill_shop_set_product_seller( 10, self::VENUE );
			extrachill_shop_set_seller_stripe_account_record( self::VENUE, array( 'account_id' => 'acct_merch', 'status' => 'active', 'status_synced_at' => '2026-11-01T00:00:00+00:00' ) );
			extrachill_shop_set_seller_shipping_address( self::VENUE, array( 'name' => 'Venue', 'street1' => '1 Main', 'street2' => '', 'city' => 'Charleston', 'state' => 'SC', 'zip' => '29401', 'country' => 'US' ) );
		}

		private function paid_order(): BuyerOrderDetailsOrder {
			$order       = new BuyerOrderDetailsOrder();
			$order->meta = array(
				'_extrachill_stripe_account'      => 'acct_merch',
				'_extrachill_payment_intent'      => 'pi_merch',
				'_extrachill_shipping_hold_minor' => 500,
				'_extrachill_seller'              => 'venue:9922',
				'_extrachill_application_fee'     => 700,
			);
			$GLOBALS['test_orders'][654] = $order;
			return $order;
		}

		private function mark_shipped( array $extra = array() ) {
			return extrachill_shop_ability_update_order_status( array_merge( array( 'id' => 654, 'seller' => self::VENUE, 'status' => 'completed' ), $extra ) );
		}

		private function buy_label() {
			$GLOBALS['shippo_label_result'] = array( 'tracking_number' => '9400111', 'label_url' => 'https://label', 'tracking_url' => 'https://track.example/9400111?a=1', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'cost' => 4.35, 'rate_id' => 'r', 'transaction_id' => 't' );
			return extrachill_shop_ability_create_shipping_label( array( 'order_id' => 654, 'seller' => self::VENUE ) );
		}

		private function customer_email( $order, bool $plain = false ): string {
			ob_start();
			extrachill_shop_email_buyer_order_details( $order, false, $plain );
			return (string) ob_get_clean();
		}

		private function order_view( $order ): string {
			ob_start();
			extrachill_shop_render_buyer_order_details( $order );
			return (string) ob_get_clean();
		}

		public function test_hooks_into_woocommerce_customer_emails_and_order_view(): void {
			$hooks = array_column( $GLOBALS['test_actions']['woocommerce_email_order_meta'] ?? array(), 'callback' );
			$this->assertContains( 'extrachill_shop_email_buyer_order_details', $hooks );
			$hooks = array_column( $GLOBALS['test_actions']['woocommerce_order_details_after_order_table'] ?? array(), 'callback' );
			$this->assertContains( 'extrachill_shop_render_buyer_order_details', $hooks );
		}

		public function test_mark_shipped_sends_one_email_with_tracking(): void {
			$order = $this->paid_order();
			$this->assertFalse( is_wp_error( $this->mark_shipped( array( 'tracking_number' => '1Z999' ) ) ) );
			$this->assertSame( 1, $order->buyer_emails, 'The Completed order email is the shipped email.' );
			$this->assertSame( array(), $order->customer_notes );

			$email = $this->customer_email( $order );
			$this->assertStringContainsString( 'Tracking number: 1Z999', $email );
			$this->assertStringContainsString( '<a href="https://events.extrachill.com/venue/lo-fi/">Lo-Fi &amp; Friends</a>', $email );

			// Marking it shipped again with the same tracking sends nothing more.
			$this->mark_shipped( array( 'tracking_number' => '1Z999' ) );
			$this->assertSame( 1, $order->buyer_emails );
		}

		public function test_label_then_mark_shipped_sends_one_email_with_carrier_and_link(): void {
			$order = $this->paid_order();
			$this->assertSame( '9400111', $this->buy_label()['tracking_number'] );
			$this->mark_shipped( array( 'tracking_number' => '9400111' ) );
			$this->assertSame( 1, $order->buyer_emails );

			$email = $this->customer_email( $order );
			$this->assertStringContainsString( 'Shipped via USPS. Tracking number: <a href="https://track.example/9400111?a=1">9400111</a>', $email );

			$plain = $this->customer_email( $order, true );
			$this->assertStringContainsString( 'Shipped via USPS. Tracking number: 9400111', $plain );
			$this->assertStringContainsString( 'Track your package: https://track.example/9400111?a=1', $plain );
			$this->assertStringContainsString( 'Seller page: https://events.extrachill.com/venue/lo-fi/', $plain );
			$this->assertStringNotContainsString( '<', $plain );
		}

		public function test_tracking_added_after_shipping_reaches_the_buyer_once(): void {
			$order = $this->paid_order();
			$this->mark_shipped();
			$this->assertSame( 1, $order->buyer_emails );

			$this->mark_shipped( array( 'tracking_number' => '1ZLATE' ) );
			$this->assertSame( 2, $order->buyer_emails );
			$this->assertCount( 1, $order->customer_notes );
			$this->assertStringContainsString( 'Tracking number: 1ZLATE', $this->customer_email( $order ) );

			$this->mark_shipped( array( 'tracking_number' => '1ZLATE' ) );
			$this->assertSame( 2, $order->buyer_emails );
		}

		public function test_account_view_shows_tracking(): void {
			$order = $this->paid_order();
			$this->buy_label();
			$view = $this->order_view( $order );
			$this->assertStringContainsString( '<h2>Seller and shipping</h2>', $view );
			$this->assertStringContainsString( '<a href="https://track.example/9400111?a=1">9400111</a>', $view );
			$this->assertStringContainsString( 'Lo-Fi &amp; Friends', $view );
		}

		public function test_confirmation_names_the_seller_without_tracking(): void {
			$order = $this->paid_order();
			$email = $this->customer_email( $order );
			$this->assertStringContainsString( 'Sold and shipped by <a href="https://events.extrachill.com/venue/lo-fi/">Lo-Fi &amp; Friends</a>', $email );
			$this->assertStringContainsString( 'Contact the seller', $email );
			$this->assertStringNotContainsString( 'Tracking', $email );
			$this->assertSame( $email, $this->order_view( $order ) );
		}

		public function test_admin_emails_and_platform_orders_show_nothing(): void {
			$order = $this->paid_order();
			ob_start();
			extrachill_shop_email_buyer_order_details( $order, true, false );
			$this->assertSame( '', ob_get_clean() );

			unset( $order->meta['_extrachill_seller'] );
			$this->assertSame( '', $this->order_view( $order ) );
			$this->assertSame( '', $this->customer_email( $order ) );
			$this->assertSame( '', $this->customer_email( $order, true ) );
		}

		public function test_seller_without_a_public_page_links_to_its_storefront(): void {
			$order                             = $this->paid_order();
			$order->meta['_extrachill_seller'] = 'venue:9933';
			$GLOBALS['test_owner_callback'] = static fn() => array( 'type' => 'venue', 'id' => 9933, 'name' => 'No Page', 'slug' => 'no-page', 'url' => '' );
			$view = $this->order_view( $order );
			$this->assertStringContainsString( 'Sold and shipped by <a href="https://shop.extrachill.com/venue/no-page/">No Page</a>.', $view );
		}
	}
}
