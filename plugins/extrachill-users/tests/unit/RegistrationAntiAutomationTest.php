<?php
/**
 * Password registration anti-automation policy tests.
 *
 * @package ExtraChill\Users
 */

class Test_Registration_Anti_Automation extends WP_UnitTestCase {
	private const IP = '203.0.113.30';

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = self::IP;
		unset( $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		delete_transient( extrachill_users_registration_attempt_key() );
	}

	protected function tearDown(): void {
		delete_transient( extrachill_users_registration_attempt_key() );
		remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
		delete_site_option( 'banned_email_domains' );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		parent::tearDown();
	}

	public function test_valid_registration_policy_passes(): void {
		$this->use_turnstile_result( true );

		$this->assertTrue( extrachill_users_validate_password_registration( 'person@example.com', 'valid-token' ) );
	}

	public function test_missing_turnstile_is_rejected(): void {
		$this->use_turnstile_error_for_token( '', 'turnstile_missing_token' );

		$result = extrachill_users_validate_password_registration( 'person@example.com', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'turnstile_missing_token', $result->get_error_code() );
	}

	public function test_invalid_turnstile_is_rejected(): void {
		$this->use_turnstile_error_for_token( 'invalid-token', 'turnstile_failed' );

		$result = extrachill_users_validate_password_registration( 'person@example.com', 'invalid-token' );

		$this->assertWPError( $result );
		$this->assertSame( 'turnstile_failed', $result->get_error_code() );
	}

	public function test_spoofed_app_header_does_not_bypass_turnstile(): void {
		$_SERVER['HTTP_EXTRACHILL_CLIENT'] = 'app';
		$this->use_turnstile_error_for_token( '', 'turnstile_missing_token' );

		$result = extrachill_users_validate_password_registration( 'person@example.com', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'turnstile_missing_token', $result->get_error_code() );
	}

	public function test_sixth_attempt_is_blocked_at_exact_boundary(): void {
		$this->use_turnstile_result( true );

		for ( $attempt = 1; $attempt <= EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT; ++$attempt ) {
			$this->assertTrue( extrachill_users_validate_password_registration( "person{$attempt}@example.com", 'valid-token' ) );
		}

		$result = extrachill_users_validate_password_registration( 'blocked@example.com', 'valid-token' );
		$this->assertWPError( $result );
		$this->assertSame( 'registration_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_attempts_do_not_extend_expiry_and_reset_after_expiration(): void {
		$this->use_turnstile_result( true );
		extrachill_users_validate_password_registration( 'first@example.com', 'valid-token' );
		$first_state = extrachill_users_registration_attempt_state();
		extrachill_users_validate_password_registration( 'second@example.com', 'valid-token' );
		$second_state = extrachill_users_registration_attempt_state();

		$this->assertSame( $first_state['expires_at'], $second_state['expires_at'] );

		set_transient(
			extrachill_users_registration_attempt_key(),
			array(
				'attempts'   => EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT,
				'expires_at' => time() - 1,
			),
			MINUTE_IN_SECONDS
		);

		$this->assertTrue( extrachill_users_validate_password_registration( 'reset@example.com', 'valid-token' ) );
		$this->assertSame( 1, extrachill_users_registration_attempt_state()['attempts'] );
	}

	public function test_rate_limit_response_preserves_fixed_expiry(): void {
		$this->use_turnstile_result( true );
		for ( $attempt = 0; $attempt < EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT; ++$attempt ) {
			extrachill_users_validate_password_registration( "person{$attempt}@example.com", 'valid-token' );
		}

		$first  = extrachill_users_check_registration_rate_limit();
		$second = extrachill_users_check_registration_rate_limit();

		$this->assertSame( $first->get_error_data()['expires_at'], $second->get_error_data()['expires_at'] );
		$this->assertGreaterThan( 0, $first->get_error_data()['retry_after'] );
	}

	public function test_network_banned_domain_is_rejected(): void {
		$this->use_turnstile_result( true );
		update_site_option( 'banned_email_domains', array( 'blocked.example' ) );

		$result = extrachill_users_validate_password_registration( 'person@sub.blocked.example', 'valid-token' );

		$this->assertWPError( $result );
		$this->assertSame( 'unsafe_email', $result->get_error_code() );
	}

	public function test_rest_and_form_paths_use_shared_policy(): void {
		$root         = dirname( __DIR__, 2 );
		$form_source  = file_get_contents( $root . '/inc/auth/register.php' );
		$token_source = file_get_contents( $root . '/inc/auth-tokens/service.php' );

		$this->assertGreaterThanOrEqual( 2, substr_count( $form_source, 'extrachill_users_validate_password_registration' ) );
		$this->assertStringContainsString( 'extrachill_users_validate_password_registration( $email, $turnstile_token )', $token_source );
	}

	public function test_google_registration_does_not_enter_password_policy(): void {
		$this->use_turnstile_result( new WP_Error( 'unexpected_turnstile', 'Password policy ran.' ) );
		update_site_option( 'banned_email_domains', array( 'blocked.example' ) );

		$result = ec_oauth_google_user(
			array(
				'google_id' => 'google-registration-policy-test',
				'email'     => 'google-user@blocked.example',
				'name'      => 'Google User',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['is_new'] );
	}

	private function use_turnstile_result( $result ): void {
		add_filter(
			'extrachill_users_registration_turnstile_verifier',
			static function () use ( $result ) {
				return static function () use ( $result ) {
					return $result;
				};
			}
		);
	}

	private function use_turnstile_error_for_token( string $expected_token, string $code ): void {
		add_filter(
			'extrachill_users_registration_turnstile_verifier',
			function () use ( $expected_token, $code ) {
				return function ( string $token ) use ( $expected_token, $code ) {
					$this->assertSame( $expected_token, $token );
					return new WP_Error( $code, 'Turnstile rejected.', array( 'status' => 403 ) );
				};
			}
		);
	}
}
