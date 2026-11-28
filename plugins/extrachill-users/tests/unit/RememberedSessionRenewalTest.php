<?php
/**
 * Remember Me: 30-day rolling login sessions (#1782).
 */

class Test_Remembered_Session_Renewal extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var array<string,mixed> */
	private $original_cookie;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/auth/session-renewal.php';

		$this->original_cookie = $_COOKIE;
		$this->user_id         = self::factory()->user->create();
		add_filter( 'send_auth_cookies', '__return_false' );
	}

	protected function tearDown(): void {
		remove_filter( 'send_auth_cookies', '__return_false' );
		$_COOKIE = $this->original_cookie;
		parent::tearDown();
	}

	/**
	 * Create a session through core's login cookie path.
	 *
	 * @param bool                $remember Whether the login was remembered.
	 * @param array<string,mixed> $extra    Extra session data (2FA keys).
	 * @return string Session token.
	 */
	private function login( bool $remember, array $extra = array() ): string {
		$token  = '';
		$attach = static function ( $info ) use ( $extra ) {
			return array_merge( $info, $extra );
		};
		$grab   = static function ( $cookie, $expire, $expiration, $user_id, $scheme, $session_token ) use ( &$token ) {
			$token = $session_token;
		};

		add_filter( 'attach_session_information', $attach );
		add_action( 'set_auth_cookie', $grab, 20, 6 );
		wp_set_auth_cookie( $this->user_id, $remember );
		remove_filter( 'attach_session_information', $attach );
		remove_action( 'set_auth_cookie', $grab, 20 );

		$this->assertNotSame( '', $token );
		return $token;
	}

	/**
	 * Pretend the session was issued `$seconds_ago`.
	 */
	private function age( string $token, int $seconds_ago ): void {
		$manager                 = WP_Session_Tokens::get_instance( $this->user_id );
		$session                 = $manager->get( $token );
		$session['login']        = time() - $seconds_ago;
		$session['expiration']   = $session['login'] + ( $session['expiration'] - time() );
		$manager->update( $token, $session );
	}

	private function session( string $token ) {
		return WP_Session_Tokens::get_instance( $this->user_id )->get( $token );
	}

	public function test_remembered_cookie_lasts_thirty_days(): void {
		$this->assertSame( 30 * DAY_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $this->user_id, true ) );
	}

	public function test_non_remembered_cookie_keeps_core_default(): void {
		$this->assertSame( 2 * DAY_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $this->user_id, false ) );
	}

	public function test_login_records_remember_flag_and_thirty_day_expiry(): void {
		$remembered = $this->session( $this->login( true ) );
		$this->assertTrue( $remembered['ec_remember'] );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, $remembered['expiration'], 5 );

		$short = $this->session( $this->login( false ) );
		$this->assertFalse( $short['ec_remember'] );
		$this->assertEqualsWithDelta( time() + 2 * DAY_IN_SECONDS, $short['expiration'], 5 );
	}

	public function test_remembered_session_past_throttle_is_renewed_in_place(): void {
		$old = $this->login( true );
		$this->age( $old, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		$before = $this->session( $old );

		$issued = array();
		$watch  = static function ( $cookie, $expire, $expiration, $user_id, $scheme, $token ) use ( &$issued ) {
			$issued[] = compact( 'expire', 'expiration', 'token' );
		};
		add_action( 'set_auth_cookie', $watch, 20, 6 );
		$renewed = extrachill_users_maybe_renew_remembered_session( $this->user_id, $old );
		remove_action( 'set_auth_cookie', $watch, 20 );

		$this->assertTrue( $renewed );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $this->user_id )->get_all(), 'No new session is created.' );

		$session = $this->session( $old );
		$this->assertIsArray( $session );
		$this->assertTrue( $session['ec_remember'] );
		$this->assertSame( $before['login'], $session['login'], 'Original login time is kept.' );
		$this->assertGreaterThan( $before['expiration'], $session['expiration'] );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, $session['expiration'], 5 );
		$this->assertEqualsWithDelta( time(), $session['ec_renewed_at'], 5 );

		$this->assertCount( 1, $issued );
		$this->assertSame( $old, $issued[0]['token'], 'Cookie is re-sent with the same token.' );
		$this->assertNotSame( 0, $issued[0]['expire'], 'Renewed cookie stays persistent.' );
		$this->assertSame( $old, wp_get_session_token(), 'Request cookies keep the same session.' );
	}

	public function test_old_token_stays_valid_after_renewal(): void {
		$token = $this->login( true );
		$this->age( $token, DAY_IN_SECONDS + HOUR_IN_SECONDS );

		$this->assertTrue( extrachill_users_maybe_renew_remembered_session( $this->user_id, $token ) );
		$this->assertTrue( WP_Session_Tokens::get_instance( $this->user_id )->verify( $token ) );

		// A concurrent request carrying the same cookie is still authenticated.
		$this->assertSame( $this->user_id, wp_validate_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ], 'logged_in' ) );
	}

	public function test_renewal_keeps_two_factor_keys(): void {
		$two_factor = array(
			'two-factor-login'    => time() - 2 * DAY_IN_SECONDS,
			'two-factor-provider' => 'Two_Factor_Totp',
		);
		$token      = $this->login( true, $two_factor );
		$this->age( $token, DAY_IN_SECONDS + HOUR_IN_SECONDS );

		$this->assertTrue( extrachill_users_maybe_renew_remembered_session( $this->user_id, $token ) );

		$session = $this->session( $token );
		$this->assertSame( $two_factor['two-factor-login'], $session['two-factor-login'] );
		$this->assertSame( 'Two_Factor_Totp', $session['two-factor-provider'] );
	}

	public function test_recent_renewal_throttles_even_when_login_is_old(): void {
		$token = $this->login( true );
		$this->age( $token, 5 * DAY_IN_SECONDS );

		$this->assertTrue( extrachill_users_maybe_renew_remembered_session( $this->user_id, $token ) );
		$after_first = $this->session( $token );

		$this->assertFalse( extrachill_users_maybe_renew_remembered_session( $this->user_id, $token ) );
		$this->assertSame( $after_first, $this->session( $token ) );
	}

	public function test_remembered_session_inside_throttle_is_left_alone(): void {
		$token = $this->login( true );
		$this->age( $token, DAY_IN_SECONDS - HOUR_IN_SECONDS );
		$before = $this->session( $token );

		$this->assertFalse( extrachill_users_maybe_renew_remembered_session( $this->user_id, $token ) );
		$this->assertSame( $before, $this->session( $token ) );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_non_remembered_session_is_never_renewed(): void {
		$token = $this->login( false );
		$this->age( $token, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		$before = $this->session( $token );

		$this->assertFalse( extrachill_users_maybe_renew_remembered_session( $this->user_id, $token ) );
		$this->assertSame( $before, $this->session( $token ) );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_legacy_sessions_are_classified_by_lifetime(): void {
		$now = time();
		$this->assertTrue( extrachill_users_session_is_remembered( array( 'login' => $now, 'expiration' => $now + 14 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( extrachill_users_session_is_remembered( array( 'login' => $now, 'expiration' => $now + 2 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( extrachill_users_session_is_remembered( array() ) );
	}

	public function test_bearer_requests_skip_renewal(): void {
		$original = $_SERVER;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
		try {
			$this->assertFalse( extrachill_users_session_renewal_request_allowed() );
		} finally {
			$_SERVER = $original;
		}
	}
}
