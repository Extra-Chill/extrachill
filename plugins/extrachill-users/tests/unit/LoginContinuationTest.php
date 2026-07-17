<?php
/**
 * End-to-end contract tests for login continuation handling (#206, #208).
 */

class Test_Login_Continuation extends WP_UnitTestCase {
	/**
	 * Original request server state.
	 *
	 * @var array<string, mixed>
	 */
	private $original_server;

	/**
	 * Original request query state.
	 *
	 * @var array<string, mixed>
	 */
	private $original_get;

	protected function setUp(): void {
		parent::setUp();
		$this->original_server = $_SERVER;
		$this->original_get    = $_GET;
		require_once dirname( __DIR__, 2 ) . '/inc/oauth/google-canonical-origin.php';
	}

	public function test_same_site_redirect_is_preserved(): void {
		$_GET['redirect_to'] = 'https://extrachill.com/my-shows/';

		$this->assertSame(
			'https://extrachill.com/my-shows/',
			ec_users_get_validated_login_redirect_from_request()
		);
	}

	public function test_cross_subdomain_redirect_is_preserved(): void {
		$_GET['redirect_to'] = 'https://events.extrachill.com/my-shows/';

		$this->assertSame(
			'https://events.extrachill.com/my-shows/',
			ec_users_get_validated_login_redirect_from_request()
		);
	}

	public function test_encoded_destination_query_round_trips_without_double_decoding(): void {
		$destination         = 'https://events.extrachill.com/my-shows/?artist=Tyler%2C%20the%20Creator&source=local%26scene';
		$_GET['redirect_to'] = $destination;

		$this->assertSame( $destination, ec_users_get_validated_login_redirect_from_request() );

		$login_url = ec_users_login_url_with_redirect( 'https://community.extrachill.com/login/', $destination );
		parse_str( (string) wp_parse_url( $login_url, PHP_URL_QUERY ), $query );
		$this->assertSame( $destination, $query['redirect_to'] );
	}

