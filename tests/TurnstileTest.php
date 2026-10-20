<?php
/**
 * Tests for the Cloudflare Turnstile integration.
 *
 * Covers the network-wide captcha verification + render surface that lives in
 * inc/core/extrachill-turnstile.php and gates every form endpoint on the
 * platform (newsletter, events, contact, auth). Cloudflare HTTP calls are
 * intercepted with the `pre_http_request` filter so the suite is deterministic
 * and offline.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * @group turnstile
 */
class TurnstileTest extends WP_UnitTestCase {

	/**
	 * The most recent siteverify request body captured by the HTTP shim.
	 *
	 * @var array<string,mixed>|null
	 */
	private $captured_request_body = null;

	/**
	 * Rejection action arguments, in firing order.
	 *
	 * @var array
	 */
	private $rejections = array();

	public function set_up() {
		parent::set_up();
		add_action(
			'extrachill_turnstile_rejected',
			function ( $error_code, $request_or_token ) {
				$this->rejections[] = array( $error_code, $request_or_token );
			},
			10,
			2
		);
	}

	public function tear_down() {
		remove_all_actions( 'extrachill_turnstile_rejected' );
		$this->rejections = array();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'extrachill_bypass_turnstile_verification' );
		delete_site_option( 'ec_turnstile_site_key' );
		delete_site_option( 'ec_turnstile_secret_key' );
		$this->captured_request_body = null;
		parent::tear_down();
	}

	/**
	 * Intercept the Cloudflare siteverify call and return a canned response.
	 *
	 * @param int    $code HTTP status code to return.
	 * @param string $body Raw response body.
	 */
	private function stub_siteverify( int $code, string $body ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $code, $body ) {
				if ( false === strpos( (string) $url, 'challenges.cloudflare.com' ) ) {
					return $preempt;
				}
				$this->captured_request_body = $args['body'] ?? null;
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => $code,
						'message' => '',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Make the siteverify call fail at the transport layer (WP_Error).
	 */
	private function stub_siteverify_transport_error(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( (string) $url, 'challenges.cloudflare.com' ) ) {
					return $preempt;
				}
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			},
			10,
			3
		);
	}

	// ---------------------------------------------------------------------
	// ec_verify_turnstile_response()
	// ---------------------------------------------------------------------

	public function test_verify_returns_true_on_cloudflare_success() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => true ) ) );

		$this->assertTrue( ec_verify_turnstile_response( 'valid-token' ) );
	}

	public function test_verify_returns_false_on_cloudflare_failure() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify(
			200,
			wp_json_encode(
				array(
					'success'     => false,
					'error-codes' => array( 'invalid-input-response' ),
				)
			)
		);

		$this->assertFalse( ec_verify_turnstile_response( 'bad-token' ) );
	}

	public function test_verify_returns_false_when_token_empty() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		// No HTTP stub: an empty token must short-circuit before any request.
		add_filter(
			'pre_http_request',
			static function () {
				throw new RuntimeException( 'siteverify must not be called for an empty token' );
			},
			10,
			3
		);

		$this->assertFalse( ec_verify_turnstile_response( '' ) );
	}

	public function test_verify_returns_false_when_secret_not_configured() {
		// Secret key intentionally absent.
		add_filter(
			'pre_http_request',
			static function () {
				throw new RuntimeException( 'siteverify must not be called without a secret key' );
			},
			10,
			3
		);

		$this->assertFalse( ec_verify_turnstile_response( 'some-token' ) );
	}

	public function test_verify_returns_false_on_non_200_response() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 500, 'upstream error' );

		$this->assertFalse( ec_verify_turnstile_response( 'token' ) );
	}

	public function test_verify_returns_false_on_transport_error() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify_transport_error();

		$this->assertFalse( ec_verify_turnstile_response( 'token' ) );
	}

	public function test_verify_returns_false_on_malformed_json() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, 'not-json-at-all' );

		$this->assertFalse( ec_verify_turnstile_response( 'token' ) );
	}

	public function test_verify_sends_secret_and_token_to_cloudflare() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-xyz' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => true ) ) );

		ec_verify_turnstile_response( 'the-token' );

		$this->assertIsArray( $this->captured_request_body );
		$this->assertSame( 'secret-xyz', $this->captured_request_body['secret'] );
		$this->assertSame( 'the-token', $this->captured_request_body['response'] );
	}

	public function test_verify_bypass_filter_returns_true_without_http() {
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		add_filter(
			'pre_http_request',
			static function () {
				throw new RuntimeException( 'siteverify must not be called when bypass is active' );
			},
			10,
			3
		);

		$this->assertTrue( ec_verify_turnstile_response( '' ) );
	}

	// ---------------------------------------------------------------------
	// ec_turnstile_check_request()
	// ---------------------------------------------------------------------

	public function test_check_request_returns_wp_error_when_token_missing() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$request = new WP_REST_Request( 'POST', '/test' );
		// No turnstile_response param set.

		$result = ec_turnstile_check_request( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'turnstile_missing_token', $result->get_error_code() );
		$this->assertSame( array( array( 'turnstile_missing_token', $request ) ), $this->rejections );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_request_returns_wp_error_on_invalid_token() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => false ) ) );

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_param( 'turnstile_response', 'bad-token' );

		$result = ec_turnstile_check_request( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'turnstile_failed', $result->get_error_code() );
		$this->assertSame( array( array( 'turnstile_failed', $request ) ), $this->rejections );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_request_returns_true_on_valid_token() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => true ) ) );

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_param( 'turnstile_response', 'good-token' );

		$this->assertTrue( ec_turnstile_check_request( $request ) );
		$this->assertSame( array(), $this->rejections );
	}

	public function test_check_request_rejection_preserves_raw_token() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => false ) ) );

		$this->assertWPError( ec_turnstile_check_request( '' ) );
		$this->assertWPError( ec_turnstile_check_request( 'bad-token' ) );
		$this->assertSame(
			array(
				array( 'turnstile_missing_token', '' ),
				array( 'turnstile_failed', 'bad-token' ),
			),
			$this->rejections
		);
	}

	public function test_check_request_accepts_raw_token_string() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => true ) ) );

		$this->assertTrue( ec_turnstile_check_request( 'good-token' ) );
	}

	public function test_check_request_bypass_returns_true() {
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );

		$request = new WP_REST_Request( 'POST', '/test' );
		// Even with no token, bypass short-circuits to true.
		$this->assertTrue( ec_turnstile_check_request( $request ) );
		$this->assertSame( array(), $this->rejections );
	}

	// ---------------------------------------------------------------------
	// ec_turnstile_permission_callback()
	// ---------------------------------------------------------------------

	public function test_permission_callback_passes_turnstile_then_runs_secondary() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => true ) ) );

		$secondary_ran = false;
		$callback      = ec_turnstile_permission_callback(
			function () use ( &$secondary_ran ) {
				$secondary_ran = true;
				return true;
			}
		);

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_param( 'turnstile_response', 'good-token' );

		$this->assertTrue( $callback( $request ) );
		$this->assertTrue( $secondary_ran, 'Secondary callback should run after Turnstile passes.' );
	}

	public function test_permission_callback_short_circuits_on_turnstile_failure() {
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );
		$this->stub_siteverify( 200, wp_json_encode( array( 'success' => false ) ) );

		$secondary_ran = false;
		$callback      = ec_turnstile_permission_callback(
			function () use ( &$secondary_ran ) {
				$secondary_ran = true;
				return true;
			}
		);

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_param( 'turnstile_response', 'bad-token' );

		$result = $callback( $request );

		$this->assertWPError( $result );
		$this->assertFalse( $secondary_ran, 'Secondary callback must not run when Turnstile fails.' );
	}

	// ---------------------------------------------------------------------
	// ec_render_turnstile_widget()
	// ---------------------------------------------------------------------

	public function test_render_returns_empty_when_not_configured() {
		// Neither site key nor secret key set.
		$this->assertSame( '', ec_render_turnstile_widget() );
	}

	public function test_render_outputs_cf_turnstile_div_with_sitekey() {
		update_site_option( 'ec_turnstile_site_key', 'site-key-abc' );
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );

		$html = ec_render_turnstile_widget();

		$this->assertStringContainsString( 'class="cf-turnstile"', $html );
		$this->assertStringContainsString( 'data-sitekey="site-key-abc"', $html );
	}

	public function test_render_merges_custom_attributes() {
		update_site_option( 'ec_turnstile_site_key', 'site-key-abc' );
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );

		$html = ec_render_turnstile_widget( array( 'data-size' => 'invisible' ) );

		$this->assertStringContainsString( 'data-size="invisible"', $html );
	}

	/**
	 * Regression guard for the dangling-callback bug (newsletter #17): the
	 * renderer must only emit attributes it was given, never inject a
	 * `data-callback` referencing an undefined JS function that would abort
	 * Cloudflare's implicit auto-render for sibling widgets on the page.
	 */
	public function test_render_does_not_inject_unsolicited_data_callback() {
		update_site_option( 'ec_turnstile_site_key', 'site-key-abc' );
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );

		$html = ec_render_turnstile_widget( array( 'data-size' => 'invisible' ) );

		$this->assertStringNotContainsString( 'data-callback', $html );
	}

	/**
	 * Explicit per-widget render (multisite #48) reads each widget's declared
	 * data-* attributes (including a consumer-supplied data-callback) off the
	 * `.cf-turnstile` element and maps them into the turnstile.render() options.
	 * The renderer must therefore still pass through a callback a consumer
	 * explicitly asks for — the boot script is what decides (per-widget, in
	 * isolation) whether the named global actually exists. This is the inverse
	 * guard to test_render_does_not_inject_unsolicited_data_callback(): never
	 * inject one, but never drop one the consumer set either.
	 */
	public function test_render_passes_through_consumer_supplied_data_callback() {
		update_site_option( 'ec_turnstile_site_key', 'site-key-abc' );
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );

		$html = ec_render_turnstile_widget(
			array( 'data-callback' => 'myConsumerCallback' )
		);

		$this->assertStringContainsString( 'data-callback="myConsumerCallback"', $html );
	}

	/**
	 * The default render still emits a `.cf-turnstile` element carrying the
	 * data-* attributes the explicit-render boot script reads (sitekey, size,
	 * theme, appearance). The boot keys entirely off these attributes, so the
	 * renderer's output contract is what makes per-widget render possible.
	 */
	public function test_render_emits_data_attributes_boot_reads() {
		update_site_option( 'ec_turnstile_site_key', 'site-key-abc' );
		update_site_option( 'ec_turnstile_secret_key', 'secret-123' );

		$html = ec_render_turnstile_widget();

		$this->assertStringContainsString( 'class="cf-turnstile"', $html );
		$this->assertStringContainsString( 'data-sitekey="site-key-abc"', $html );
		$this->assertStringContainsString( 'data-theme=', $html );
		$this->assertStringContainsString( 'data-appearance=', $html );
	}
}
