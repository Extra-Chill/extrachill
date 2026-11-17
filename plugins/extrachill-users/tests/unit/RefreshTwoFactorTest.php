<?php
/**
 * Refresh tokens never upgrade a session that skipped Two Factor (#900),
 * and wp-native-auth browser handoffs follow the same rule.
 *
 * Runs against the Two Factor double and the wp-native-auth refresh double.
 */

class Test_Refresh_Two_Factor extends WP_UnitTestCase {

	/** @var int */
	private $cookies = 0;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/support/two-factor-core-double.php';
		require_once dirname( __DIR__ ) . '/support/wp-native-refresh-double.php';

		if ( ! defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real Two Factor plugin is loaded.' );
		}
		if ( ! defined( 'EXTRACHILL_USERS_WP_NATIVE_REFRESH_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real wp-native-auth session helpers are loaded.' );
		}

		Two_Factor_Core::reset();
		Extrachill_Users_WP_Native_Refresh_Double::reset();
		$this->cookies = 0;

		add_filter( 'send_auth_cookies', '__return_false' );
		add_action( 'set_auth_cookie', array( $this, 'count_cookie' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_action( 'set_auth_cookie', array( $this, 'count_cookie' ) );
		remove_all_actions( 'wp_login', PHP_INT_MAX );
		if ( defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			Two_Factor_Core::reset();
		}
		if ( defined( 'EXTRACHILL_USERS_WP_NATIVE_REFRESH_DOUBLE' ) ) {
			Extrachill_Users_WP_Native_Refresh_Double::reset();
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function count_cookie(): void {
		++$this->cookies;
	}

	/**
	 * @return array{0:WP_User,1:string,2:string} User, device ID, refresh token.
	 */
	private function session( bool $two_factor, ?string $policy, ?int $verified_at, ?string $oauth_client_id = null ): array {
		Two_Factor_Core::$using = $two_factor;
		$user                   = get_user_by( 'id', self::factory()->user->create() );
		$device_id              = wp_generate_uuid4();
		$token                  = Extrachill_Users_WP_Native_Refresh_Double::add( (int) $user->ID, $device_id, $policy, $verified_at, $oauth_client_id );

		return array( $user, $device_id, $token );
	}

	public function test_test_environment_has_the_policy_inactive(): void {
		// Mirrors production (Two Factor 0.16.0): no wp-native-auth challenge loop here.
		$this->assertFalse( extrachill_users_two_factor_policy_active() );
	}

	public function test_members_without_two_factor_refresh_with_a_cookie_as_before(): void {
		list( , $device_id, $token ) = $this->session( false, null, null );

		$result = extrachill_users_refresh_tokens( $token, $device_id, array( 'set_cookie' => true ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertGreaterThan( 0, $this->cookies );
	}

	public function test_unverified_two_factor_session_gets_no_cookie_and_keeps_its_tokens(): void {
		list( , $device_id, $token ) = $this->session( true, null, null );

		$result = extrachill_users_refresh_tokens( $token, $device_id, array( 'set_cookie' => true ) );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->cookies );
		$this->assertSame( 0, Extrachill_Users_WP_Native_Refresh_Double::$rotations, 'refused before the token rotates' );
		$this->assertSame( 0, get_current_user_id() );

		// The old refresh token still works for a plain token refresh.
		$this->assertIsArray( extrachill_users_refresh_tokens( $token, $device_id ) );
	}

	public function test_unverified_two_factor_session_token_refresh_works_while_policy_is_inactive(): void {
		list( , $device_id, $token ) = $this->session( true, null, null );

		$result = extrachill_users_refresh_tokens( $token, $device_id );

		$this->assertIsArray( $result, 'no lockout while the app cannot verify sessions' );
		$this->assertSame( 1, Extrachill_Users_WP_Native_Refresh_Double::$rotations );
		$this->assertSame( 0, $this->cookies );
	}

	public function test_verified_two_factor_session_refreshes_with_a_cookie(): void {
		list( $user, $device_id, $token ) = $this->session( true, 'two-factor', time() - 300 );

		$result = extrachill_users_refresh_tokens( $token, $device_id, array( 'set_cookie' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( (int) $user->ID, $result['user']['id'] );
		$this->assertGreaterThan( 0, $this->cookies );
	}

	public function test_session_verified_by_another_policy_gets_no_cookie(): void {
		list( , $device_id, $token ) = $this->session( true, 'other-policy', time() );

		$result = extrachill_users_refresh_tokens( $token, $device_id, array( 'set_cookie' => true ) );

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_required', $result->get_error_code() );
		$this->assertSame( 0, $this->cookies );
	}

	public function test_cookie_flag_does_not_outlive_the_refresh(): void {
		list( , $device_id, $token ) = $this->session( true, null, null );

		extrachill_users_refresh_tokens( $token, $device_id, array( 'set_cookie' => true ) );
		$this->assertFalse( extrachill_users_refresh_cookie_requested() );

		extrachill_users_refresh_tokens( 'wrong-token', $device_id, array( 'set_cookie' => true ) );
		$this->assertFalse( extrachill_users_refresh_cookie_requested() );
	}

	public function test_cookie_is_never_set_for_an_unverified_session_even_without_the_gate(): void {
		list( $user, $device_id, $token ) = $this->session( true, null, null );
		remove_filter( 'wp_native_auth_pre_login', 'extrachill_users_two_factor_refresh_gate', 20 );

		try {
			$result = extrachill_users_refresh_tokens( $token, $device_id, array( 'set_cookie' => true ) );
		} finally {
			add_filter( 'wp_native_auth_pre_login', 'extrachill_users_two_factor_refresh_gate', 20, 3 );
		}

		$this->assertIsArray( $result );
		$this->assertSame( 0, $this->cookies );
		$this->assertFalse( extrachill_users_refresh_may_set_cookie( $user, $device_id ) );
	}

	public function test_active_policy_refuses_token_refresh_of_an_unverified_app_session(): void {
		list( $user, $device_id ) = $this->session( true, null, null );

		$refused = extrachill_users_two_factor_refresh_refusal( $user, $device_id, false, true );

		$this->assertWPError( $refused );
		$this->assertSame( 'two_factor_required', $refused->get_error_code() );
		$this->assertSame( 401, $refused->get_error_data()['status'], 'a 401 makes the app sign in again' );
	}

	public function test_active_policy_keeps_verified_and_oauth_sessions(): void {
		list( $user, $device_id ) = $this->session( true, 'two-factor', time() - 60 );
		$this->assertNull( extrachill_users_two_factor_refresh_refusal( $user, $device_id, false, true ) );
		$this->assertNull( extrachill_users_two_factor_refresh_refusal( $user, $device_id, true, true ) );

		list( $oauth_user, $oauth_device ) = $this->session( true, null, null, 'mcp-client' );
		$this->assertNull( extrachill_users_two_factor_refresh_refusal( $oauth_user, $oauth_device, false, true ) );
		$this->assertWPError( extrachill_users_two_factor_refresh_refusal( $oauth_user, $oauth_device, true, true ), 'still no cookie' );

		list( $plain_user, $plain_device ) = $this->session( false, null, null );
		$this->assertNull( extrachill_users_two_factor_refresh_refusal( $plain_user, $plain_device, true, true ) );
	}

	public function test_gate_only_runs_on_refresh(): void {
		list( $user, $device_id ) = $this->session( true, null, null );
		extrachill_users_refresh_cookie_requested( true );

		try {
			$login   = extrachill_users_two_factor_refresh_gate( null, $user, array( 'device_id' => $device_id, 'reason' => 'login' ) );
			$refresh = extrachill_users_two_factor_refresh_gate( null, $user, array( 'device_id' => $device_id, 'reason' => 'refresh' ) );
		} finally {
			extrachill_users_refresh_cookie_requested( false );
		}

		$this->assertNull( $login );
		$this->assertWPError( $refresh );
	}

	public function test_handoff_probe_does_not_send_an_email_code(): void {
		Two_Factor_Core::$using                 = true;
		Two_Factor_Core::$challenge['provider'] = 'Two_Factor_Email';
		$user                                   = get_user_by( 'id', self::factory()->user->create() );

		$challenge = extrachill_users_two_factor_challenge( $user, array( 'reason' => 'browser_handoff' ) );

		$this->assertIsArray( $challenge );
		$this->assertSame( 'two_factor', $challenge['type'] );
		$this->assertSame( array(), Two_Factor_Core::$verify_calls );

		extrachill_users_two_factor_challenge( $user, array( 'reason' => 'login' ) );
		$this->assertCount( 1, Two_Factor_Core::$verify_calls, 'a real login prompt still sends the code' );
	}

	public function test_wp_native_handoff_needs_a_two_factor_verified_session(): void {
		Two_Factor_Core::$using = true;
		$user                   = get_user_by( 'id', self::factory()->user->create() );
		$session                = array(
			'user_id'               => (int) $user->ID,
			'device_id'             => wp_generate_uuid4(),
			'challenge_policy'      => null,
			'challenge_verified_at' => null,
		);

		foreach ( array( null, $session, array_merge( $session, array( 'challenge_policy' => 'other', 'challenge_verified_at' => time() ) ), array_merge( $session, array( 'user_id' => (int) $user->ID + 1, 'challenge_policy' => 'two-factor', 'challenge_verified_at' => time() ) ) ) as $candidate ) {
			$blocked = apply_filters( 'wp_native_auth_pre_browser_handoff', null, $user, array( 'session' => $candidate ) );
			$this->assertWPError( $blocked );
			$this->assertSame( 'two_factor_required', $blocked->get_error_code() );
		}
	}

	public function test_wp_native_handoff_of_a_verified_session_skips_two_factors_form(): void {
		Two_Factor_Core::$using = true;
		$user                   = get_user_by( 'id', self::factory()->user->create() );
		add_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ), PHP_INT_MAX, 2 );

		$blocked = apply_filters(
			'wp_native_auth_pre_browser_handoff',
			null,
			$user,
			array(
				'session' => array(
					'user_id'               => (int) $user->ID,
					'challenge_policy'      => 'two-factor',
					'challenge_verified_at' => time() - 30,
				),
			)
		);

		$this->assertNull( $blocked );
		$this->assertFalse( has_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ) ) );
	}

	public function test_wp_native_handoff_of_members_without_two_factor_is_untouched(): void {
		$user = get_user_by( 'id', self::factory()->user->create() );

		$this->assertNull( apply_filters( 'wp_native_auth_pre_browser_handoff', null, $user, array( 'session' => null ) ) );
	}
}
