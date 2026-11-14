<?php
/**
 * The extrachill.link editor's saves must pass the CORS preflight.
 *
 * wp.apiFetch sends PUT/PATCH/DELETE as POST + X-HTTP-Method-Override. If the
 * artist site does not allow that header cross-origin, the browser blocks
 * every editor save before it reaches the server.
 *
 * @package ExtraChillAPI
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) { // phpcs:ignore
		return true;
	}
}
require_once dirname( __DIR__, 2 ) . '/inc/auth/link-editor-cors.php';

class LinkEditorCorsMethodOverrideTest extends TestCase {

	public function test_core_default_headers_gain_method_override() {
		$core = array( 'Authorization', 'X-WP-Nonce', 'Content-Disposition', 'Content-MD5', 'Content-Type' );
		$out  = ec_api_allow_method_override_cors_header( $core );

		$this->assertContains( 'X-HTTP-Method-Override', $out );
		foreach ( $core as $header ) {
			$this->assertContains( $header, $out, 'Existing allowed headers must be preserved.' );
		}
	}

	public function test_header_is_not_duplicated() {
		$out = ec_api_allow_method_override_cors_header( array( 'Content-Type', 'x-http-method-override' ) );
		$this->assertCount( 2, $out );
	}

	public function test_filter_is_registered_on_the_core_cors_hook() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/auth/link-editor-cors.php' );
		$this->assertStringContainsString( "add_filter( 'rest_allowed_cors_headers', 'ec_api_allow_method_override_cors_header' )", $source );
	}

	public function test_plugin_boot_loads_the_cors_file() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/extrachill-api.php' );
		$this->assertStringContainsString( "inc/auth/link-editor-cors.php", $source );
	}
}
