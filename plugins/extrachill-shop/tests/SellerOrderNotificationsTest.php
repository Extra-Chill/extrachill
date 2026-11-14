<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Seller order emails: artists directly, venues and promoters through Events. */
namespace {
	function get_userdata( $id ) { return (object) array( 'user_email' => "user$id@example.com" ); }
	function ec_get_linked_members( $id ) { return array( get_userdata( 4 ), get_userdata( 4 ), get_userdata( 5 ) ); }
	function esc_html( $text ) { return htmlspecialchars( (string) $text ); }
	function ec_send_email( $data ) { $GLOBALS['notification_mail'][] = $data; }
	function wc_get_product( $id ) { return new class( $id ) {
		private $id;
		public function __construct( $id ) { $this->id = $id; }
		public function get_name() { return 'Product ' . $this->id; }
	}; }
	// Reuse existing suite WordPress doubles when this file runs alone.
	require_once __DIR__ . '/SellerCommerceTest.php';
	require_once __DIR__ . '/TicketTest.php';
	require_once dirname( __DIR__ ) . '/inc/core/commission-settings.php';
	require_once dirname( __DIR__ ) . '/inc/core/artist-product-meta.php';
	require_once dirname( __DIR__ ) . '/inc/stripe/checkout-handler.php';
	require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
	require_once dirname( __DIR__ ) . '/inc/core/order-seller-index.php';
	require_once dirname( __DIR__ ) . '/inc/core/seller-order-notifications.php';

	final class SellerOrderNotificationsTest extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			$GLOBALS['notification_mail']   = $GLOBALS['test_blog_stack'] = array();
			$GLOBALS['notification_events'] = array();
			$GLOBALS['notification_down']   = false;
			$GLOBALS['test_options']        = $GLOBALS['test_post_meta'] = array();
			$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) {
				if ( str_contains( $route, 'get-commerce-seller' ) ) {
					return array( 'type' => $args['query']['input']['type'], 'id' => 77, 'name' => 'Lo-Fi' );
				}
				if ( str_contains( $route, 'notify-commerce-seller' ) ) {
					$GLOBALS['notification_events'][] = array( $site, $args );
					return $GLOBALS['notification_down'] ? new WP_Error( 'owner_runtime_unavailable', 'Down' ) : array( 'sent' => 1 );
				}
				return new WP_Error( 'unexpected', $route );
			};
		}
		private function notify( array $types, $fee_minor = '' ): array {
			$items = array();
			foreach ( $types as $i => $type ) {
				$id = $i + 10;
				if ( $type ) {
					extrachill_shop_set_product_seller( $id, array(
						'type' => $type,
						'id'   => 'artist' === $type ? 9 : 77,
					) );
				}
				$items[] = new class( $id ) {
					private $id;
					public function __construct( $id ) { $this->id = $id; }
					public function get_product_id() { return $this->id; }
					public function get_quantity() { return 2; }
					public function get_total() { return 20; }
				};
			}
			$order = new class( $items ) {
				public $meta = array();
				private $items;
				public function __construct( $items ) { $this->items = $items; }
				public function get_items( $type = 'line_item' ) { return $this->items; }
				public function get_total() { return 20 * count( $this->items ); }
				public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
				public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
				public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
				public function save_meta_data() {}
				public function get_order_number() { return 123; }
				public function get_billing_first_name() { return 'Test'; }
				public function get_billing_last_name() { return 'Buyer'; }
				public function get_address( $type ) { return array( 'address_1' => '123 Main' ); }
			};
			// Order creation derives the seller; checkout records the fee.
			extrachill_shop_index_order_seller( $order );
			$order->meta['_extrachill_application_fee'] = $fee_minor;
			$GLOBALS['test_orders'][1]                 = $order;
			extrachill_shop_notify_sellers_of_order( 1 );
			$this->assertSame( array(), $GLOBALS['test_blog_stack'] );
			return $GLOBALS['notification_mail'];
		}
		public function test_artist_roster_is_unchanged(): void {
			$mail = $this->notify( array( 'artist' ) );
			$this->assertSame( array( 'user4@example.com', 'user5@example.com' ), array_column( $mail, 'to' ) );
			$this->assertSame( 'https://shop.extrachill.com/manage/?seller=artist:9', $mail[0]['context']['cta_url'] );
		}
		private function events_message( $index = 0 ) {
			list( $site, $args ) = $GLOBALS['notification_events'][ $index ];
			$this->assertSame( 'events', $site );
			$this->assertSame( 0, $args['user_id'] );
			$this->assertSame( 'extrachill.events.tickets', $args['service_assertion']['service_id'] );
			return $args['body']['input'];
		}
		public function test_venue_order_is_emailed_by_events(): void {
			$this->assertSame( array(), $this->notify( array( 'venue' ) ), 'Shop never mails venue owners itself.' );
			$input = $this->events_message();
			$this->assertSame( array( 'type' => 'venue', 'id' => 77 ), $input['seller'] );
			$this->assertSame( 'New Order #123 - Lo-Fi', $input['subject'] );
			$this->assertStringContainsString( 'Product 10', $input['body_html'] );
			$this->assertSame( 'https://shop.extrachill.com/manage/?seller=venue:77', $input['cta_url'] );
		}
		public function test_promoter_order_is_emailed_by_events(): void {
			$this->notify( array( 'promoter' ) );
			$this->assertSame( array( 'type' => 'promoter', 'id' => 77 ), $this->events_message()['seller'] );
		}
		public function test_events_outage_does_not_break_the_order(): void {
			$GLOBALS['notification_down'] = true;
			$this->assertSame( array(), $this->notify( array( 'venue' ) ) );
			$this->assertCount( 1, $GLOBALS['notification_events'] );
		}
		public function test_mixed_and_platform_orders_have_no_seller_to_notify(): void {
			$this->assertSame( array(), $this->notify( array( 'artist', 'venue' ) ) );
			$GLOBALS['test_post_meta'] = array();
			$this->assertSame( array(), $this->notify( array( null ) ) );
			$this->assertSame( array(), $GLOBALS['notification_events'] );
		}
		public function test_payout_is_the_order_total_minus_the_checkout_fee(): void {
			$this->notify( array( 'venue', 'venue' ), 350 );
			$body = $this->events_message()['body_html'];
			$this->assertStringContainsString( 'Product 10', $body );
			$this->assertStringContainsString( 'Product 11', $body );
			$this->assertStringContainsString( '<strong>Your Payout:</strong> $36.50', $body );
		}
	}
}
