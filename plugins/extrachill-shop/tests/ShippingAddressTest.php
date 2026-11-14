<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Seller-owned shipping address regression tests. */
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

function add_option( $key, $value, $deprecated = '', $autoload = false ) {
	if ( false !== get_option( $key, false ) ) {
		return false; }
	return update_option( $key, $value );
}
if ( ! function_exists( 'extrachill_shop_is_shippo_configured' ) ) {
	function extrachill_shop_is_shippo_configured() { return true; }
}
if ( ! function_exists( 'extrachill_shop_shippo_create_label' ) ) {
	/** Captures the shipment; returns `$GLOBALS['shippo_label']` when a test sets one. */
	function extrachill_shop_shippo_create_label( $from, $to ) {
		$GLOBALS['shipping_from'] = $from;
		return $GLOBALS['shippo_label'] ?? new WP_Error( 'shippo_test', 'Captured shipment.' );
	}
}
require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
require_once dirname( __DIR__ ) . '/inc/core/commission-settings.php';
require_once dirname( __DIR__ ) . '/inc/core/order-seller-index.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-list-orders.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-get-shipping-address.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-update-shipping-address.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-create-shipping-label.php';

final class ShippingAddressTest extends TestCase {
	protected function setUp(): void {
		extrachill_shop_reset_seller_permission_cache();
		$GLOBALS['test_options']        = array();
		$GLOBALS['test_post_meta']      = array();
		$GLOBALS['test_blog_stack'] = array();
		$GLOBALS['test_user_id']        = 812;
		$GLOBALS['test_owner_callback'] = static function () {
			return array(
				'type'                => 'venue',
				'id'                  => 998,
				'can_manage_finances' => false,
			);
		};
	}

	private function address(): array {
		return array(
			'name'    => 'Seller',
			'street1' => '123 Main',
			'street2' => '',
			'city'    => 'Charleston',
			'state'   => 'SC',
			'zip'     => '29401',
			'country' => 'US',
		);
	}

	public function test_get_and_update_for_every_seller_type(): void {
		foreach ( array( 'artist', 'venue', 'promoter' ) as $type ) {
			$seller = array(
				'type' => $type,
				'id'   => 24,
			);
			$input  = array_merge( array( 'seller' => $seller ), $this->address() );
			$this->assertTrue( extrachill_shop_ability_update_shipping_address( $input )['success'] );
			$this->assertTrue( extrachill_shop_ability_update_shipping_address( $input )['success'] );
			$result = extrachill_shop_ability_get_shipping_address( $input );
			$this->assertSame( $seller, $result['seller'] );
			$this->assertSame( $this->address(), $result['address'] );
			$this->assertTrue( $result['is_set'] );
		}
		$this->assertSame( 'missing_seller', extrachill_shop_ability_get_shipping_address( array( 'artist_id' => 24 ) )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_post_meta'] );
	}

	public function test_lazy_migration_never_overwrites_shop_record(): void {
		$seller = array(
			'type' => 'artist',
			'id'   => 25,
		);
		$GLOBALS['test_post_meta'][25]['_shipping_address'] = $this->address();
		$this->assertSame( $this->address(), extrachill_shop_get_seller_shipping_address( $seller ) );
		$this->assertSame( array(), $GLOBALS['test_blog_stack'] );
		$updated = array_merge( $this->address(), array( 'name' => 'New address' ) );
		extrachill_shop_set_seller_shipping_address( $seller, $updated );
		$this->assertSame( $updated, extrachill_shop_get_seller_shipping_address( $seller ) );
		$this->assertSame( $this->address(), $GLOBALS['test_post_meta'][25]['_shipping_address'] );
	}

	public function test_abilities_refuse_unauthorized_seller(): void {
		$GLOBALS['test_abilities'] = array();
		extrachill_shop_register_get_shipping_address_ability();
		extrachill_shop_register_update_shipping_address_ability();
		extrachill_shop_register_create_shipping_label_ability();
		foreach ( $GLOBALS['test_abilities'] as $ability ) {
			$this->assertSame( 'extrachill_shop_authorize_seller_input', $ability['permission_callback'] );
			$error = call_user_func( $ability['permission_callback'], array(
				'seller' => array(
					'type' => 'venue',
					'id'   => 998,
				),
			) );
			$this->assertSame( 'cannot_manage_seller', $error->get_error_code() );
		}
	}

	public function test_update_requires_existing_address_shape(): void {
		$result = extrachill_shop_ability_update_shipping_address( array(
			'seller' => array(
				'type' => 'venue',
				'id'   => 24,
			),
			'name'   => 'Only name',
		) );
		$this->assertSame( 'invalid_address', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_options'] );
	}

	public function test_labels_use_shop_address_for_order_seller(): void {
		foreach ( array( 'artist', 'venue', 'promoter' ) as $type ) {
			$seller = array(
				'type' => $type,
				'id'   => 26,
			);
			extrachill_shop_set_seller_shipping_address( $seller, $this->address() );
			$order                       = new class( $seller ) {
				private $seller;
				public function __construct( $seller ) { $this->seller = $seller; }
				public function get_meta( $key ) {
					return '_extrachill_seller' === $key ? extrachill_shop_seller_ref_key( $this->seller ) : '';
				}
				public function get_items( $type = 'line_item' ) { return array(); }
				public function get_total() { return 0; }
				public function get_address( $type ) { return array(
					'address_1' => '456 Destination',
					'country'   => 'US',
				); }
			};
			$GLOBALS['test_orders'][123] = $order;
			$result                      = extrachill_shop_ability_create_shipping_label( array(
				'order_id' => 123,
				'seller'   => $seller,
			) );
			$this->assertSame( 'shippo_test', $result->get_error_code() );
			$this->assertSame( $this->address(), $GLOBALS['shipping_from'] );
		}
	}
}
