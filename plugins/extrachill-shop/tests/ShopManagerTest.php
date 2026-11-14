<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Seller shop manager: selection, page provisioning, and seller-scoped order updates. */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
require_once dirname( __DIR__ ) . '/inc/core/commission-settings.php';
require_once dirname( __DIR__ ) . '/inc/core/order-seller-index.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-update-order-status.php';

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public function __construct( $id ) { $this->ID = $id; }
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
		return $GLOBALS['test_pages'][ $path ] ?? null;
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		$GLOBALS['test_inserted_posts'][] = $postarr;
		return 500 + count( $GLOBALS['test_inserted_posts'] );
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['test_user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['test_user_meta'][ $user_id ][ $key ] ?? '';
	}
}

final class ShopManagerTestOrder {
	public $meta     = array();
	public $saved    = 0;
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function set_status( $status, $note = '' ) { $this->meta['status'] = $status; }
	public function save() { ++$this->saved; }
}

final class ShopManagerTest extends TestCase {
	private const SELLERS = array(
		array( 'type' => 'artist', 'id' => 12, 'name' => 'The Band' ),
		array( 'type' => 'venue', 'id' => 1524, 'name' => 'Lo-Fi Brewing' ),
	);

	protected function setUp(): void {
		extrachill_shop_reset_seller_permission_cache();
		$GLOBALS['test_options']        = array();
		$GLOBALS['test_pages']          = array();
		$GLOBALS['test_inserted_posts'] = array();
		$GLOBALS['test_user_meta']      = array();
		$GLOBALS['test_abilities']      = array();
		$GLOBALS['test_orders']         = array();
		$GLOBALS['test_user_id']        = 7;
	}

	public function test_explicit_seller_is_selected_and_never_falls_back(): void {
		$this->assertSame( self::SELLERS[1], extrachill_shop_resolve_manager_seller( self::SELLERS, 'venue:1524', 'artist:12' ) );
		$this->assertNull( extrachill_shop_resolve_manager_seller( self::SELLERS, 'venue:99', 'artist:12' ) );
		$this->assertNull( extrachill_shop_resolve_manager_seller( self::SELLERS, '', 'artist:12' ) );
	}

	public function test_without_explicit_seller_uses_recent_then_first(): void {
		$this->assertSame( self::SELLERS[1], extrachill_shop_resolve_manager_seller( self::SELLERS, null, 'venue:1524' ) );
		$this->assertSame( self::SELLERS[0], extrachill_shop_resolve_manager_seller( self::SELLERS, null, 'promoter:3' ) );
		$this->assertSame( self::SELLERS[0], extrachill_shop_resolve_manager_seller( self::SELLERS, null, '' ) );
		$this->assertNull( extrachill_shop_resolve_manager_seller( array(), null, '' ) );
	}

	public function test_manager_url_preselects_each_seller_type(): void {
		$base = 'https://shop.extrachill.com/manage/';
		$this->assertSame( $base, extrachill_shop_get_shop_manager_url() );
		foreach ( array( 'artist' => 12, 'venue' => 1524, 'promoter' => 8 ) as $type => $id ) {
			$this->assertSame( $base . '?seller=' . $type . ':' . $id, extrachill_shop_get_shop_manager_url( array( 'type' => $type, 'id' => $id ) ) );
		}
		$this->assertSame( $base, extrachill_shop_get_shop_manager_url( array( 'type' => 'label', 'id' => 3 ) ) );
		$this->assertSame( $base, extrachill_shop_get_shop_manager_url( array( 'type' => 'venue', 'id' => 0 ) ) );
	}

	public function test_seller_keys_parse_strictly(): void {
		$this->assertSame( array( 'type' => 'promoter', 'id' => 8 ), extrachill_shop_parse_seller_key( 'promoter:8' ) );
		foreach ( array( 'shop:1', 'venue:0', 'venue:-2', 'venue:1x', 'venue', '', null, array() ) as $bad ) {
			$this->assertNull( extrachill_shop_parse_seller_key( $bad ) );
		}
	}

