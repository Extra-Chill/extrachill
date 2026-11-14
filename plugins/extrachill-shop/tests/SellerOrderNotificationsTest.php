<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Seller order emails: artists directly, venues and promoters through Events. */
namespace {
	function get_userdata( $id ) { return (object) array( 'user_email' => "user$id@example.com" ); }
	function get_post( $id ) { return (object) array( 'post_title' => 'Artist' ); }
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
	require_once dirname( __DIR__ ) . '/inc/core/marketplace-settings.php';
	require_once dirname( __DIR__ ) . '/inc/core/artist-product-meta.php';
	require_once dirname( __DIR__ ) . '/inc/stripe/checkout-handler.php';
	require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
	require_once dirname( __DIR__ ) . '/inc/core/seller-order-notifications.php';

	final class SellerOrderNotificationsTest extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			$GLOBALS['notification_mail']   = $GLOBALS['test_blog_stack'] = array();
			$GLOBALS['notification_events'] = array();
			$GLOBALS['notification_down']   = false;
			$GLOBALS['notification_order_meta'] = array();
			$GLOBALS['test_site_options']   = $GLOBALS['test_blog_options'] = array();
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
		private function notify( array $types ): array {
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
			$GLOBALS['test_orders'][1] = new class( $items ) {
				private $items;
				public function __construct( $items ) { $this->items = $items; }
				public function get_items() { return $this->items; }
				public function get_order_number() { return 123; }
				public function get_billing_first_name() { return 'Test'; }
				public function get_billing_last_name() { return 'Buyer'; }
				public function get_address( $type ) { return array( 'address_1' => '123 Main' ); }
				public function get_meta( $key ) { return $GLOBALS['notification_order_meta'][ $key ] ?? ''; }
			};
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
		public function test_mixed_order_groups_each_seller_once_and_excludes_platform_items(): void {
			$mail = $this->notify( array( 'artist', 'venue', 'promoter', 'venue', null ) );
			$this->assertCount( 2, $mail, 'Artist roster only.' );
			$this->assertCount( 2, $GLOBALS['notification_events'] );
			$venue = $this->events_message( 0 );
			$this->assertStringContainsString( 'Product 11', $venue['body_html'] );
			$this->assertStringContainsString( 'Product 13', $venue['body_html'] );
			$this->assertStringNotContainsString( 'Product 10', $venue['body_html'] );
			$this->assertSame( 'promoter', $this->events_message( 1 )['seller']['type'] );
		}
		public function test_payout_uses_the_commerce_fee(): void {
			// Two $20 lines at the legacy 10% default: $40 - $4.
			$mail = $this->notify( array( 'artist', 'artist' ) );
			$this->assertStringContainsString( '<strong>Your Payout:</strong> $36.00', $mail[0]['context']['body_html'] );
			$this->assertStringNotContainsString( 'shipping', $mail[0]['context']['body_html'] );

			$GLOBALS['notification_mail']                             = array();
			$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'merch_percent' => 15 );
			$mail = $this->notify( array( 'artist', 'artist' ) );
			$this->assertStringContainsString( '<strong>Your Payout:</strong> $34.00', $mail[0]['context']['body_html'] );
		}
		public function test_email_explains_the_shipping_hold(): void {
			$GLOBALS['notification_order_meta'] = array( '_extrachill_shipping_hold_minor' => 500 );
			$body = $this->notify( array( 'artist' ) )[0]['context']['body_html'];
			$this->assertStringContainsString( 'The buyer paid $5.00 shipping', $body );
			$this->assertStringContainsString( 'paid out to you when you mark the order shipped', $body );
		}
	}
}