	public function test_google_continuation_preserves_encoded_destination_query(): void {
		$destination = 'https://events.extrachill.com/my-shows/?artist=Tyler%2C%20the%20Creator&source=local%26scene';
		$login_url   = ec_users_canonical_google_signin_url( $destination );
		parse_str( (string) wp_parse_url( $login_url, PHP_URL_QUERY ), $query );

		$_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] = $query[ EC_USERS_GOOGLE_REDIRECT_PARAM ] ?? '';
		$this->assertSame( $destination, ec_users_get_validated_google_redirect_from_request() );
	}

	public function test_unsafe_external_redirect_is_rejected(): void {
		$_GET['redirect_to'] = 'https://attacker.example/phish';

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
		$this->assertSame(
			'https://community.extrachill.com/login/',
			ec_users_login_url_with_redirect(
				'https://community.extrachill.com/login/',
				'https://attacker.example/phish'
			)
		);
	}

	public function test_protocol_relative_redirect_is_rejected(): void {
		$_GET['redirect_to'] = '//attacker.example/phish';

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
	}

	public function test_relative_redirect_is_rejected(): void {
		$_GET['redirect_to'] = '/my-shows/';

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
	}

	public function test_missing_continuation_returns_null(): void {
		unset( $_GET['redirect_to'] );

		$this->assertNull( ec_users_get_validated_login_redirect_from_request() );
	}

	public function test_valid_request_continuation_takes_precedence_over_fixed_block_redirect(): void {
		$fixed_redirect         = 'https://community.extrachill.com/';
		$request_redirect       = 'https://community.extrachill.com/?compose=discussion&entity_taxonomy=artist&entity_slug=kid-lake';
		$_GET['redirect_to']    = $request_redirect;
		$_SERVER['HTTP_HOST']   = 'community.extrachill.com';
		$_SERVER['REQUEST_URI'] = '/login/?redirect_to=' . rawurlencode( $request_redirect );

		$config = $this->render_login_block_config( $fixed_redirect );

		$this->assertSame( $request_redirect, $config['loginRedirectUrl'] );
		$this->assertSame( $request_redirect, $config['successRedirectUrl'] );
	}

	public function test_unsafe_request_continuation_falls_back_to_fixed_block_redirect(): void {
		$fixed_redirect         = 'https://community.extrachill.com/';
		$_GET['redirect_to']    = 'https://attacker.example/phish';
		$_SERVER['HTTP_HOST']   = 'community.extrachill.com';
		$_SERVER['REQUEST_URI'] = '/login/?redirect_to=https%3A%2F%2Fattacker.example%2Fphish';

		$config = $this->render_login_block_config( $fixed_redirect );

		$this->assertSame( $fixed_redirect, $config['loginRedirectUrl'] );
		$this->assertSame( $fixed_redirect, $config['successRedirectUrl'] );
	}

	public function test_fixed_block_redirect_remains_the_default_without_request_continuation(): void {
		$fixed_redirect         = 'https://community.extrachill.com/';
		$_SERVER['HTTP_HOST']   = 'community.extrachill.com';
		$_SERVER['REQUEST_URI'] = '/login/';

		$config = $this->render_login_block_config( $fixed_redirect );

		$this->assertSame( $fixed_redirect, $config['loginRedirectUrl'] );
		$this->assertSame( $fixed_redirect, $config['successRedirectUrl'] );
	}

	public function test_network_custom_domain_is_allowed_by_existing_policy(): void {
		$this->assertTrue( ec_users_is_valid_return_to_url( 'https://extrachill.link/chubes/' ) );
	}

	public function test_attendance_cta_round_trips_through_wp_login_and_custom_login(): void {
		$attendance_url = 'https://events.extrachill.com/shows/khruangbin/?attendance=going&source=my-shows%2Fupcoming';
		$wp_login_url   = wp_login_url( $attendance_url );

		parse_str( (string) wp_parse_url( $wp_login_url, PHP_URL_QUERY ), $wp_login_query );
		$custom_login_url = ec_users_login_url_with_redirect(
			'https://community.extrachill.com/login/',
			$wp_login_query['redirect_to'] ?? ''
		);

		parse_str( (string) wp_parse_url( $custom_login_url, PHP_URL_QUERY ), $custom_login_query );
		$this->assertSame( $attendance_url, $custom_login_query['redirect_to'] ?? '' );
	}

	public function test_noncanonical_google_handoff_carries_the_resolved_request_continuation(): void {
		$request_redirect    = 'https://community.extrachill.com/?compose=discussion&entity_taxonomy=artist&entity_slug=kid-lake';
		$_GET['redirect_to'] = $request_redirect;

		$resolved   = ec_users_resolve_login_block_redirect(
			'https://events.extrachill.com/',
			'https://events.extrachill.com/login/'
		);
		$google_url = ec_users_canonical_google_signin_url( $resolved );
		parse_str( (string) wp_parse_url( $google_url, PHP_URL_QUERY ), $query );
		$this->assertSame( $request_redirect, $query[ EC_USERS_GOOGLE_REDIRECT_PARAM ] ?? '' );
	}

	/**
	 * The token login service must carry its validated destination into the
	 * Two Factor plugin's challenge state.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_two_factor_challenge_carries_the_validated_login_continuation(): void {
		if ( class_exists( 'Two_Factor_Core' ) ) {
			$this->markTestSkipped( 'Test requires the isolated Two_Factor_Core stub.' );
		}

		eval(
			'class Two_Factor_Core {' .
			'public static function is_user_using_two_factor( $user_id ) { return true; }' .
			'public static function create_login_nonce( $user_id ) { return array( "key" => "test-2fa-nonce" ); }' .
			'}'
		);

		$password     = 'valid-password';
		$user_id      = self::factory()->user->create( array( 'user_pass' => $password ) );
		$user         = get_user_by( 'id', $user_id );
		$continuation = 'https://community.extrachill.com/?compose=discussion&entity_taxonomy=artist&entity_slug=kid-lake';
		$result       = extrachill_users_maybe_handle_two_factor( $user->user_login, $password, true, $continuation );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['requires_2fa'] );
		parse_str( (string) wp_parse_url( $result['redirect_url'], PHP_URL_QUERY ), $query );
		$this->assertSame( $continuation, $query['redirect_to'] ?? '' );
	}

	/**
	 * Render the dynamic block and decode its browser configuration.
	 *
	 * @param string $fixed_redirect Configured block redirect.
	 * @return array<string, mixed>
	 */
	private function render_login_block_config( string $fixed_redirect ): array {
		$html = render_block(
			array(
				'blockName' => 'extrachill/login-register',
				'attrs'     => array( 'redirectUrl' => $fixed_redirect ),
			)
		);

		$this->assertMatchesRegularExpression( '/data-ec-login-register-config="([^"]+)"/', $html );
		preg_match( '/data-ec-login-register-config="([^"]+)"/', $html, $matches );
		$config = json_decode( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), true );

		$this->assertIsArray( $config );
		return $config;
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		$_GET    = $this->original_get;
		parent::tearDown();
	}
}
