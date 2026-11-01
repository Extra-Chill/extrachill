<?php
/**
 * Smoke coverage for the inert extraction placeholder.
 *
 * @package ExtraChillBooking
 */

use PHPUnit\Framework\TestCase;

/**
 * Loads without WordPress services: any accidental bootstrap work should fail.
 */
final class PluginSmokeTest extends TestCase {

	/**
	 * The placeholder must load silently and keep its WordPress dependency.
	 *
	 * @return void
	 */
	public function test_plugin_loads_and_declares_dependency() {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/' );
		}

		$plugin = dirname( __DIR__ ) . '/extrachill-booking.php';
		$this->expectOutputString( '' );
		require $plugin;

		$header = file_get_contents( $plugin );
		$this->assertMatchesRegularExpression( '/^ \* Plugin Name: Extra Chill Booking$/m', $header );
		$this->assertMatchesRegularExpression( '/^ \* Requires Plugins: extrachill-events$/m', $header );
		$this->assertMatchesRegularExpression( '/^ \* Text Domain: extrachill-booking$/m', $header );
	}
}
