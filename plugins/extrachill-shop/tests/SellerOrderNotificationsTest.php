<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Seller order email integration with the Events-owned recipient helper. */
namespace ExtraChillEvents\Core {
	class VenueMembershipRepository {
		public function list_for_venue( $id, $filters ) {
			return array_slice( $GLOBALS['notification_members'], $filters['offset'], $filters['limit'] );
		}
	}
	class VenueAuthorization {
		const ACTION_MANAGE_FINANCES = 'manage_finances';
		public function __construct( $repository = null ) {}
		public function can( $user, $venue, $action ) {
			return $user === 1 && $venue === 9 && $action === self::ACTION_MANAGE_FINANCES;
		}
	}
	class PromoterAuthoritySchema {
		public static function is_ready() { return true; }
	}
	class PromoterAuthorityRepository {
		public function get_organization( $id ) { return array( 'status' => $GLOBALS['notification_org_status'] ); }
		public function list_memberships( $id ) { return $GLOBALS['notification_members']; }
	}
}

namespace {
	function get_userdata( $id ) { return (object) array( 'user_email' => "user$id@example.com" ); }
	function get_term( $id, $type ) { return (object) array(
		'taxonomy' => $type,
		'name'     => ucfirst( $type ),
	); }
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
	require_once dirname( __DIR__ ) . '/inc/core/commission-settings.php';
	require_once dirname( __DIR__ ) . '/inc/core/artist-product-meta.php';
	require_once dirname( __DIR__ ) . '/inc/stripe/checkout-handler.php';
	require_once dirname( __DIR__, 2 ) . '/extrachill-events/inc/Abilities/CommerceSellerNotifications.php';
	require_once dirname( __DIR__ ) . '/inc/core/seller-order-notifications.php';

	final class SellerOrderNotificationsTest extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			$GLOBALS['notification_mail']       = $GLOBALS['test_blog_stack'] = array();
			$GLOBALS['notification_org_status'] = 'active';
			$GLOBALS['notification_members']    = array(
				array(
					'user_id'  => 1,
					'status'   => 'active',
					'is_owner' => true,
				),
				array(
					'user_id'  => 2,
					'status'   => 'active',
					'is_owner' => true,
				),
				array(
					'user_id'  => 3,
					'status'   => 'revoked',
					'is_owner' => true,
				),
				array(
					'user_id'  => 6,
					'status'   => 'active',
					'is_owner' => false,
				),
			);
			$GLOBALS['test_options']            = $GLOBALS['test_post_meta'] = array();
		}
		private function notify( array $types ): array {
			$items = array();
			foreach ( $types as $i => $type ) {
				$id = $i + 10;
				if ( $type ) {
					extrachill_shop_set_product_seller( $id, array(
						'type' => $type,
						'id'   => 9,
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
			};
			extrachill_shop_notify_sellers_of_order( 1 );
			$this->assertSame( array(), $GLOBALS['test_blog_stack'] );
			return $GLOBALS['notification_mail'];
		}
		public function test_artist_roster_is_unchanged(): void {
			$mail = $this->notify( array( 'artist' ) );
			$this->assertSame( array( 'user4@example.com', 'user5@example.com' ), array_column( $mail, 'to' ) );
			$this->assertStringContainsString( 'seller=artist:9', $mail[0]['context']['cta_url'] );
		}
		public function test_venue_only_notifies_explicit_finance_authority(): void {
			$mail = $this->notify( array( 'venue' ) );
			$this->assertSame( array( 'user1@example.com' ), array_column( $mail, 'to' ) );
			$this->assertStringContainsString( 'Venue', $mail[0]['subject'] );
		}
		public function test_promoter_notifies_active_owners(): void {
			$mail = $this->notify( array( 'promoter' ) );
			$this->assertSame( array( 'user1@example.com', 'user2@example.com' ), array_column( $mail, 'to' ) );
		}
		public function test_inactive_promoter_is_not_notified(): void {
			$GLOBALS['notification_org_status'] = 'revoked';
			$this->assertSame( array(), $this->notify( array( 'promoter' ) ) );
		}
		public function test_mixed_order_groups_each_seller_once_and_excludes_platform_items(): void {
			$mail = $this->notify( array( 'artist', 'venue', 'promoter', 'venue', null ) );
			$this->assertCount( 5, $mail );
			$venue = $mail[2];
			$this->assertStringContainsString( 'Product 11', $venue['context']['body_html'] );
			$this->assertStringContainsString( 'Product 13', $venue['context']['body_html'] );
			$this->assertStringNotContainsString( 'Product 10', $venue['context']['body_html'] );
			$this->assertStringContainsString( 'seller=venue:9', $venue['context']['cta_url'] );
		}
	}
}
