<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Focused seller index and listing contract tests. */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SellerCommerceTest.php';
require_once __DIR__ . '/fixtures/OrderUtil.php';
require_once dirname( __DIR__ ) . '/inc/core/order-seller-index.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-list-orders.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-list-products.php';

function wc_get_order_statuses() { return array(
	'wc-processing' => 'Processing',
	'wc-completed'  => 'Completed',
); }
function wp_next_scheduled() { return false; }
function wp_schedule_single_event() {}
function wc_get_orders( $args ) {
	$GLOBALS['listing_queries'][] = $args;
	$orders                       = array_values( $GLOBALS['listing_orders'] );
	$key                          = $args['extrachill_order_seller'] ?? $args['meta_query'][0]['value'] ?? null;
	if ( $key ) {
		$orders = array_values( array_filter( $orders, static function ( $order ) use ( $key, $args ) {
			return in_array( $key, $order->sellers, true ) && in_array( 'wc-' . $order->get_status(), $args['status'], true );
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
	public $sellers = array();
	public $saves   = 0;
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
	public function get_meta( $key, $single = true ) {
		if ( '_extrachill_order_seller' === $key && ! $single ) {
			return array_map( static function ( $value ) { return (object) array( 'value' => $value );
			}, $this->sellers );
		}
		return '';
	}
	public function delete_meta_data( $key ) { $this->sellers = array(); }
	public function add_meta_data( $key, $value, $unique ) { $this->sellers[] = $value; }
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
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
		$GLOBALS['test_options']                           = array();
		$GLOBALS['test_post_meta']                         = array();
		$GLOBALS['test_user_id']                           = 1226;
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
			new SellerListingOrder( array( 2, 3 ) ),
		);
	}
	public function test_index_handles_single_and_multiple_sellers_without_duplicates(): void {
		foreach ( $GLOBALS['listing_orders'] as $order ) {
			extrachill_shop_index_order_sellers( $order );
			extrachill_shop_index_order_sellers( $order );
			$this->assertSame( 1, $order->saves );
		}
		$this->assertSame( array( 'artist:1226' ), $GLOBALS['listing_orders'][0]->sellers );
		$this->assertSame( array( 'promoter:1226', 'venue:1226' ), $GLOBALS['listing_orders'][2]->sellers );
	}
	public function test_backfill_is_idempotent(): void {
		extrachill_shop_backfill_order_sellers();
		$this->assertSame( 1, get_option( 'extrachill_shop_order_seller_index_complete' ) );
		unset( $GLOBALS['test_options']['extrachill_shop_order_seller_index_complete'] );
		extrachill_shop_backfill_order_sellers();
		foreach ( $GLOBALS['listing_orders'] as $order ) {
			$this->assertSame( 1, $order->saves );
		}
	}
	public function test_order_listing_scopes_all_seller_types_and_paginates(): void {
		extrachill_shop_backfill_order_sellers();
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
		$this->assertSame( 1, extrachill_shop_ability_list_orders( array( 'artist_id' => 1226 ) )['total'] );
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
			if ( 'artist' === $type ) {
				$this->assertSame( '_artist_profile_id', $args['meta_query'][0]['key'] );
			} else {
				$this->assertSame( $type, $args['meta_query'][0]['value'] );
				$this->assertSame( 1226, $args['meta_query'][1]['value'] );
			}
		}
		extrachill_shop_ability_list_products( array( 'artist_id' => 1226 ) );
		$this->assertSame( 1226, $GLOBALS['listing_product_query']['meta_query'][0]['value'] );
	}
	public function test_unauthorized_seller_is_refused_by_both_abilities(): void {
		$input = array(
			'seller' => array(
				'type' => 'venue',
				'id'   => 999,
			),
		);
		$this->assertInstanceOf( WP_Error::class, extrachill_shop_ability_list_orders( $input ) );
		$this->assertInstanceOf( WP_Error::class, extrachill_shop_ability_list_products( $input ) );
		$GLOBALS['test_abilities'] = array();
		extrachill_shop_register_list_orders_ability();
		extrachill_shop_register_list_products_ability();
		foreach ( $GLOBALS['test_abilities'] as $ability ) {
			$this->assertSame( 'extrachill_shop_authorize_seller_input', $ability['permission_callback'] );
		}
	}
	public function test_hpos_uses_native_meta_query(): void {
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
		extrachill_shop_backfill_order_sellers();
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
				'key'   => '_extrachill_order_seller',
				'value' => 'venue:1226',
			),
		), $args['meta_query'] );
	}
	public function test_cpt_adapter_adds_exact_seller_constraint(): void {
		$query = extrachill_shop_order_seller_cpt_query( array(), array( 'extrachill_order_seller' => 'venue:1226' ) );
		$this->assertSame( array(
			'key'   => '_extrachill_order_seller',
			'value' => 'venue:1226',
		), $query['meta_query'][0] );
	}
}
