<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Block checkout payment method registration for the Stripe Checkout gateway. */
require_once __DIR__ . '/MerchCheckoutTest.php';
require_once __DIR__ . '/fixtures/AbstractPaymentMethodType.php';
require_once dirname( __DIR__ ) . '/inc/stripe/class-merch-gateway.php';

if ( ! defined( 'EXTRACHILL_SHOP_PLUGIN_URL' ) ) {
	define( 'EXTRACHILL_SHOP_PLUGIN_URL', 'https://shop.extrachill.com/wp-content/plugins/extrachill-shop/' );
}
if ( ! defined( 'EXTRACHILL_SHOP_VERSION' ) ) {
	define( 'EXTRACHILL_SHOP_VERSION', '0.0.0-test' );
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
		$GLOBALS['test_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'args' );
		return true;
	}
}

final class BlocksPaymentMethodTestRegistry {
	public $registered = array();
	public function register( $integration ) {
		$this->registered[ $integration->get_name() ] = $integration;
		return true;
	}
}

final class BlocksPaymentMethodTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		$GLOBALS['test_options'] = array();
		$GLOBALS['test_scripts'] = array();
	}

	private function registered_method(): ExtraChill_Merch_Blocks_Payment_Method {
		$registry = new BlocksPaymentMethodTestRegistry();
		extrachill_shop_register_merch_blocks_payment_method( $registry );
		$method = $registry->registered['extrachill_stripe_checkout'];
		$method->initialize();
		return $method;
	}

	public function test_registration_hook_is_wired(): void {
		$callbacks = array_column( $GLOBALS['test_actions']['woocommerce_blocks_payment_method_type_registration'] ?? array(), 'callback' );
		$this->assertContains( 'extrachill_shop_register_merch_blocks_payment_method', $callbacks );
	}

	public function test_registers_method_matching_gateway_id(): void {
		$method = $this->registered_method();
		$this->assertInstanceOf( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class, $method );
		$this->assertSame( ExtraChill_Merch_Gateway::ID, $method->get_name() );
		$this->assertSame( ( new ExtraChill_Merch_Gateway() )->id, $method->get_name() );
	}

	public function test_active_by_default_when_settings_never_saved(): void {
		$this->assertTrue( $this->registered_method()->is_active() );
		$this->assertSame( 'yes', ( new ExtraChill_Merch_Gateway() )->enabled );
	}

	public function test_respects_enabled_setting(): void {
		$GLOBALS['test_options']['woocommerce_extrachill_stripe_checkout_settings'] = array( 'enabled' => 'no' );
		$this->assertFalse( $this->registered_method()->is_active() );
		$this->assertSame( 'no', ( new ExtraChill_Merch_Gateway() )->enabled );

		$GLOBALS['test_options']['woocommerce_extrachill_stripe_checkout_settings'] = array( 'enabled' => 'yes' );
		$this->assertTrue( $this->registered_method()->is_active() );
		$this->assertSame( 'yes', ( new ExtraChill_Merch_Gateway() )->enabled );
	}

	public function test_script_registered_with_blocks_dependencies_and_shipped_asset(): void {
		$handles = $this->registered_method()->get_payment_method_script_handles();
		$this->assertSame( array( 'extrachill-shop-blocks-stripe-checkout' ), $handles );
		$script = $GLOBALS['test_scripts']['extrachill-shop-blocks-stripe-checkout'];
		foreach ( array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ) as $dep ) {
			$this->assertContains( $dep, $script['deps'] );
		}
		$this->assertStringEndsWith( 'assets/js/blocks-stripe-checkout.js', $script['src'] );

		$plugin_dir = dirname( __DIR__ );
		$js         = file_get_contents( $plugin_dir . '/assets/js/blocks-stripe-checkout.js' );
		$this->assertStringContainsString( "name: 'extrachill_stripe_checkout'", $js );
		$this->assertStringContainsString( 'registerPaymentMethod', $js );
		$rules = array_map( 'trim', file( $plugin_dir . '/.buildignore', FILE_IGNORE_NEW_LINES ) );
		foreach ( array( 'assets', 'assets/', '/assets', '/assets/', 'assets/js', 'assets/js/', '/assets/js/' ) as $exclusion ) {
			$this->assertNotContains( $exclusion, $rules );
		}
	}

	public function test_payment_method_data_uses_gateway_settings(): void {
		$data = $this->registered_method()->get_payment_method_data();
		$this->assertSame( 'Pay securely with Stripe', $data['title'] );
		$this->assertSame( "You'll be redirected to Stripe to pay securely.", $data['description'] );

		$GLOBALS['test_options']['woocommerce_extrachill_stripe_checkout_settings'] = array(
			'enabled'     => 'yes',
			'title'       => 'Card (Stripe)',
			'description' => 'Custom copy',
		);
		$data = $this->registered_method()->get_payment_method_data();
		$this->assertSame( 'Card (Stripe)', $data['title'] );
		$this->assertSame( 'Custom copy', $data['description'] );
	}
}
