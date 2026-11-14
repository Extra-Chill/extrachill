<?php
/**
 * Contract tests for `wp extrachill shop`: each command is a thin adapter
 * that resolves its Shop ability on the Shop site and passes exact input.
 *
 * Run standalone: php tests/ShopCommandTest.php
 */

// phpcs:disable -- Standalone test doubles for WP-CLI, WordPress and abilities.

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$messages[] = 'TABLE(' . $format . '):' . implode( ',', $fields ) . ':' . count( $items );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\get_flag_value' ) ) {
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}
	}
}

namespace {
	use ExtraChill\CLI\Commands\Shop\BoostCommand;
	use ExtraChill\CLI\Commands\Shop\OrderCommand;
	use ExtraChill\CLI\Commands\Shop\OrdersCommand;
	use ExtraChill\CLI\Commands\Shop\SellerCommand;
	use ExtraChill\CLI\Commands\Shop\SellersCommand;
	use ExtraChill\CLI\Commands\Shop\WebhookCommand;
	use ExtraChill\CLI\CommandRegistry;

	// Real WordPress (managed PHPUnit harness) already declares these doubles.
	if ( ! function_exists( 'wp_get_ability' ) && ! class_exists( 'WP_Error', false ) ) {

		define( 'ABSPATH', __DIR__ . '/' );

		class ShopCommandTestError extends RuntimeException {}
		class ShopCommandTestAborted extends RuntimeException {}

		class WP_CLI {
			public static $messages = array();
			public static $confirm  = true;

			public static function error( $message ) {
				throw new ShopCommandTestError( $message );
			}

			public static function success( $message ) {
				self::$messages[] = 'Success: ' . $message;
			}

			public static function log( $message ) {
				self::$messages[] = $message;
			}

			public static function line( $message ) {
				self::$messages[] = $message;
			}

			public static function confirm( $question, $assoc_args = array() ) {
				self::$messages[] = 'CONFIRM: ' . $question;
				if ( empty( $assoc_args['yes'] ) && ! self::$confirm ) {
					throw new ShopCommandTestAborted( 'aborted' );
				}
			}
		}

		class WP_Error {
			private $message;

			public function __construct( $code, $message ) {
				unset( $code );
				$this->message = $message;
			}

			public function get_error_message() {
				return $this->message;
			}
		}

		class ShopCommandTestAbility {
			public $name;
			public $schema = array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
			);

			public function __construct( $name ) {
				$this->name = $name;
			}

			public function get_input_schema() {
				return $this->schema;
			}

			public function execute( $input ) {
				$GLOBALS['shop_test_calls'][] = array( $this->name, $input );
				$response                     = $GLOBALS['shop_test_responses'][ $this->name ] ?? array();
				return is_callable( $response ) ? $response( $input ) : $response;
			}
		}

		$GLOBALS['shop_test_calls']     = array();
		$GLOBALS['shop_test_responses'] = array();
		$GLOBALS['shop_test_abilities'] = array();
		$GLOBALS['shop_test_blog_id']   = 3;

		function wp_get_ability( $name ) {
			if ( ! isset( $GLOBALS['shop_test_abilities'][ $name ] ) ) {
				$GLOBALS['shop_test_abilities'][ $name ] = new ShopCommandTestAbility( $name );
			}
			return $GLOBALS['shop_test_abilities'][ $name ];
		}
		function ec_get_blog_id( $key ) {
			return array(
				'shop'   => 3,
				'events' => 7,
			)[ $key ] ?? 0;
		}
		function ec_get_site_url( $key ) {
			return 'https://' . $key . '.extrachill.com';
		}
		function get_current_blog_id() {
			return $GLOBALS['shop_test_blog_id'];
		}
		function is_wp_error( $value ) {
			return $value instanceof WP_Error;
		}
		function wp_json_encode( $value, $flags = 0 ) {
			return json_encode( $value, $flags );
		}

