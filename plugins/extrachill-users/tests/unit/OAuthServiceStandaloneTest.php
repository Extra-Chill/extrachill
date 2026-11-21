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

		// The runner needs a separate PHP process. Runtimes that cannot spawn one
		// (e.g. WordPress Playground) exit non-zero with no output; skip there
		// rather than report the runtime limitation as an OAuth failure.
		$probe      = array();
		$probe_code = 1;
		if ( function_exists( 'exec' ) && '' !== PHP_BINARY ) {
			exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'echo "ok";' ) . ' 2>/dev/null', $probe, $probe_code );
		}
		if ( 0 !== $probe_code || array( 'ok' ) !== $probe ) {
			$this->markTestSkipped( 'This runtime cannot spawn a PHP subprocess; run php tests/oauth/run.php directly.' );
		}

		$runner = dirname( __DIR__ ) . '/oauth/run.php';
		$output = array();
		$code   = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>/dev/null', $output, $code );

		$this->assertSame( 0, $code, implode( "\n", $output ) );
		$this->assertMatchesRegularExpression( '/^Passed \d+ OAuth checks\.$/', (string) end( $output ) );
	}
}
