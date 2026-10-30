<?php
/**
 * Frontend REST nonce coverage for cached anonymous pages.
 *
 * @package ExtraChillNewsletter\Tests\Integration
 */

final class NewsletterAssetsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		wp_set_current_user( 0 );
		wp_deregister_script( 'extrachill-newsletter' );
		parent::tear_down();
	}

	private function localized_params(): array {
		wp_deregister_script( 'extrachill-newsletter' );
		newsletter_enqueue_frontend_assets();

		$data = wp_scripts()->get_data( 'extrachill-newsletter', 'data' );
		$this->assertIsString( $data );
		$this->assertSame( 1, preg_match( '/var newsletterParams = (.+);/', $data, $matches ) );

		return json_decode( $matches[1], true );
	}

	public function test_anonymous_pages_have_no_rest_nonce(): void {
		wp_set_current_user( 0 );

		$params = $this->localized_params();

		$this->assertSame( '', $params['restNonce'] );
		$this->assertSame( rest_url(), $params['restUrl'] );
	}

	public function test_logged_in_pages_have_a_valid_rest_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$params = $this->localized_params();

		$this->assertNotEmpty( $params['restNonce'] );
		$this->assertNotFalse( wp_verify_nonce( $params['restNonce'], 'wp_rest' ) );
	}
}
