<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Contract tests for the operator abilities behind `wp extrachill shop`. */

namespace Stripe {
	if ( ! class_exists( '\Stripe\Event', false ) ) {
		class Event {
			public static function retrieve( $id, $options = array() ) {
				$GLOBALS['operator_event_requests'][] = array( $id, $options );
				if ( isset( $GLOBALS['operator_event_error'] ) ) {
					throw new \RuntimeException( $GLOBALS['operator_event_error'] );
				}
				return $GLOBALS['operator_event'];
			}
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/SellerListingTest.php';
	require_once __DIR__ . '/TicketTest.php';
	require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
	require_once dirname( __DIR__ ) . '/inc/abilities/shop-operator.php';

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $capability ) {
			return in_array( $capability, $GLOBALS['operator_caps'] ?? array(), true );
		}
	}
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args ) {
			$GLOBALS['operator_post_queries'][] = $args;
			return $GLOBALS['operator_product_ids'] ?? array();
		}
	}

	final class ShopOperatorTestItem {
		private $id;
		public function __construct( $id ) { $this->id = $id; }
		public function get_id() { return $this->id; }
		public function get_product_id() { return 99; }
		public function get_name() { return 'Event Priority Boost'; }
		public function get_quantity() { return 1; }
		public function get_total() { return 25.0; }
		public function get_meta( $key ) {
			return array(
				'priority_boost_event_id'        => 101,
				'priority_boost_event_reference' => 'event-slug',
				'priority_boost_event_title'     => 'Event title',
			)[ $key ] ?? '';
		}
	}

	final class ShopOperatorTestOrder {
		public $sellers = array();
		public $meta    = array();
		public $saves   = 0;
		public $notes   = array();
		private $id;
		private $items;
		private $paid;

		public function __construct( $id, array $items = array(), $paid = true ) {
			$this->id    = $id;
			$this->items = $items;
			$this->paid  = $paid;
		}
		public function get_id() { return $this->id; }
		public function get_type() { return 'shop_order'; }
		public function get_items( $type = 'line_item' ) { return $this->items; }
		public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function add_order_note( $note ) { $this->notes[] = $note; }
		public function save() { ++$this->saves; }
		public function is_paid() { return $this->paid; }
		public function get_status() { return 'processing'; }
		public function get_order_number() { return (string) $this->id; }
		public function get_date_created() { return null; }
		public function get_date_paid() { return null; }
		public function get_billing_first_name() { return 'Pat'; }
		public function get_billing_last_name() { return 'Buyer'; }
		public function get_billing_email() { return 'pat@example.com'; }
		public function get_payment_method() { return 'extrachill_stripe_checkout'; }
		public function get_currency() { return 'USD'; }
		public function get_total() { return 30.0; }
		public function get_shipping_total() { return 5.0; }
		public function get_total_refunded() { return 0.0; }
		public function get_refunds() { return array(); }
	}

	final class ShopOperatorAbilitiesTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['test_abilities']          = array();
			$GLOBALS['test_options']            = array();
			$GLOBALS['test_post_meta']          = array();
			$GLOBALS['test_orders']             = array();
			$GLOBALS['test_owner_calls']        = array();
			$GLOBALS['listing_orders']          = array();
			$GLOBALS['listing_queries']         = array();
			$GLOBALS['operator_caps']           = array( 'manage_woocommerce' );
			$GLOBALS['operator_product_ids']    = array();
			$GLOBALS['operator_event_requests'] = array();
			unset( $GLOBALS['operator_event_error'] );
			\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
			$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route ) {
				if ( str_contains( $route, 'grant-event-priority-boost' ) ) {
					return priority_boost_owner_response();
				}
				if ( str_contains( $route, 'artist-get' ) ) {
					return array( 'id' => 8121, 'name' => 'Operator Band' );
				}
				if ( str_contains( $route, 'get-commerce-seller' ) ) {
					return array( 'type' => 'venue', 'id' => 8122, 'name' => 'Operator Venue' );
				}
				return new WP_Error( 'unexpected', $route );
			};
		}

		public function test_registers_five_operator_abilities_gated_on_manage_woocommerce(): void {
			extrachill_shop_register_operator_abilities();
			$names = array( 'extrachill/shop-list-sellers', 'extrachill/shop-operator-list-orders', 'extrachill/shop-get-order', 'extrachill/shop-replay-webhook', 'extrachill/shop-retry-priority-boost' );
			$this->assertSame( $names, array_keys( $GLOBALS['test_abilities'] ) );
			foreach ( $names as $name ) {
				$this->assertSame( 'extrachill_shop_operator_permission', $GLOBALS['test_abilities'][ $name ]['permission_callback'] );
			}
			$this->assertTrue( extrachill_shop_operator_permission() );
			$GLOBALS['operator_caps'] = array();
			$this->assertFalse( extrachill_shop_operator_permission() );
		}

		public function test_list_sellers_merges_product_owners_and_stripe_records(): void {
			$GLOBALS['operator_product_ids']                  = array( 501, 502 );
			$GLOBALS['test_post_meta'][501]['_artist_profile_id'] = 8121;
			$GLOBALS['test_post_meta'][502]['_artist_profile_id'] = 8121;
			$GLOBALS['test_options']['extrachill_shop_stripe_seller_venue:8122'] = array( 'account_id' => 'acct_v', 'status' => 'active', 'onboarding_complete' => true );
			$GLOBALS['test_options']['extrachill_shop_stripe_seller_bogus']       = array( 'account_id' => 'acct_x' );

			$sellers = extrachill_shop_ability_list_sellers()['sellers'];

			$this->assertSame( array( 'artist:8121', 'venue:8122' ), array_column( $sellers, 'key' ) );
			$this->assertSame( array( 2, 0 ), array_column( $sellers, 'products' ) );
			$this->assertSame( array( 'Operator Band', 'Operator Venue' ), array_column( $sellers, 'name' ) );
			$this->assertSame( array( '', 'acct_v' ), array_column( $sellers, 'stripe_account' ) );
			$this->assertTrue( $sellers[1]['onboarding_complete'] );
			$this->assertSame( array( 'venue:8122' ), array_column( extrachill_shop_ability_list_sellers( array( 'type' => 'venue' ) )['sellers'], 'key' ) );
		}

		public function test_operator_list_orders_filters_by_seller_index_and_status(): void {
			$a          = new ShopOperatorTestOrder( 11 );
			$a->sellers = array( 'artist:8121' );
			$a->meta['_extrachill_seller'] = array( 'type' => 'artist', 'id' => 8121 );
			$b          = new ShopOperatorTestOrder( 12 );
			$b->sellers = array( 'venue:8122' );
			$GLOBALS['listing_orders'] = array( $a, $b );

			$all = extrachill_shop_ability_operator_list_orders( array() );
			$this->assertSame( 2, $all['total'] );
			$this->assertSame( array( 'wc-processing', 'wc-completed' ), $GLOBALS['listing_queries'][0]['status'] );

			$one = extrachill_shop_ability_operator_list_orders( array( 'seller' => array( 'type' => 'artist', 'id' => 8121 ), 'status' => 'processing' ) );
			$this->assertSame( 'artist:8121', $GLOBALS['listing_queries'][1]['extrachill_order_seller'] );
			$this->assertSame( array( 'wc-processing' ), $GLOBALS['listing_queries'][1]['status'] );
			$this->assertSame( array( 11 ), array_column( $one['orders'], 'id' ) );
			$this->assertSame( 'artist:8121', $one['orders'][0]['seller'] );

			$this->assertSame( 'invalid_status', extrachill_shop_ability_operator_list_orders( array( 'status' => 'shipped' ) )->get_error_code() );
			$this->assertSame( 'invalid_seller', extrachill_shop_ability_operator_list_orders( array( 'seller' => array( 'type' => 'label', 'id' => 1 ) ) )->get_error_code() );
		}

		public function test_get_order_reports_seller_payment_refs_and_boost_receipts(): void {
			$order = new ShopOperatorTestOrder( 44, array( new ShopOperatorTestItem( 10 ) ) );
			$order->meta['_extrachill_order_seller']    = 'promoter:9';
			$order->meta['_extrachill_stripe_account']  = 'acct_p';
			$order->meta['_extrachill_payment_intent']  = 'pi_1';
			$GLOBALS['test_orders'][44] = $order;

			$result = extrachill_shop_ability_get_order( array( 'id' => 44 ) );

			$this->assertSame( array( 'type' => 'promoter', 'id' => 9 ), $result['seller'] );
			$this->assertSame( 'acct_p', $result['stripe_account'] );
			$this->assertSame( 'pi_1', $result['payment_intent'] );
			$this->assertSame( 'Event Priority Boost', $result['items'][0]['name'] );
			$this->assertSame( array( 'event' => 'event-slug', 'title' => 'Event title', 'granted' => false ), $result['items'][0]['priority_boost'] );
			$order->meta['_extrachill_priority_boost_receipt_10'] = array( 'operation_id' => 'x' );
			$this->assertTrue( extrachill_shop_ability_get_order( array( 'id' => 44 ) )['items'][0]['priority_boost']['granted'] );
			$this->assertSame( 'order_not_found', extrachill_shop_ability_get_order( array( 'id' => 45 ) )->get_error_code() );
		}

		public function test_replay_fetches_event_from_stripe_and_routes_it(): void {
			$GLOBALS['operator_event'] = (object) array(
				'id'       => 'evt_1',
				'type'     => 'customer.created',
				'livemode' => false,
				'data'     => (object) array( 'object' => (object) array( 'metadata' => (object) array() ) ),
			);
			$result = extrachill_shop_ability_replay_webhook( array( 'event_id' => 'evt_1', 'account' => 'acct_9' ) );

			$this->assertSame( array( array( 'evt_1', array( 'stripe_account' => 'acct_9' ) ) ), $GLOBALS['operator_event_requests'] );
			$this->assertTrue( $result['routed'] );
			$this->assertSame( 'acct_9', $result['account'] );
			$this->assertSame( 'customer.created', $result['type'] );

			$this->assertSame( 'invalid_event', extrachill_shop_ability_replay_webhook( array( 'event_id' => 'pi_1' ) )->get_error_code() );
			$GLOBALS['operator_event_error'] = 'No such event';
			$this->assertSame( 'stripe_event_not_found', extrachill_shop_ability_replay_webhook( array( 'event_id' => 'evt_2' ) )->get_error_code() );
		}

		public function test_retry_grants_only_unreceipted_boosts_and_reports_each(): void {
			$order = new ShopOperatorTestOrder(
				46,
				array(
					new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ),
					new PriorityBoostTestItem( 11, 101, 'event-slug', 'Event title' ),
				)
			);
			$order->meta['_extrachill_priority_boost_receipt_10'] = array( 'operation_id' => 'earlier' );
			$GLOBALS['test_orders'][46] = $order;

			$result = extrachill_shop_ability_retry_priority_boost( array( 'order_id' => 46 ) );

			$this->assertSame( array( 'already_granted', 'granted' ), array_column( $result['items'], 'result' ) );
			$this->assertCount( 1, $GLOBALS['test_owner_calls'] );
			$this->assertSame( 'shop-order:46:item:11', $GLOBALS['test_owner_calls'][0]['args']['body']['input']['idempotency_key'] );
			$this->assertSame( priority_boost_owner_response()['receipt'], $order->meta['_extrachill_priority_boost_receipt_11'] );
			$this->assertSame( 1, $order->saves );

			// Re-running is a no-op.
			$again = extrachill_shop_ability_retry_priority_boost( array( 'order_id' => 46 ) );
			$this->assertSame( array( 'already_granted', 'already_granted' ), array_column( $again['items'], 'result' ) );
			$this->assertCount( 1, $GLOBALS['test_owner_calls'] );
		}

		public function test_retry_reports_owner_errors_and_unpaid_orders(): void {
			$GLOBALS['test_owner_callback'] = static fn() => new WP_Error( 'rest_forbidden', 'nope' );
			$GLOBALS['test_orders'][47]     = new ShopOperatorTestOrder( 47, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ) );
			$result = extrachill_shop_ability_retry_priority_boost( array( 'order_id' => 47 ) );
			$this->assertSame( 'rest_forbidden', $result['items'][0]['error'] );
			$this->assertSame( array( 'Priority boost pending: Events owner returned rest_forbidden.' ), $GLOBALS['test_orders'][47]->notes );

			$GLOBALS['test_orders'][48] = new ShopOperatorTestOrder( 48, array( new PriorityBoostTestItem( 10, 101, 'event-slug', 'Event title' ) ), false );
			$this->assertSame( 'priority_boost_order_not_paid', extrachill_shop_ability_retry_priority_boost( array( 'order_id' => 48 ) )['items'][0]['error'] );

			$GLOBALS['test_orders'][49] = new ShopOperatorTestOrder( 49 );
			$this->assertSame( 'no_priority_boost', extrachill_shop_ability_retry_priority_boost( array( 'order_id' => 49 ) )->get_error_code() );
			$this->assertSame( 'order_not_found', extrachill_shop_ability_retry_priority_boost( array( 'order_id' => 50 ) )->get_error_code() );
		}
	}
}
