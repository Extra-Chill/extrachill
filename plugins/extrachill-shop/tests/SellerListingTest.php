<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Order seller record and seller-scoped listing contract tests. */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SellerCommerceTest.php';
require_once __DIR__ . '/fixtures/OrderUtil.php';
require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
require_once dirname( __DIR__ ) . '/inc/core/artist-taxonomy.php';
require_once dirname( __DIR__ ) . '/inc/core/order-seller-index.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-list-orders.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-list-products.php';

function wc_get_orders( $args ) {
	$GLOBALS['listing_queries'][] = $args;
	$orders                       = array_values( $GLOBALS['listing_orders'] );
	$key                          = $args['extrachill_seller'] ?? $args['meta_query'][0]['value'] ?? null;
	if ( $key ) {
		$orders = array_values( array_filter( $orders, static function ( $order ) use ( $key, $args ) {
			return $key === $order->get_meta( '_extrachill_seller' ) && in_array( 'wc-' . $order->get_status(), $args['status'], true );
		} ) );
	}
	$total  = count( $orders );
	$orders = array_slice( $orders, ( $args['paged'] - 1 ) * $args['limit'], $args['limit'] );
	return empty( $args['paginate'] ) ? $orders : (object) array(
		'orders'        => $orders,
		'total'         => $total,
		'max_num_pages' => (int) ceil( $total / $args['limit'] ),
	);
}
class WP_Query {
	public $posts;
	public function __construct( $args ) {
		$GLOBALS['listing_product_query'] = $args;
		$this->posts                      = array();
	}
}
class SellerListingOrder {
	public $meta  = array();
	public $saves = 0;
	private $products;
	public function __construct( $products ) { $this->products = $products; }
	public function get_items( $type = 'line_item' ) {
		return array_map( static function ( $id ) {
			return new class( $id ) {
				private $id;
				public function __construct( $id ) { $this->id = $id; }
				public function get_product_id() { return $this->id; }
				public function get_quantity() { return 1; }
				public function get_total() { return 10; }
			};
		}, $this->products );
	}
	public function get_total() { return 10 * count( $this->products ); }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save_meta_data() { ++$this->saves; }
	public function get_status() { return 'processing'; }
	public function get_address( $type ) { return array(); }
	public function get_id() { return 1; }
	public function get_order_number() { return '1'; }
	public function get_date_created() { return null; }
	public function get_billing_first_name() { return ''; }
	public function get_billing_last_name() { return ''; }
	public function get_billing_email() { return ''; }
}
final class SellerListingTest extends TestCase {
	protected function setUp(): void {
		extrachill_shop_reset_seller_permission_cache();
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
		$GLOBALS['test_options']                           = array();
		$GLOBALS['test_post_meta']                         = array();
		$GLOBALS['test_user_id']                           = 1226;
		$GLOBALS['test_artist_members']                    = array( 1226 => array( 1226 ) );
		$GLOBALS['listing_queries']                        = array();
		$GLOBALS['test_owner_callback']                    = static function ( $site, $method, $route, $args ) {
			$input = $args['query']['input'];
			return array_merge( $input, array(
				'can_edit'            => true,
				'can_manage_finances' => 999 !== $input['id'],
			) );
		};
		foreach ( array( 'artist', 'venue', 'promoter' ) as $i => $type ) {
			extrachill_shop_set_product_seller( $i + 1, array(
				'type' => $type,
				'id'   => 1226,
			) );
		}
		$GLOBALS['listing_orders'] = array(
			new SellerListingOrder( array( 1, 1 ) ),
			new SellerListingOrder( array( 2 ) ),
			new SellerListingOrder( array( 2, 2 ) ),
			new SellerListingOrder( array( 3 ) ),
			new SellerListingOrder( array( 2, 3 ) ),
			new SellerListingOrder( array( 99 ) ),
		);
		foreach ( $GLOBALS['listing_orders'] as $order ) {
			extrachill_shop_index_order_seller( $order );
		}
	}
	public function test_order_seller_is_one_key_derived_from_line_items(): void {
		$orders = $GLOBALS['listing_orders'];
		$this->assertSame( 'artist:1226', $orders[0]->meta['_extrachill_seller'] );
		$this->assertSame( 'venue:1226', $orders[2]->meta['_extrachill_seller'] );
		$this->assertSame( 'promoter:1226', $orders[3]->meta['_extrachill_seller'] );
		$this->assertArrayNotHasKey( '_extrachill_seller', $orders[4]->meta, 'Mixed orders have no seller.' );
		$this->assertArrayNotHasKey( '_extrachill_seller', $orders[5]->meta, 'Platform orders have no seller.' );

		// Replays are no-ops.
		extrachill_shop_index_order_seller( $orders[0] );
		$this->assertSame( 1, $orders[0]->saves );
	}
	public function test_checkout_freezes_the_order_seller(): void {
		$order                                        = $GLOBALS['listing_orders'][1];
		$order->meta['_extrachill_checkout_session']  = 'cs_1';
		extrachill_shop_set_product_seller( 2, array(
			'type' => 'promoter',
			'id'   => 1226,
		) );
		extrachill_shop_index_order_seller( $order );
		$this->assertSame( 'venue:1226', $order->meta['_extrachill_seller'] );
	}
	public function test_order_listing_scopes_all_seller_types_and_paginates(): void {
		foreach ( array(
			'artist'   => 1,
			'venue'    => 2,
			'promoter' => 1,
		) as $type => $total ) {
			$input  = array(
				'seller'   => array(
					'type' => $type,
					'id'   => 1226,
				),
				'per_page' => 1,
			);
			$result = extrachill_shop_ability_list_orders( $input );
			$this->assertSame( $total, $result['total'] );
			$this->assertCount( 1, $result['orders'] );
			foreach ( $result['orders'][0]['items'] as $item ) {
				$this->assertSame( $type, extrachill_shop_get_product_seller( $item['product_id'] )['type'] );
			}
			$this->assertSame( $total, $result['needs_fulfillment_count'] );
			$input['page'] = $total + 1;
			$this->assertSame( array(), extrachill_shop_ability_list_orders( $input )['orders'] );
		}
		$this->assertSame( 'missing_seller', extrachill_shop_ability_list_orders( array( 'artist_id' => 1226 ) )->get_error_code() );
		foreach ( $GLOBALS['listing_queries'] as $args ) {
			$this->assertGreaterThan( 0, $args['limit'] );
		}
	}
	public function test_product_queries_use_seller_meta_and_bounded_pagination(): void {
		foreach ( array( 'artist', 'venue', 'promoter' ) as $type ) {
			extrachill_shop_ability_list_products( array(
				'seller'   => array(
					'type' => $type,
					'id'   => 1226,
				),
				'page'     => 2,
				'per_page' => 5,
			) );
			$args = $GLOBALS['listing_product_query'];
			$this->assertSame( 5, $args['posts_per_page'] );
			$this->assertSame( 2, $args['paged'] );
			$this->assertSame( '_extrachill_shop_seller_type', $args['meta_query'][0]['key'] );
			$this->assertSame( $type, $args['meta_query'][0]['value'] );
			$this->assertSame( 1226, $args['meta_query'][1]['value'] );
		}
		$this->assertSame( 'missing_seller', extrachill_shop_ability_list_products( array( 'artist_id' => 1226 ) )->get_error_code() );
	}
	public function test_unauthorized_seller_is_refused_by_both_abilities(): void {
		$input = array(
			'seller' => array(
				'type' => 'venue',
				'id'   => 999,
			),
		);
		$this->assertSame( 'cannot_manage_seller', extrachill_shop_authorize_seller_input( $input )->get_error_code() );
		$GLOBALS['test_abilities'] = array();
		extrachill_shop_register_list_orders_ability();
		extrachill_shop_register_list_products_ability();
		foreach ( $GLOBALS['test_abilities'] as $ability ) {
			$this->assertSame( 'extrachill_shop_authorize_seller_input', $ability['permission_callback'] );
		}
	}
	public function test_hpos_uses_native_meta_query(): void {
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
		$result = extrachill_shop_ability_list_orders( array(
			'seller'   => array(
				'type' => 'venue',
				'id'   => 1226,
			),
			'per_page' => 1,
			'page'     => 2,
		) );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 1, $result['orders'] );
		$args = $GLOBALS['listing_queries'][1];
		$this->assertSame( array(
			array(
				'key'   => '_extrachill_seller',
				'value' => 'venue:1226',
			),
		), $args['meta_query'] );
	}
	public function test_cpt_adapter_adds_exact_seller_constraint(): void {
		$query = extrachill_shop_order_seller_cpt_query( array(), array( 'extrachill_seller' => 'venue:1226' ) );
		$this->assertSame( array(
			'key'   => '_extrachill_seller',
			'value' => 'venue:1226',
		), $query['meta_query'][0] );
	}
}