	public function test_manage_page_is_created_once(): void {
		extrachill_shop_maybe_provision_manager_page();
		extrachill_shop_maybe_provision_manager_page();

		$this->assertCount( 1, $GLOBALS['test_inserted_posts'] );
		$this->assertSame( 'manage', $GLOBALS['test_inserted_posts'][0]['post_name'] );
		$this->assertSame( '<!-- wp:extrachill/shop-manager /-->', $GLOBALS['test_inserted_posts'][0]['post_content'] );
		$this->assertSame( 501, get_option( EXTRACHILL_SHOP_MANAGER_PAGE_OPTION ) );
	}

	public function test_existing_manage_page_is_adopted_not_overwritten(): void {
		$GLOBALS['test_pages']['manage'] = new WP_Post( 42 );

		extrachill_shop_maybe_provision_manager_page();

		$this->assertSame( array(), $GLOBALS['test_inserted_posts'] );
		$this->assertSame( 42, get_option( EXTRACHILL_SHOP_MANAGER_PAGE_OPTION ) );
	}

	public function test_select_seller_ability_is_owner_authorized_and_remembers_selection(): void {
		extrachill_shop_register_select_seller_ability();
		$ability = $GLOBALS['test_abilities']['extrachill/shop-select-seller'];

		$this->assertSame( 'extrachill_shop_authorize_seller_input', $ability['permission_callback'] );
		$this->assertTrue( $ability['meta']['show_in_rest'] );

		$result = extrachill_shop_ability_select_seller( array( 'seller' => array( 'type' => 'venue', 'id' => 1524 ) ) );
		$this->assertSame( array( 'seller' => 'venue:1524' ), $result );
		$this->assertSame( 'venue:1524', get_user_meta( 7, EXTRACHILL_SHOP_RECENT_SELLER_META, true ) );

		$this->assertTrue( is_wp_error( extrachill_shop_ability_select_seller( array( 'seller' => array( 'type' => 'venue', 'id' => '1524' ) ) ) ) );
	}

	public function test_order_status_ability_accepts_typed_sellers(): void {
		$GLOBALS['test_abilities'] = array();
		extrachill_shop_register_update_order_status_ability();
		$ability = $GLOBALS['test_abilities']['extrachill/shop-update-order-status'];

		$this->assertSame( 'extrachill_shop_authorize_seller_input', $ability['permission_callback'] );
		$this->assertArrayHasKey( 'seller', $ability['input_schema']['properties'] );
		$this->assertSame( array( 'id', 'seller', 'status' ), $ability['input_schema']['required'] );
	}

	public function test_order_membership_reads_the_canonical_seller_key(): void {
		$order                             = new ShopManagerTestOrder();
		$order->meta['_extrachill_seller'] = 'venue:1524';
		$this->assertTrue( extrachill_shop_order_has_seller( $order, array( 'type' => 'venue', 'id' => 1524 ) ) );
		$this->assertFalse( extrachill_shop_order_has_seller( $order, array( 'type' => 'promoter', 'id' => 1524 ) ) );
		$this->assertFalse( extrachill_shop_order_has_seller( $order, array( 'type' => 'venue', 'id' => 15 ) ) );

		$platform                             = new ShopManagerTestOrder();
		$platform->meta['_extrachill_seller'] = '';
		$this->assertNull( extrachill_shop_get_order_seller( $platform ) );
		$this->assertFalse( extrachill_shop_order_has_seller( $platform, array( 'type' => 'artist', 'id' => 12 ) ) );
	}

	public function test_order_status_refuses_a_seller_not_on_the_order(): void {
		$order                             = new ShopManagerTestOrder();
		$order->meta['_extrachill_seller'] = 'artist:12';
		$GLOBALS['test_orders'][90]        = $order;

		$result = extrachill_shop_ability_update_order_status( array(
			'id'              => 90,
			'seller'          => array( 'type' => 'venue', 'id' => 1524 ),
			'status'          => 'completed',
			'tracking_number' => '1Z',
		) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 0, $order->saved );
		$this->assertArrayNotHasKey( '_extrachill_shipment', $order->meta );
	}
}
