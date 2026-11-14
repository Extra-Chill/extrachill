<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/**
 * Venue order end to end through the canonical order seller record:
 * order creation -> checkout -> seller order view -> tracking -> label -> label read.
 */

// Reuse the suite's Stripe, checkout, Shippo and mail doubles.
require_once __DIR__ . '/MerchCheckoutTest.php';
require_once __DIR__ . '/ShippingAddressTest.php';
require_once __DIR__ . '/SellerOrderNotificationsTest.php';
require_once dirname( __DIR__ ) . '/inc/core/order-seller-index.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-list-orders.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-update-order-status.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-get-shipping-label.php';

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() { return (object) array( 'display_name' => 'Venue Staff' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return (string) $url; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) { return '2026-01-02 03:04:05'; }
}

final class OrderSellerRecordTestItem {
	public function get_product_id() { return 10; }
	public function get_total() { return 20; }
	public function get_total_tax() { return 0; }
	public function get_quantity() { return 2; }
	public function get_name() { return 'Shirt'; }
}

final class OrderSellerRecordTestOrder {
	public $meta   = array();
	public $status = 'pending';
	public function get_id() { return 321; }
	public function get_order_number() { return '321'; }
	public function get_items( $type = 'line_item' ) { return array( new OrderSellerRecordTestItem() ); }
	public function get_total() { return 25; }
	public function get_currency() { return 'USD'; }
	public function get_billing_email() { return 'buyer@example.com'; }
	public function get_billing_first_name() { return 'Test'; }
	public function get_billing_last_name() { return 'Buyer'; }
	public function get_address( $type ) { return array( 'address_1' => '456 Destination', 'country' => 'US' ); }
	public function get_date_created() { return null; }
	public function get_checkout_order_received_url() { return 'https://shop.example/received'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save_meta_data() {}
	public function save() {}
	public function get_status() { return $this->status; }
	public function set_status( $status, $note = '' ) { $this->status = $status; }
}

final class OrderSellerRecordTest extends \PHPUnit\Framework\TestCase {
	private const VENUE = array(
		'type' => 'venue',
		'id'   => 9911,
	);

