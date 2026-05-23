<?php
/**
 * Unit tests for the single-origin Google OAuth helpers (#26).
 */

class Test_Google_Canonical_Origin extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/oauth/google-canonical-origin.php';
	}

	// -----------------------------------------------------------------
	// ec_users_is_valid_return_to_url — host allowlist
	// -----------------------------------------------------------------

	public function test_valid_return_to_accepts_apex_domain(): void {
		$this->assertTrue( ec_users_is_valid_return_to_url( 'https://extrachill.com/some-path' ) );
		$this->assertTrue( ec_users_is_valid_return_to_url( 'https://extrachill.com/' ) );
		$this->assertTrue( ec_users_is_valid_return_to_url( 'https://extrachill.com' ) );
	}

	public function test_valid_return_to_accepts_subdomains(): void {
		foreach (
			array(
				'https://community.extrachill.com/u/chubes',
				'https://studio.extrachill.com/compose',
				'https://artist.extrachill.com/myband',
				'https://shop.extrachill.com/cart',
				'https://events.extrachill.com',
			) as $url
		) {
			$this->assertTrue(
				ec_users_is_valid_return_to_url( $url ),
				sprintf( 'Expected %s to pass the allowlist.', $url )
			);
		}
	}

	public function test_valid_return_to_rejects_http(): void {
		$this->assertFalse(
			ec_users_is_valid_return_to_url( 'http://community.extrachill.com/' ),
			'http:// must be rejected to prevent scheme downgrades.'
		);
	}

	public function test_valid_return_to_rejects_dangerous_schemes(): void {
		foreach (
			array(
				'javascript:alert(1)',
				'data:text/html,<script>',
				'file:///etc/passwd',
				'ftp://extrachill.com/',
			) as $url
		) {
			$this->assertFalse(
				ec_users_is_valid_return_to_url( $url ),
				sprintf( 'Expected %s to be rejected.', $url )
			);
		}
	}

	public function test_valid_return_to_rejects_external_hosts(): void {
		foreach (
			array(
				'https://attacker.example.com/',
				'https://extrachill.com.attacker.example/',
				'https://notextrachill.com/',
				'https://extrachill-com.attacker.example/',
			) as $url
		) {
			$this->assertFalse(
				ec_users_is_valid_return_to_url( $url ),
				sprintf( 'Expected external host %s to be rejected.', $url )
			);
		}
	}

	public function test_valid_return_to_rejects_string_prefix_attack(): void {
		// "extrachill.com.attacker.example" naively starts with
		// "extrachill.com" but is actually a subdomain of attacker.example.
		// The trailing-dot match in the allowlist guards against this.
		$this->assertFalse(
			ec_users_is_valid_return_to_url( 'https://extrachill.com.attacker.example/path' ),
			'String-prefix attacks must be rejected.'
		);
		$this->assertFalse(
			ec_users_is_valid_return_to_url( 'https://xextrachill.com/' ),
			'Hosts that just happen to end in extrachill.com must be rejected if there is no separating dot.'
		);
	}

	public function test_valid_return_to_rejects_empty_or_malformed(): void {
		$this->assertFalse( ec_users_is_valid_return_to_url( '' ) );
		$this->assertFalse( ec_users_is_valid_return_to_url( '   ' ) );
		$this->assertFalse( ec_users_is_valid_return_to_url( 'not a url' ) );
		$this->assertFalse( ec_users_is_valid_return_to_url( '//community.extrachill.com/' ) );
		$this->assertFalse( ec_users_is_valid_return_to_url( '/just/a/path' ) );
	}

	public function test_valid_return_to_rejects_non_string_input(): void {
		$this->assertFalse( ec_users_is_valid_return_to_url( null ) );
		$this->assertFalse( ec_users_is_valid_return_to_url( 42 ) );
		$this->assertFalse( ec_users_is_valid_return_to_url( array( 'evil' ) ) );
	}

	// -----------------------------------------------------------------
	// ec_users_canonical_google_signin_url — URL construction
	// -----------------------------------------------------------------

	public function test_canonical_signin_url_omits_param_when_empty(): void {
		$url = ec_users_canonical_google_signin_url( '' );
		$this->assertStringNotContainsString( EC_USERS_GOOGLE_REDIRECT_PARAM, $url );
		$this->assertStringContainsString( '/login/', $url );
	}

	public function test_canonical_signin_url_appends_encoded_return_to(): void {
		$url = ec_users_canonical_google_signin_url( 'https://studio.extrachill.com/compose?draft=42' );

		$this->assertStringContainsString( '/login/', $url );
		$this->assertStringContainsString( EC_USERS_GOOGLE_REDIRECT_PARAM . '=', $url );

		// Verify the param round-trips cleanly.
		$parsed = wp_parse_url( $url );
		parse_str( $parsed['query'] ?? '', $args );
		$this->assertSame(
			'https://studio.extrachill.com/compose?draft=42',
			rawurldecode( $args[ EC_USERS_GOOGLE_REDIRECT_PARAM ] ?? '' )
		);
	}

	// -----------------------------------------------------------------
	// ec_users_get_validated_google_redirect_from_request — request parsing
	// -----------------------------------------------------------------

	public function test_get_validated_redirect_returns_null_without_param(): void {
		unset( $_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] );
		$this->assertNull( ec_users_get_validated_google_redirect_from_request() );
	}

	public function test_get_validated_redirect_returns_valid_url(): void {
		$_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] = rawurlencode( 'https://studio.extrachill.com/compose' );
		$this->assertSame(
			'https://studio.extrachill.com/compose',
			ec_users_get_validated_google_redirect_from_request()
		);
	}

	public function test_get_validated_redirect_rejects_external_host(): void {
		$_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] = rawurlencode( 'https://attacker.example/phish' );
		$this->assertNull( ec_users_get_validated_google_redirect_from_request() );
	}

	public function test_get_validated_redirect_rejects_dangerous_scheme(): void {
		$_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] = rawurlencode( 'javascript:alert(1)' );
		$this->assertNull( ec_users_get_validated_google_redirect_from_request() );
	}

	protected function tearDown(): void {
		unset( $_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] );
		parent::tearDown();
	}
}
