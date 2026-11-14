<?php
/**
 * Shop reads commerce credentials only through the network's
 * ec_commerce_credential() (#1402). No filter indirection, no local resolvers.
 */

use PHPUnit\Framework\TestCase;

final class CommerceCredentialTest extends TestCase {
	/**
	 * Separate process: other suites stub shipping-settings.php functions.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_shippo_key_comes_from_the_network_credential(): void {
		$GLOBALS['ec_commerce_credentials']      = array();
		$GLOBALS['ec_commerce_credential_reads'] = array();
		if ( ! function_exists( 'ec_commerce_credential' ) ) {
			function ec_commerce_credential( string $provider, string $field ): string {
				$GLOBALS['ec_commerce_credential_reads'][] = $provider . '.' . $field;
				return $GLOBALS['ec_commerce_credentials'][ $provider ][ $field ] ?? '';
			}
		}
		require_once dirname( __DIR__ ) . '/inc/shipping/shipping-settings.php';

		$this->assertSame( '', extrachill_shop_get_shippo_api_key() );
		$this->assertFalse( extrachill_shop_is_shippo_configured() );

		$GLOBALS['ec_commerce_credentials']['shippo']['api_key'] = 'shippo_test_123';

		$this->assertSame( 'shippo_test_123', extrachill_shop_get_shippo_api_key() );
		$this->assertTrue( extrachill_shop_is_shippo_configured() );
		$this->assertContains( 'shippo.api_key', $GLOBALS['ec_commerce_credential_reads'] );
	}

	public function test_stripe_readers_call_the_network_credential_directly(): void {
		$dir     = dirname( __DIR__ ) . '/inc/stripe/';
		$connect = file_get_contents( $dir . 'stripe-connect.php' );
		$hooks   = file_get_contents( $dir . 'webhooks.php' );

		$this->assertStringContainsString( "ec_commerce_credential( 'stripe', 'secret_key' )", $connect );
		$this->assertStringNotContainsString( 'function extrachill_shop_stripe_is_configured', $connect );
		$this->assertStringNotContainsString( 'function extrachill_shop_get_stripe_publishable_key', $connect );
		$this->assertStringContainsString( "ec_commerce_credential( 'stripe', 'webhook_secret' )", $hooks );
		$this->assertStringContainsString( "ec_commerce_credential( 'stripe', 'connect_webhook_secret' )", $hooks );
	}

	public function test_no_credential_filter_indirection_remains(): void {
		$root = dirname( __DIR__ );
		$this->assertFileDoesNotExist( $root . '/inc/auth/resolvers.php' );

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = file_get_contents( $file->getPathname() );
			$this->assertDoesNotMatchRegularExpression(
				'/extrachill_(stripe_(secret_key|publishable_key|webhook_secret|connect_webhook_secret|connect_client_id)|shippo_api_key)\'|extrachill_shop_resolve_(stripe|shippo)_/',
				$source,
				$file->getPathname() . ' still reads a commerce credential through a filter or resolver.'
			);
		}
	}
}