	protected function setUp(): void {
		extrachill_shop_reset_seller_permission_cache();
		$GLOBALS['test_options']         = array();
		$GLOBALS['test_post_meta']       = array();
		$GLOBALS['test_orders']          = array();
		$GLOBALS['notification_mail']    = array();
		$GLOBALS['ticket_account_ready'] = true;
		$GLOBALS['test_user_id']         = 812;
		$GLOBALS['test_owner_callback']  = static fn() => self::VENUE + array( 'name' => 'Venue' );
		unset( $GLOBALS['shippo_label'] );
		extrachill_shop_set_product_seller( 10, self::VENUE );
		extrachill_shop_set_seller_stripe_account_record( self::VENUE, array( 'account_id' => 'acct_venue' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['shippo_label'] );
	}

	public function test_venue_order_flows_through_one_record(): void {
		$order                       = new OrderSellerRecordTestOrder();
		$GLOBALS['test_orders'][321] = $order;

		// Order creation derives the seller.
		extrachill_shop_index_order_seller( $order );
		$this->assertSame( 'venue:9911', $order->meta['_extrachill_seller'] );

		// Checkout charges on the venue's account and records the fee.
		$this->assertFalse( is_wp_error( extrachill_shop_start_merch_checkout( $order ) ) );
		$this->assertSame( 'acct_venue', $order->meta['_extrachill_stripe_account'] );
		$this->assertSame( 200, $order->meta['_extrachill_application_fee'] );
		$this->assertSame( 'venue:9911', $order->meta['_extrachill_seller'] );
		foreach ( array( '_artist_payouts', '_seller_payouts', '_extrachill_order_seller' ) as $retired ) {
			$this->assertArrayNotHasKey( $retired, $order->meta );
		}

		$record = extrachill_shop_get_order_seller_record( $order );
		$this->assertSame( self::VENUE, $record['seller'] );
		$this->assertSame( 'acct_venue', $record['stripe_account'] );
		$this->assertSame( 25.0, $record['total'] );
		$this->assertSame( 2.0, $record['application_fee'] );
		$this->assertSame( 23.0, $record['seller_payout'] );
		$this->assertSame( $record, extrachill_shop_get_order_seller_payout( $order, self::VENUE ) );
		$this->assertNull( extrachill_shop_get_order_seller_payout( $order, array( 'type' => 'promoter', 'id' => 9911 ) ) );

		// The seller's order view.
		$view = extrachill_shop_ability_build_order_response( $order );
		$this->assertSame( 23.0, $view['seller_payout'] );
		$this->assertSame( 25.0, $view['order_total'] );
		$this->assertSame( '', $view['label_url'] );
		$this->assertSame( 'Product 10', $view['items'][0]['name'] );

		// Manual tracking lands in the shipment record, without a label.
		$view = extrachill_shop_ability_update_order_status( array(
			'id'              => 321,
			'seller'          => self::VENUE,
			'status'          => 'processing',
			'tracking_number' => '1ZMANUAL',
		) );
		$this->assertSame( '1ZMANUAL', $view['tracking_number'] );
		$this->assertSame( '', $view['label_url'] );

		// Buying a label replaces the tracking and is read back everywhere.
		extrachill_shop_set_seller_shipping_address( self::VENUE, array(
			'name'    => 'Venue',
			'street1' => '1 Stage St',
			'street2' => '',
			'city'    => 'Charleston',
			'state'   => 'SC',
			'zip'     => '29401',
			'country' => 'US',
		) );
		$GLOBALS['shippo_label'] = array(
			'label_url'       => 'https://labels.example/321.pdf',
			'tracking_number' => '9400LABEL',
			'tracking_url'    => 'https://track.example/9400LABEL',
			'carrier'         => 'USPS',
			'service'         => 'Ground Advantage',
			'cost'            => 5.0,
			'rate_id'         => 'rate_1',
			'transaction_id'  => 'tx_1',
		);
		$label = extrachill_shop_ability_create_shipping_label( array(
			'order_id' => 321,
			'seller'   => self::VENUE,
		) );
		$this->assertSame( 'https://labels.example/321.pdf', $label['label_url'] );
		$this->assertSame( 'completed', $order->status );
		$this->assertSame( 'tx_1', $order->meta['_extrachill_shipment']['transaction_id'] );

		$view = extrachill_shop_ability_build_order_response( $order );
		$this->assertSame( 'https://labels.example/321.pdf', $view['label_url'] );
		$this->assertSame( '9400LABEL', $view['tracking_number'] );

		$read = extrachill_shop_ability_get_shipping_label( array(
			'order_id' => 321,
			'seller'   => self::VENUE,
		) );
		$this->assertTrue( $read['has_label'] );
		$this->assertSame( 'https://track.example/9400LABEL', $read['tracking_url'] );
		$this->assertSame( 'Ground Advantage', $read['service'] );
		$this->assertSame( '2026-01-02 03:04:05', $read['purchased_at'] );
		$this->assertArrayNotHasKey( 'transaction_id', $read );

		// A second purchase is a reprint of the stored label.
		unset( $GLOBALS['shippo_label'] );
		$again = extrachill_shop_ability_create_shipping_label( array(
			'order_id' => 321,
			'seller'   => self::VENUE,
		) );
		$this->assertTrue( $again['reprint'] );
		$this->assertSame( '9400LABEL', $again['tracking_number'] );

		// The label email links to the venue's own shop manager.
		$this->assertSame( 'https://shop.extrachill.com/manage/?seller=venue:9911', $GLOBALS['notification_mail'][0]['context']['cta_url'] );

		// Another seller can read none of it.
		$this->assertSame( 'order_not_found', extrachill_shop_ability_get_shipping_label( array(
			'order_id' => 321,
			'seller'   => array( 'type' => 'venue', 'id' => 1 ),
		) )->get_error_code() );
	}
}
