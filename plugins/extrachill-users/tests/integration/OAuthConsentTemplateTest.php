<?php
/**
 * The branded consent screen serves two OAuth grants that verify different
 * nonce actions.
 *
 * wp-native-auth renders this template for both the authorization code flow
 * and the RFC 8628 device flow, passing the action each one verifies in
 * $args['nonce_action']. The template previously hardcoded the code-flow
 * action, so every device approval failed its nonce check with a 403 —
 * reproduced against production before this test existed.
 *
 * The plugin's own tests did not catch it because they exercise the default
 * unbranded template; the breakage only exists for hosts that override it,
 * which is what the filter seam is for.
 */

class Test_OAuth_Consent_Template extends WP_UnitTestCase {

	/**
	 * Register the theme-owned handle the badges stylesheet depends on.
	 *
	 * The bare test theme does not provide it, so enqueuing during render
	 * would raise an unregistered-dependency notice that has nothing to do
	 * with what is under test. The underlying coupling is tracked in #401.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! wp_style_is( 'extrachill-root', 'registered' ) ) {
			wp_register_style( 'extrachill-root', false, array(), null );
		}
	}

	/**
	 * Render the branded template and return its markup.
	 *
	 * @param array<string,mixed> $overrides View args to merge.
	 * @return string Rendered HTML.
	 */
	private function render( array $overrides = array() ): string {
		$args = array_merge(
			array(
				'client_name'      => 'Test Client',
				'client_uri'       => '',
				'client_id'        => 'test-client-id',
				'scope'            => 'account',
				'resource'         => '',
				'bundle'           => 'test-bundle',
				'signature'        => 'test-signature',
				'authorize_action' => 'ignored-by-this-template',
			),
			$overrides
		);

		ob_start();
		include EXTRACHILL_USERS_PLUGIN_DIR . 'templates/oauth-consent.php';

		return (string) ob_get_clean();
	}

	/**
	 * Extract the rendered _wpnonce value.
	 */
	private function rendered_nonce( string $html ): string {
		preg_match( '/name="_wpnonce"[^>]*value="([^"]+)"/', $html, $m );

		return isset( $m[1] ) ? $m[1] : '';
	}

	/**
	 * Every nonce-action case, in one render pass.
	 *
	 * Deliberately a single test rather than four. The template renders full
	 * page chrome, and the bare test theme ships no header.php or footer.php,
	 * so rendering emits deprecation notices that WP_UnitTestCase fails on
	 * unless they are declared. Those notices fire only once per process, so
	 * splitting these into separate tests makes the declaration correct in
	 * whichever test happens to render first and wrong in every one after it
	 * — a test that passes or fails on execution order rather than on
	 * behavior. One render pass, one declaration, order-independent.
	 */
	public function test_consent_form_renders_the_nonce_action_for_each_grant(): void {
		$this->setExpectedDeprecated( 'Theme without header.php' );
		$this->setExpectedDeprecated( 'Theme without footer.php' );

		$device = $this->rendered_nonce(
			$this->render(
				array(
					'nonce_action'   => 'wp_native_auth_oauth_device',
					'is_device_flow' => true,
				)
			)
		);

		$this->assertNotSame( '', $device, 'the consent form rendered no nonce field' );
		$this->assertSame(
			1,
			wp_verify_nonce( $device, 'wp_native_auth_oauth_device' ),
			'the device flow verifies wp_native_auth_oauth_device, so the form must carry that action'
		);

		// Without this, the assertion above would also hold for a template
		// that ignored the argument entirely.
		$this->assertFalse(
			(bool) wp_verify_nonce( $device, 'wp_native_auth_oauth_consent' ),
			'a device nonce must not validate against the consent action'
		);

		$code = $this->rendered_nonce(
			$this->render( array( 'nonce_action' => 'wp_native_auth_oauth_consent' ) )
		);

		$this->assertSame(
			1,
			wp_verify_nonce( $code, 'wp_native_auth_oauth_consent' ),
			'the authorization code flow must still get the consent action'
		);

		// A caller predating the argument keeps working.
		$fallback = $this->rendered_nonce( $this->render() );

		$this->assertSame(
			1,
			wp_verify_nonce( $fallback, 'wp_native_auth_oauth_consent' ),
			'a missing nonce_action must fall back to the consent action'
		);
	}
}
