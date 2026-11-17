<?php
/**
 * The `two-factor` challenge policy: challenge, verify, and bearer-session glue.
 *
 * Runs against a double of the unreleased Two Factor login API
 * (WordPress/two-factor#1045) and of wp-native-auth's session helpers (#120).
 */

class Test_Two_Factor_Policy extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/support/two-factor-core-double.php';
		require_once dirname( __DIR__ ) . '/support/wp-native-session-double.php';

		if ( ! defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real Two Factor plugin is loaded.' );
		}

		Two_Factor_Core::reset();
		Two_Factor_Core::$using = true;

		if ( defined( 'EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE' ) ) {
			Extrachill_Users_WP_Native_Session_Double::reset();
		}
	}

	protected function tearDown(): void {
		if ( defined( 'EXTRACHILL_USERS_TWO_FACTOR_DOUBLE' ) ) {
			Two_Factor_Core::reset();
		}
		if ( defined( 'EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE' ) ) {
			Extrachill_Users_WP_Native_Session_Double::reset();
		}
		extrachill_users_continued_login_flow( array() );
		parent::tearDown();
	}

	private function user(): WP_User {
		return get_user_by( 'id', self::factory()->user->create() );
	}

	private function require_session_double(): void {
		if ( ! defined( 'EXTRACHILL_USERS_WP_NATIVE_SESSION_DOUBLE' ) ) {
			$this->markTestSkipped( 'The real wp-native-auth session helpers are loaded.' );
		}
	}

	public function test_api_is_detected_from_the_upstream_methods(): void {
		$this->assertTrue( extrachill_users_two_factor_api_available() );
	}

	public function test_challenge_describes_the_users_providers(): void {
		$challenge = extrachill_users_two_factor_challenge( $this->user(), array() );

		$this->assertSame(
			array(
				'type'      => 'two_factor',
				'provider'  => 'Two_Factor_Totp',
				'providers' => array(
					array(
						'key'    => 'Two_Factor_Totp',
						'label'  => 'Authenticator app',
						'resend' => false,
					),
					array(
						'key'    => 'Two_Factor_Email',
						'label'  => 'Email',
						'resend' => true,
					),
				),
			),
			$challenge
		);
		$this->assertSame( array(), Two_Factor_Core::$verify_calls, 'an app code must not trigger a send' );
	}

	public function test_users_without_two_factor_are_not_challenged(): void {
		Two_Factor_Core::$using = false;

		$this->assertNull( extrachill_users_two_factor_challenge( $this->user(), array() ) );
	}

	public function test_users_without_a_usable_provider_are_not_challenged(): void {
		Two_Factor_Core::$challenge = array(
			'provider'  => '',
			'providers' => array(),
		);

		$this->assertNull( extrachill_users_two_factor_challenge( $this->user(), array() ) );
	}

	public function test_an_unknown_primary_falls_back_to_the_first_provider(): void {
		Two_Factor_Core::$challenge['provider'] = 'Two_Factor_Missing';

		$challenge = extrachill_users_two_factor_challenge( $this->user(), array() );

		$this->assertSame( 'Two_Factor_Totp', $challenge['provider'] );
	}

	public function test_an_email_primary_sends_a_code_with_the_challenge(): void {
		$user = $this->user();
		Two_Factor_Core::$challenge['provider'] = 'Two_Factor_Email';

		$challenge = extrachill_users_two_factor_challenge( $user, array() );

		$this->assertSame( 'Two_Factor_Email', $challenge['provider'] );
		$this->assertSame( 'code_sent', $challenge['status'] );
		$this->assertSame(
			array(
				array(
					'user_id'  => (int) $user->ID,
					'provider' => 'Two_Factor_Email',
					'args'     => array( 'resend' => true ),
				),
			),
			Two_Factor_Core::$verify_calls
		);
	}

	public function test_a_rate_limited_send_still_prompts_for_the_code(): void {
		Two_Factor_Core::$challenge['provider'] = 'Two_Factor_Email';

		Two_Factor_Core::$verify_result = new WP_Error(
			'two_factor_too_fast',
			'Slow down.',
			array(
				'status'      => 429,
				'retry_after' => 30,
			)
		);

		$challenge = extrachill_users_two_factor_challenge( $this->user(), array() );

		$this->assertSame( 'two_factor', $challenge['type'] );
		$this->assertArrayNotHasKey( 'status', $challenge );
	}

	public function test_a_valid_code_completes_the_login(): void {
		$user = $this->user();
		update_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );
		update_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 2 );
		add_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ), PHP_INT_MAX, 2 );

		$result = extrachill_users_two_factor_verify(
			$user,
			array(
				'provider' => 'Two_Factor_Totp',
				'code'     => ' 123456 ',
			),
			array()
		);

		$this->assertTrue( $result );
		$this->assertSame(
			array(
				'code'   => '123456',
				'resend' => false,
			),
			Two_Factor_Core::$verify_calls[0]['args']
		);
		$this->assertFalse(
			has_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ) ),
			'Two Factor would render its form and exit when the continued login sets a cookie'
		);
		$this->assertSame( '', get_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY, true ) );
		$this->assertSame( '', get_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );

		$session = apply_filters( 'attach_session_information', array(), $user->ID );
		$this->assertSame( 'Two_Factor_Totp', $session['two-factor-provider'] );
		$this->assertIsInt( $session['two-factor-login'] );
	}

	public function test_an_omitted_provider_means_the_primary(): void {
		$result = extrachill_users_two_factor_verify( $this->user(), array( 'code' => '123456' ), array() );

		$this->assertTrue( $result );
		$this->assertSame( 'Two_Factor_Totp', Two_Factor_Core::$verify_calls[0]['provider'] );
	}

	public function test_a_wrong_code_returns_the_upstream_error(): void {
		$result = extrachill_users_two_factor_verify(
			$this->user(),
			array(
				'provider' => 'Two_Factor_Totp',
				'code'     => '000000',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'two_factor_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_rate_limiting_keeps_retry_after(): void {
		Two_Factor_Core::$verify_result = new WP_Error(
			'two_factor_too_fast',
			'Slow down.',
			array(
				'status'      => 429,
				'retry_after' => 42,
			)
		);

		$result = extrachill_users_two_factor_verify( $this->user(), array( 'code' => '123456' ), array() );

		$this->assertSame( 'two_factor_too_fast', $result->get_error_code() );
		$this->assertSame( 42, $result->get_error_data()['retry_after'] );
	}

	public function test_a_provider_the_user_does_not_have_is_rejected(): void {
		$result = extrachill_users_two_factor_verify(
			$this->user(),
			array(
				'provider' => 'Two_Factor_Backup_Codes',
				'code'     => '123456',
			),
			array()
		);

		$this->assertSame( 'two_factor_provider_not_enabled', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), Two_Factor_Core::$verify_calls );
	}

	public function test_resend_returns_a_rechallenge_for_that_provider(): void {
		$result = extrachill_users_two_factor_verify(
			$this->user(),
			array(
				'provider' => 'Two_Factor_Email',
				'resend'   => true,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'two_factor', $result['type'] );
		$this->assertSame( 'Two_Factor_Email', $result['provider'] );
		$this->assertSame( 'code_sent', $result['status'] );
		$this->assertTrue( Two_Factor_Core::$verify_calls[0]['args']['resend'] );
	}

	public function test_a_forced_password_reset_ends_the_pending_login(): void {
		Two_Factor_Core::$verify_result = new WP_Error( 'two_factor_password_reset_required', 'Reset.', array( 'status' => 403 ) );

		$result = extrachill_users_two_factor_verify( $this->user(), array( 'code' => '000000' ), array() );

		$this->assertSame( 'two_factor_password_reset_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_bearer_session_reports_its_two_factor_time(): void {
		$this->require_session_double();
		$user = $this->user();
		Extrachill_Users_WP_Native_Session_Double::$session = array(
			'user_id'               => (int) $user->ID,
			'challenge_policy'      => 'two-factor',
			'challenge_verified_at' => 1700000000,
		);

		$this->assertSame( 1700000000, apply_filters( 'two_factor_current_session_two_factor_time', false, $user ) );
	}

	public function test_unverified_bearer_session_may_revalidate(): void {
		$this->require_session_double();
		$user = $this->user();
		Extrachill_Users_WP_Native_Session_Double::$session = array(
			'user_id'               => (int) $user->ID,
			'challenge_policy'      => null,
			'challenge_verified_at' => null,
		);

		$this->assertSame( 0, apply_filters( 'two_factor_current_session_two_factor_time', false, $user ) );
	}

	public function test_session_verified_by_another_policy_does_not_count(): void {
		$this->require_session_double();
		$user = $this->user();
		Extrachill_Users_WP_Native_Session_Double::$session = array(
			'user_id'               => (int) $user->ID,
			'challenge_policy'      => 'passkey',
			'challenge_verified_at' => 1700000000,
		);

		$this->assertSame( 0, apply_filters( 'two_factor_current_session_two_factor_time', false, $user ) );
	}

	public function test_cookie_requests_keep_two_factors_own_time(): void {
		$this->require_session_double();
		$user = $this->user();

		$this->assertSame( 1600000000, apply_filters( 'two_factor_current_session_two_factor_time', 1600000000, $user ) );

		Extrachill_Users_WP_Native_Session_Double::$session = array(
			'user_id'               => (int) $user->ID + 1,
			'challenge_policy'      => 'two-factor',
			'challenge_verified_at' => 1700000000,
		);
		$this->assertSame( 1600000000, apply_filters( 'two_factor_current_session_two_factor_time', 1600000000, $user ) );
	}

	public function test_revalidation_marks_the_bearer_session(): void {
		$this->require_session_double();
		$user = $this->user();
		Extrachill_Users_WP_Native_Session_Double::$session = array(
			'user_id'               => (int) $user->ID,
			'challenge_policy'      => null,
			'challenge_verified_at' => null,
		);

		do_action( 'two_factor_user_revalidated', $user, 'Two_Factor_Totp' );

		$this->assertSame( array( 'two-factor' ), Extrachill_Users_WP_Native_Session_Double::$marked );
	}

	public function test_revalidation_without_a_bearer_session_marks_nothing(): void {
		$this->require_session_double();

		do_action( 'two_factor_user_revalidated', $this->user(), 'Two_Factor_Totp' );

		$this->assertSame( array(), Extrachill_Users_WP_Native_Session_Double::$marked );
	}

	public function test_api_login_allowance_does_not_widen_two_factors_default(): void {
		$this->assertFalse( apply_filters( 'two_factor_user_api_login_enable', false, 1 ) );
		$this->assertTrue( apply_filters( 'two_factor_user_api_login_enable', true, 1 ) );
	}

	public function test_api_login_allowance_needs_the_challenge_loop(): void {
		extrachill_users_two_factor_allow_api_primary_login( true );
		$allowed = extrachill_users_two_factor_allow_api_primary_login();
		extrachill_users_two_factor_allow_api_primary_login( false );

		$this->assertSame( extrachill_users_two_factor_policy_active(), $allowed );
	}

	public function test_continued_login_flow_is_captured_at_revalidation(): void {
		$flow = array(
			'flow'        => 'password',
			'redirect_to' => 'https://community.extrachill.com/r/new-topic/',
		);

		$this->assertNull( apply_filters( 'wp_native_auth_revalidate_login', null, $this->user(), array( 'extrachill' => $flow ) ) );
		$this->assertSame( $flow, extrachill_users_continued_login_flow() );
	}

	public function test_a_blocked_continuation_passes_through_untouched(): void {
		$error = new WP_Error( 'extrachill_user_blocked', 'Blocked.', array( 'status' => 403 ) );

		$this->assertSame( $error, extrachill_users_capture_continued_login( $error, $this->user(), array( 'extrachill' => array( 'flow' => 'oauth' ) ) ) );
		$this->assertSame( array(), extrachill_users_continued_login_flow() );
	}

	public function test_logins_complete_directly_without_the_challenge_loop(): void {
		if ( function_exists( 'wp_native_auth_challenge_login' ) ) {
			$this->markTestSkipped( 'wp-native-auth with the challenge loop is loaded.' );
		}

		Two_Factor_Core::$using = false;

		$this->assertNull( extrachill_users_maybe_challenge_login( $this->user(), wp_generate_uuid4(), array(), array( 'flow' => 'oauth' ) ) );
	}

	public function test_continue_requires_the_challenge_loop(): void {
		if ( function_exists( 'wp_native_auth_continue_login' ) ) {
			$this->markTestSkipped( 'wp-native-auth with the challenge loop is loaded.' );
		}

		$result = extrachill_users_continue_login_with_tokens( 'token', wp_generate_uuid4(), array( 'code' => '123456' ) );

		$this->assertSame( 'extrachill_dependency_missing', $result->get_error_code() );
	}

	public function test_policy_is_registered_only_with_both_upstream_apis(): void {
		$this->assertSame(
			extrachill_users_two_factor_api_available() && function_exists( 'wp_native_auth_challenge_login' ) && function_exists( 'wp_native_auth_continue_login' ) && function_exists( 'wp_native_auth_register_challenge_policy' ),
			extrachill_users_two_factor_policy_active()
		);
	}
}
