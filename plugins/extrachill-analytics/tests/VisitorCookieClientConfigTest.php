<?php
/**
 * Tests for the client-side visitor-cookie mint boundary.
 *
 * The server no longer mints `ec_vid` (a `Set-Cookie` response is refused by
 * the edge cache); the browser mints it from config this plugin localizes.
 * These tests pin that boundary: which requests receive a mint-capable config,
 * that the computed domain stays the leading-dot network root, and that no
 * server code path mints or emits `Set-Cookie` anymore.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify visitor identity config is exposed only to eligible first-party requests.
 */
final class VisitorCookieClientConfigTest extends TestCase {
	/**
	 * Load the request-boundary helpers.
	 */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/inc/core/assets.php';
	}

	/**
	 * Set a normal first-party browser request before each test.
	 */
	protected function setUp(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = self::first_party_host();
		unset( $_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] );
	}

	/**
	 * A host this install actually treats as first-party.
	 *
	 * `extrachill_analytics_request_host_is_first_party()` compares
	 * `HTTP_HOST` against the resolved cookie domain, falling back to
	 * `home_url()`. Hard-coding a production host here made every
	 * "first-party" case in this file third-party under the managed harness —
	 * which is silent, because the assertions those tests make
	 * (`cookieName`, `cookieMaxAge`) are populated before the eligibility
	 * gate and pass either way. Derive it instead.
	 *
	 * @return string First-party host for this install.
	 */
	private static function first_party_host(): string {
		$domain = ltrim( extrachill_analytics_visitor_cookie_domain(), '.' );

		if ( '' !== $domain ) {
			return $domain;
		}

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $host ) ? $host : 'example.org';
	}

	/**
	 * Restore request fixtures after each test.
	 */
	protected function tearDown(): void {
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HOST'],
			$_SERVER['HTTP_SEC_GPC'],
			$_SERVER['HTTP_DNT'],
			$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ]
		);
	}

	/**
	 * Eligible first-party requests receive the full client mint config.
	 */
	public function test_first_party_public_request_receives_mint_config(): void {
		$config = extrachill_analytics_visitor_cookie_client_config();

		$this->assertSame( 'ec_vid', $config['cookieName'] );
		$this->assertSame( YEAR_IN_SECONDS, $config['cookieMaxAge'] );
	}

	/**
	 * The resolved cookie domain is the leading-dot form of whatever network
	 * this install actually is.
	 *
	 * Asserts the transform, not a literal host. The previous version of this
	 * test hard-coded `.extrachill.com` and seeded
	 * `$GLOBALS['extrachill_analytics_test_network_domain']`, both of which
	 * only worked because the deleted 1,020-line fake-WordPress bootstrap
	 * (#271) fabricated a network. Under the managed harness the install is a
	 * disposable site on an arbitrary host, so a production domain is not
	 * available and asserting one tests the fixture rather than the code.
	 *
	 * The leading dot is the part that carries meaning: it is what makes one
	 * visitor id span every subdomain instead of being re-minted per site.
	 */
	public function test_cookie_domain_is_the_leading_dot_form_of_the_network_root(): void {
		$domain = extrachill_analytics_visitor_cookie_domain();

		if ( '' === $domain ) {
			$this->assertFalse(
				defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN,
				'An empty cookie domain is only correct when neither COOKIE_DOMAIN nor a network supplies one.'
			);
			$this->assertFalse(
				function_exists( 'get_network' ) && get_network() && ! empty( get_network()->domain ),
				'A network with a domain must produce a leading-dot cookie domain, not an empty one.'
			);

			return;
		}

		$this->assertSame(
			'.',
			$domain[0],
			'A non-empty cookie domain must carry the leading dot that spans subdomains.'
		);
		$this->assertStringNotContainsString(
			'..',
			$domain,
			'The leading dot must be prefixed once, never doubled onto an already-dotted domain.'
		);
	}

	/**
	 * The documented filter is the override seam, and it wins outright.
	 */
	public function test_cookie_domain_filter_overrides_the_resolved_value(): void {
		$override = static fn(): string => '.override.test';

		add_filter( 'extrachill_analytics_visitor_cookie_domain', $override );
		$domain = extrachill_analytics_visitor_cookie_domain();
		remove_filter( 'extrachill_analytics_visitor_cookie_domain', $override );

		$this->assertSame( '.override.test', $domain );
	}

	/**
	 * The localized client config carries the resolved domain verbatim, so the
	 * browser mints against the same scope the server would have.
	 */
	public function test_client_config_carries_the_resolved_cookie_domain(): void {
		$domain = extrachill_analytics_visitor_cookie_domain();

		if ( '' === $domain ) {
			$this->assertSame(
				'',
				extrachill_analytics_visitor_cookie_client_config()['cookieDomain'],
				'With no resolvable cookie domain the client must mint host-scoped, not against a guess.'
			);

			return;
		}

		$_SERVER['HTTP_HOST'] = ltrim( $domain, '.' );

		$config = extrachill_analytics_visitor_cookie_client_config();

		$this->assertSame(
			$domain,
			$config['cookieDomain'],
			'A first-party request must localize the same scope the server would have used.'
		);
	}

	/**
	 * Preview and non-template runtimes receive no mintable domain.
	 *
	 * Drives the real WordPress state the eligibility gate reads —
	 * `is_preview()`, `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()` —
	 * rather than the `$GLOBALS['extrachill_analytics_test_*']` flags this
	 * test used to set. Those were honored only by the fake-WordPress
	 * bootstrap #271 deleted; no production code has ever read them, so
	 * against the managed harness every case here ran as an ordinary
	 * template request and asserted nothing.
	 *
	 * @dataProvider ineligible_runtime_provider
	 *
	 * @param string $runtime Runtime to enter.
	 */
	public function test_non_template_runtimes_receive_no_mint_domain( $runtime ): void {
		$restore = $this->enter_runtime( $runtime );

		try {
			$config = extrachill_analytics_visitor_cookie_client_config();

			$this->assertSame(
				'',
				$config['cookieDomain'],
				sprintf( 'A %s runtime must not localize a mintable cookie domain.', $runtime )
			);
		} finally {
			$restore();
		}
	}

	/**
	 * Enter a non-template runtime, returning its undo.
	 *
	 * @param string $runtime Runtime key.
	 * @return callable Restores the prior state.
	 */
	private function enter_runtime( string $runtime ): callable {
		switch ( $runtime ) {
			case 'ajax':
			case 'cron':
				$hook = 'ajax' === $runtime ? 'wp_doing_ajax' : 'wp_doing_cron';
				add_filter( $hook, '__return_true' );

				return static function () use ( $hook ): void {
					remove_filter( $hook, '__return_true' );
				};

			case 'admin':
				set_current_screen( 'edit.php' );

				return static function (): void {
					set_current_screen( 'front' );
				};

			case 'preview':
				global $wp_query;
				$prior                = $wp_query->is_preview;
				$wp_query->is_preview = true;

				return static function () use ( $prior ): void {
					global $wp_query;
					$wp_query->is_preview = $prior;
				};
		}

		$this->fail( sprintf( 'Unknown runtime fixture "%s".', $runtime ) );
	}

	/**
	 * Non-template runtimes the eligibility gate must refuse.
	 *
	 * @return array<string,array{string}>
	 */
	public function ineligible_runtime_provider() {
		return array(
			'preview' => array( 'preview' ),
			'admin'   => array( 'admin' ),
			'ajax'    => array( 'ajax' ),
			'cron'    => array( 'cron' ),
		);
	}

	/**
	 * Unsafe methods and custom-domain hosts must not mint the network cookie.
	 */
	public function test_post_and_third_party_hosts_receive_no_mint_domain(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( '', extrachill_analytics_visitor_cookie_client_config()['cookieDomain'] );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'artist.example';
		$this->assertSame( '', extrachill_analytics_visitor_cookie_client_config()['cookieDomain'] );
	}

	/**
	 * GPC and DNT opt-out requests receive no mintable domain.
	 *
	 * @dataProvider privacy_header_provider
	 *
	 * @param string $header Privacy request header.
	 */
	public function test_privacy_opt_out_requests_receive_no_mint_domain( $header ): void {
		$_SERVER[ $header ] = '1';

		$config = extrachill_analytics_visitor_cookie_client_config();

		$this->assertSame( '', $config['cookieDomain'] );
	}

	/**
	 * Supported privacy headers.
	 *
	 * @return array<string,array{string}>
	 */
	public function privacy_header_provider() {
		return array(
			'global privacy control' => array( 'HTTP_SEC_GPC' ),
			'do not track'           => array( 'HTTP_DNT' ),
		);
	}

	/**
	 * The server is read-only: no mint function exists and no source file
	 * emits `Set-Cookie`.
	 */
	public function test_server_never_mints_or_emits_set_cookie(): void {
		$this->assertFalse(
			function_exists( 'extrachill_analytics_get_or_mint_visitor_id' )
		);
		$this->assertStringNotContainsString(
			'setcookie(',
			$this->read_source( 'inc/core/assets.php' )
		);
		$this->assertStringNotContainsString(
			'setcookie(',
			$this->read_source( 'inc/core/abilities/track-page-view.php' )
		);
	}

	/**
	 * The read-only resolver stitches to an existing cookie and attributes
	 * anonymously (empty string, no mint) when one is absent.
	 */
	public function test_read_visitor_id_resolves_existing_cookie_or_stays_anonymous(): void {
		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = '123e4567-e89b-42d3-a456-426614174000';
		$this->assertSame(
			'123e4567-e89b-42d3-a456-426614174000',
			extrachill_analytics_read_visitor_id()
		);

		unset( $_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] );
		$this->assertSame( '', extrachill_analytics_read_visitor_id() );
		$this->assertArrayNotHasKey(
			EXTRACHILL_ANALYTICS_VISITOR_COOKIE,
			$_COOKIE
		);
	}

	/**
	 * GPC and DNT opt-out still suppresses identity resolution server-side.
	 */
	public function test_opted_out_requests_resolve_no_visitor_id(): void {
		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = '123e4567-e89b-42d3-a456-426614174000';
		$_SERVER['HTTP_SEC_GPC']                        = '1';

		$this->assertSame( '', extrachill_analytics_read_visitor_id() );
	}

	/**
	 * REST and CLI guards remain explicit in the request boundary.
	 */
	public function test_rest_and_cli_requests_are_explicitly_ineligible(): void {
		$source = $this->read_source( 'inc/core/assets.php' );

		$this->assertStringContainsString( "defined( 'REST_REQUEST' ) && REST_REQUEST", $source );
		$this->assertStringContainsString( "defined( 'WP_CLI' )", $source );
	}

	/**
	 * Read a production source file.
	 *
	 * @param string $relative_path Repository-relative path.
	 * @return string
	 */
	private function read_source( $relative_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );

		$this->assertNotFalse( $source );

		return $source;
	}
}