		function shop_assert( $condition, $message ) {
			if ( ! $condition ) {
				throw new RuntimeException( $message . "\nCalls: " . var_export( $GLOBALS['shop_test_calls'], true ) . "\nMessages: " . var_export( WP_CLI::$messages, true ) );
			}
		}
		function shop_expect_error( callable $run, $needle, $message ) {
			try {
				$run();
			} catch ( ShopCommandTestError $error ) {
				shop_assert( false !== strpos( $error->getMessage(), $needle ), $message . ' (got: ' . $error->getMessage() . ')' );
				return;
			}
			throw new RuntimeException( $message . ' (no error raised)' );
		}
		function shop_reset() {
			$GLOBALS['shop_test_calls'] = array();
			WP_CLI::$messages           = array();
			WP_CLI::$confirm            = true;
		}

		require_once dirname( __DIR__ ) . '/inc/OwnerSiteAbility.php';
		require_once dirname( __DIR__ ) . '/inc/CommandRegistry.php';
		foreach ( array( 'ShopCommandBase', 'SellersCommand', 'SellerCommand', 'OrdersCommand', 'OrderCommand', 'WebhookCommand', 'BoostCommand' ) as $class ) {
			require_once dirname( __DIR__ ) . '/inc/Commands/Shop/' . $class . '.php';
		}

		// Registry wiring.
		$map = CommandRegistry::map();
		foreach ( array(
			'extrachill shop sellers' => SellersCommand::class,
			'extrachill shop seller'  => SellerCommand::class,
			'extrachill shop orders'  => OrdersCommand::class,
			'extrachill shop order'   => OrderCommand::class,
			'extrachill shop webhook' => WebhookCommand::class,
			'extrachill shop boost'   => BoostCommand::class,
		) as $command => $class ) {
			shop_assert( ( $map[ $command ] ?? null ) === $class, "Registry maps {$command}" );
		}

		// Owner site: other sites get the exact Shop URL and never reach an ability.
		shop_reset();
		$GLOBALS['shop_test_blog_id'] = 1;
		shop_expect_error( fn() => ( new SellersCommand() )->list_( array(), array() ), '--url=https://shop.extrachill.com', 'non-shop site is refused with the owner URL' );
		shop_assert( array() === $GLOBALS['shop_test_calls'], 'refused run executes nothing' );
		$GLOBALS['shop_test_blog_id'] = 3;

		// sellers list.
		shop_reset();
		$GLOBALS['shop_test_responses']['extrachill/shop-list-sellers'] = array(
			'sellers' => array(
				array(
					'key'      => 'artist:12',
					'name'     => 'Band',
					'products' => 2,
				),
			),
		);
		( new SellersCommand() )->list_( array(), array( 'type' => 'artist' ) );
		shop_assert( array( array( 'extrachill/shop-list-sellers', array( 'type' => 'artist' ) ) ) === $GLOBALS['shop_test_calls'], 'sellers list passes --type' );
		shop_assert( 'TABLE(table):seller,name,products,stripe,status,onboarded,ship_from,platform:1' === WP_CLI::$messages[0], 'sellers list prints a table' );

		// seller status: validated type:id, live Stripe status plus ship-from.
		shop_reset();
		shop_expect_error( fn() => ( new SellerCommand() )->status( array( 'artist:abc' ), array() ), 'Invalid seller', 'bad seller ref errors' );
		shop_expect_error( fn() => ( new SellerCommand() )->status( array( 'label:5' ), array() ), 'Invalid seller', 'unknown seller type errors' );
		shop_assert( array() === $GLOBALS['shop_test_calls'], 'invalid seller never reaches an ability' );
		$GLOBALS['shop_test_responses']['extrachill/shop-stripe-status']        = array(
			'connected' => true,
			'status'    => 'active',
		);
		$GLOBALS['shop_test_responses']['extrachill/shop-get-shipping-address'] = array(
			'is_set'  => false,
			'address' => array( 'city' => '' ),
		);
		( new SellerCommand() )->status( array( 'venue:1524' ), array() );
		$seller = array(
			'type' => 'venue',
			'id'   => 1524,
		);
		shop_assert(
		array(
			array( 'extrachill/shop-stripe-status', array( 'seller' => $seller ) ),
			array( 'extrachill/shop-get-shipping-address', array( 'seller' => $seller ) ),
		) === $GLOBALS['shop_test_calls'],
		'seller status calls stripe-status and get-shipping-address with the typed seller'
		);

