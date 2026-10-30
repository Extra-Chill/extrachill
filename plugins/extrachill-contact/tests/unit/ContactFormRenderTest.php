<?php
/**
 * Contact form nonce configuration for cached and authenticated pages.
 *
 * @package ExtraChillContact
 */

declare(strict_types=1);

class Test_Contact_Form_Render extends WP_UnitTestCase {

	/** @return array<string, mixed> */
	private function render_config(): array {
		ob_start();
		try {
			include dirname( __DIR__, 2 ) . '/blocks/contact-form/render.php';
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}

		$this->assertSame( 1, preg_match( '/window\.ecContactConfig = (.+);/', $html, $matches ) );
		$config = json_decode( $matches[1], true );
		$this->assertIsArray( $config );
		return $config;
	}

	public function test_anonymous_render_has_no_rest_nonce(): void {
		wp_set_current_user( 0 );

		$config = $this->render_config();

		$this->assertSame( '', $config['restNonce'] );
	}

	public function test_logged_in_render_has_valid_rest_nonce(): void {
		wp_set_current_user( self::factory()->user->create() );

		$config = $this->render_config();

		$this->assertIsString( $config['restNonce'] );
		$this->assertNotSame( '', $config['restNonce'] );
		$this->assertNotFalse( wp_verify_nonce( $config['restNonce'], 'wp_rest' ) );
	}
}
