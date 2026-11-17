<?php
/**
 * Browser handoff for Two Factor members (no Two Factor screen, no 2FA skip).
 *
 * Runs against the Two Factor double and the wp-native-auth session double.
 */

class Test_Browser_Handoff_Two_Factor extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private $original_request;

	/** @var int */
	private $cookies = 0;

	/** @var array<string,mixed>|null */
	private $session = null;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/support/two-factor-core-double.php';
		require_once dirname( __DIR__ ) . '/support/wp-native-session-double.php';

		if ( ! defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real Two Factor plugin is loaded.' );
		}

		$this->original_request = $_REQUEST;
		Two_Factor_Core::reset();
		if ( defined( 'EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE' ) ) {
			Extrachill_Users_WP_Native_Session_Double::reset();
		}

		add_filter( 'send_auth_cookies', '__return_false' );
		add_action( 'set_auth_cookie', array( $this, 'count_cookie' ) );
		add_filter( 'attach_session_information', array( $this, 'capture_session' ), 99 );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	protected function tearDown(): void {
		$_REQUEST = $this->original_request;
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_action( 'set_auth_cookie', array( $this, 'count_cookie' ) );
		remove_filter( 'attach_session_information', array( $this, 'capture_session' ), 99 );
		remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		remove_all_actions( 'wp_login', PHP_INT_MAX );
		if ( defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			Two_Factor_Core::reset();
		}
		if ( defined( 'EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE' ) ) {
			Extrachill_Users_WP_Native_Session_Double::reset();
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function count_cookie(): void {
		++$this->cookies;
	}

	/**
	 * @param array<string,mixed> $session Session data.
	 * @return array<string,mixed>
	 */
	public function capture_session( $session ) {
		$this->session = $session;
		return $session;
	}

	/**
	 * @param string $location Redirect target.
	 * @return never
	 */
	public function stop_redirect( $location ) {
		throw new RuntimeException( (string) $location );
	}

	private function require_session_double(): void {
		if ( ! defined( 'EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real wp-native-auth session helpers are loaded.' );
		}
	}

	/**
	 * Mint a handoff from a bearer session, then consume it in a fresh request.
	 *
	 * @param int                 $user_id User.
	 * @param array<string,mixed> $session Bearer session at mint time.
	 * @return string Redirect location.
	 */
	private function hand_off( int $user_id, array $session ): string {
		$this->require_session_double();
		wp_set_current_user( $user_id );
		Extrachill_Users_WP_Native_Session_Double::$session = $session;
		$token = extrachill_users_create_browser_handoff_token( $user_id, 'https://events.extrachill.com/my-shows/' );

		Extrachill_Users_WP_Native_Session_Double::$session = null;
		wp_set_current_user( 0 );
		$_REQUEST['ec_browser_handoff'] = $token;

		try {
			extrachill_users_handle_browser_handoff();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'The handoff did not redirect.' );
	}

	/**
	 * @param int $user_id User.
	 * @return array<string,mixed>
	 */
	private function bearer_session( int $user_id, ?string $policy, ?int $verified_at ): array {
		return array(
			'user_id'               => $user_id,
			'device_id'             => wp_generate_uuid4(),
			'device_name'           => 'App',
			'oauth_client_id'       => null,
			'created_at'            => time() - 600,
			'challenge_policy'      => $policy,
			'challenge_verified_at' => $verified_at,
		);
	}

	public function test_verified_app_session_hands_off_without_two_factors_form(): void {
		Two_Factor_Core::$using = true;
		$user_id     = self::factory()->user->create();
		$verified_at = time() - 120;

		// Stands in for Two Factor's handler, which renders its form and exits.
		add_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ), PHP_INT_MAX, 2 );

		$location = $this->hand_off( $user_id, $this->bearer_session( $user_id, 'two-factor', $verified_at ) );

		$this->assertSame( 'https://events.extrachill.com/my-shows/', $location );
		$this->assertGreaterThan( 0, $this->cookies );
		$this->assertFalse( has_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ) ) );
		$this->assertSame( $verified_at, $this->session['two-factor-login'] ?? null, 'the cookie session keeps the app session 2FA time' );
	}

	public function test_unverified_app_session_signs_in_on_the_login_page(): void {
		Two_Factor_Core::$using = true;
		$user_id = self::factory()->user->create();

		$location = $this->hand_off( $user_id, $this->bearer_session( $user_id, null, null ) );

		$this->assertSame( 0, $this->cookies );
		$this->assertStringStartsWith( home_url( user_trailingslashit( '/login' ) ), $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'https://events.extrachill.com/my-shows/', $query['redirect_to'] ?? '' );
	}

	public function test_session_verified_by_another_policy_does_not_count(): void {
		Two_Factor_Core::$using = true;
		$user_id = self::factory()->user->create();

		$location = $this->hand_off( $user_id, $this->bearer_session( $user_id, 'other-policy', time() ) );

		$this->assertSame( 0, $this->cookies );
		$this->assertStringStartsWith( home_url( user_trailingslashit( '/login' ) ), $location );
	}

	public function test_members_without_two_factor_hand_off_as_before(): void {
		$user_id = self::factory()->user->create();

		$location = $this->hand_off( $user_id, $this->bearer_session( $user_id, null, null ) );

		$this->assertSame( 'https://events.extrachill.com/my-shows/', $location );
		$this->assertGreaterThan( 0, $this->cookies );
		$this->assertArrayNotHasKey( 'two-factor-login', (array) $this->session );
	}

	public function test_session_time_ignores_other_users(): void {
		$this->require_session_double();
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		Extrachill_Users_WP_Native_Session_Double::$session = $this->bearer_session( $user_id, 'two-factor', time() );

		$this->assertSame( 0, extrachill_users_current_session_two_factor_time( $user_id + 1 ) );
		$this->assertGreaterThan( 0, extrachill_users_current_session_two_factor_time( $user_id ) );
	}

	public function test_cookie_session_reports_two_factors_own_time(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		Two_Factor_Core::$session_time = 1700000000;

		$this->assertSame( 1700000000, extrachill_users_current_session_two_factor_time( $user_id ) );
	}
}