		// orders list: optional seller, status, paging.
		shop_reset();
		$GLOBALS['shop_test_responses']['extrachill/shop-operator-list-orders'] = array(
			'orders'      => array(),
			'total'       => 0,
			'total_pages' => 0,
			'page'        => 1,
		);
		( new OrdersCommand() )->list_( array(), array() );
		shop_assert( array(
			'status'   => 'any',
			'page'     => 1,
			'per_page' => 20,
		) === $GLOBALS['shop_test_calls'][0][1], 'orders list defaults' );
		shop_assert( in_array( 'No orders found.', WP_CLI::$messages, true ), 'empty list says so' );
		( new OrdersCommand() )->list_( array(), array(
			'seller'   => 'promoter:8',
			'status'   => 'processing',
			'per-page' => '5',
		) );
		shop_assert( array(
			'status'   => 'processing',
			'page'     => 1,
			'per_page' => 5,
			'seller'   => array(
				'type' => 'promoter',
				'id'   => 8,
			),
		) === $GLOBALS['shop_test_calls'][1][1], 'orders list passes seller and status' );
		shop_expect_error( fn() => ( new OrdersCommand() )->list_( array(), array( 'page' => '0' ) ), '--page', 'bad page errors' );

		// order show.
		shop_reset();
		$GLOBALS['shop_test_responses']['extrachill/shop-get-order'] = array(
			'id'             => 55,
			'seller'         => array(
				'type' => 'artist',
				'id'   => 12,
			),
			'total'          => 30.0,
			'currency'       => 'USD',
			'total_refunded' => 0,
			'items'          => array(
				array(
					'item_id'        => 1,
					'priority_boost' => array(
						'event'   => 'show',
						'granted' => false,
					),
				),
			),
			'refunds'        => array(),
		);
		( new OrderCommand() )->show( array( '55' ), array() );
		shop_assert( array( array( 'extrachill/shop-get-order', array( 'id' => 55 ) ) ) === $GLOBALS['shop_test_calls'], 'order show passes the id' );
		shop_expect_error( fn() => ( new OrderCommand() )->show( array( '5x' ), array() ), 'Order ID', 'non-numeric id errors' );

		// order refund: confirmation, seller from the order, --yes, --amount guard.
		shop_reset();
		$GLOBALS['shop_test_responses']['extrachill/shop-refund-order'] = array(
			'success'       => true,
			'order_id'      => 55,
			'refund_amount' => 30.0,
		);
		WP_CLI::$confirm = false;
		try {
			( new OrderCommand() )->refund( array( '55' ), array( 'reason' => 'Damaged' ) );
			shop_assert( false, 'declined confirmation aborts' );
		} catch ( ShopCommandTestAborted $aborted ) {
			unset( $aborted );
		}
		shop_assert( array( 'extrachill/shop-get-order' ) === array_column( $GLOBALS['shop_test_calls'], 0 ), 'declined refund never executes the refund ability' );
		shop_assert( false !== strpos( WP_CLI::$messages[0], 'Refund the full amount of order 55 (artist:12, total 30 USD' ), 'confirmation names the order, seller and total' );

		shop_reset();
		WP_CLI::$confirm = false;
		( new OrderCommand() )->refund( array( '55' ), array(
			'reason' => 'Damaged',
			'yes'    => true,
		) );
		shop_assert( array(
			'extrachill/shop-refund-order',
			array(
				'id'     => 55,
				'reason' => 'Damaged',
				'seller' => array(
					'type' => 'artist',
					'id'   => 12,
				),
			),
		) === $GLOBALS['shop_test_calls'][1], '--yes refunds with the order seller' );
		shop_assert( 'Success: Refunded 30 on order 55.' === end( WP_CLI::$messages ), 'refund reports success' );

		shop_reset();
		( new OrderCommand() )->refund( array( '55' ), array(
			'seller' => 'venue:3',
			'yes'    => true,
		) );
		shop_assert( array(
			'type' => 'venue',
			'id'   => 3,
		) === $GLOBALS['shop_test_calls'][1][1]['seller'], '--seller overrides the order seller' );

