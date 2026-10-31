<?php
/**
 * Runs the standalone OAuth service checks (tests/oauth/run.php).
 *
 * The runner declares its own WordPress doubles and a throwaway RSA key, so it
 * runs in a separate PHP process instead of inside the WordPress test runtime.
 *
 * @package ExtraChill\Users\Tests
 */

class Test_OAuth_Service_Standalone extends WP_UnitTestCase {

	public function test_standalone_oauth_checks_pass() {
		if ( ! extension_loaded( 'openssl' ) ) {
			$this->markTestSkipped( 'OpenSSL is required for the OAuth JWT checks.' );
		}

		$runner = dirname( __DIR__ ) . '/oauth/run.php';
		$output = array();
		$code   = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>/dev/null', $output, $code );

		$this->assertSame( 0, $code, implode( "\n", $output ) );
		$this->assertMatchesRegularExpression( '/^Passed \d+ OAuth checks\.$/', (string) end( $output ) );
	}
}