		shop_reset();
		shop_expect_error( fn() => ( new OrderCommand() )->refund( array( '55' ), array(
			'amount' => '5',
			'yes'    => true,
		) ), 'only issues full refunds', '--amount is refused while the ability is full-refund only' );
		shop_assert( array() === $GLOBALS['shop_test_calls'], 'refused --amount executes nothing' );

		shop_reset();
		wp_get_ability( 'extrachill/shop-refund-order' )->schema['properties']['amount'] = array( 'type' => 'number' );
		( new OrderCommand() )->refund( array( '55' ), array(
			'amount' => '5.50',
			'yes'    => true,
		) );
		shop_assert( 5.5 === $GLOBALS['shop_test_calls'][1][1]['amount'], '--amount passes through once the ability declares it' );
		shop_expect_error( fn() => ( new OrderCommand() )->refund( array( '55' ), array(
			'amount' => '-1',
			'yes'    => true,
		) ), 'positive number', 'negative amount errors' );

		shop_reset();
		$GLOBALS['shop_test_responses']['extrachill/shop-get-order'] = array(
			'id'     => 56,
			'seller' => null,
		);
		shop_expect_error( fn() => ( new OrderCommand() )->refund( array( '56' ), array( 'yes' => true ) ), 'platform order', 'platform orders are refused' );

		// webhook replay.
		shop_reset();
		shop_expect_error( fn() => ( new WebhookCommand() )->replay( array( 'pi_123' ), array() ), 'evt_', 'non-event id errors' );
		shop_expect_error( fn() => ( new WebhookCommand() )->replay( array( 'evt_1' ), array( 'account' => 'cus_1' ) ), 'acct_', 'bad account errors' );
		$GLOBALS['shop_test_responses']['extrachill/shop-replay-webhook'] = array(
			'event_id' => 'evt_1',
			'type'     => 'checkout.session.completed',
			'account'  => 'acct_9',
			'livemode' => false,
			'routed'   => true,
		);
		( new WebhookCommand() )->replay( array( 'evt_1' ), array( 'account' => 'acct_9' ) );
		shop_assert( array(
			array(
				'extrachill/shop-replay-webhook',
				array(
					'event_id' => 'evt_1',
					'account'  => 'acct_9',
				),
			),
		) === $GLOBALS['shop_test_calls'], 'replay passes event and account' );
		shop_assert( 'Success: Replayed evt_1 (checkout.session.completed on acct_9, test).' === end( WP_CLI::$messages ), 'replay reports the event' );

		// boost retry.
		shop_reset();
		$GLOBALS['shop_test_responses']['extrachill/shop-retry-priority-boost'] = array(
			'order_id' => 77,
			'items'    => array(
				array(
					'item_id' => 1,
					'result'  => 'granted',
				),
			),
		);
		( new BoostCommand() )->retry( array( '77' ), array() );
		shop_assert( array( array( 'extrachill/shop-retry-priority-boost', array( 'order_id' => 77 ) ) ) === $GLOBALS['shop_test_calls'], 'boost retry passes the order id' );
		shop_assert( 'Success: Every Priority Boost on this order is granted.' === end( WP_CLI::$messages ), 'boost retry reports success' );
		$GLOBALS['shop_test_responses']['extrachill/shop-retry-priority-boost'] = array(
			'order_id' => 77,
			'items'    => array(
				array(
					'item_id' => 1,
					'result'  => 'error',
					'error'   => 'rest_forbidden',
				),
			),
		);
		shop_expect_error( fn() => ( new BoostCommand() )->retry( array( '77' ), array() ), '1 Priority Boost grant(s) still failing', 'failed grants exit non-zero' );

		// Ability errors surface as CLI errors.
		shop_reset();
		$GLOBALS['shop_test_responses']['extrachill/shop-get-order'] = new WP_Error( 'order_not_found', 'Order not found.' );
		shop_expect_error( fn() => ( new OrderCommand() )->show( array( '9' ), array() ), 'Order not found.', 'ability WP_Error becomes a CLI error' );

		fwrite( STDOUT, "ShopCommand tests passed.\n" );
	}
}
