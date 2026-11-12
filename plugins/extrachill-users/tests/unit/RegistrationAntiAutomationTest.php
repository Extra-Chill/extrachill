<?php
/**
 * Password registration anti-automation policy tests.
 *
 * @package ExtraChill\Users
 */
class Test_Registration_Anti_Automation extends WP_UnitTestCase {
	private $counts = array();
	private $fail = false;

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.30';
		unset( $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		add_filter(
			'extrachill_rate_limit_store',
			function () {
				return function ( $key, $ttl, $operation = 'increment' ) {
					if ( $this->fail ) {
						return extrachill_rate_limit_unavailable();
					}
					if ( 'get' === $operation ) {
						return $this->counts[ $key ] ?? 0;
					}
					$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + 1;
					return $this->counts[ $key ];
				};
			}
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'extrachill_rate_limit_store' );
		remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
		remove_all_filters( 'extrachill_users_registration_admitter' );
		delete_site_option( 'banned_email_domains' );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		parent::tearDown();
	}

	public function test_valid_registration_policy_passes(): void {
		$this->use_turnstile_result( true );
		$this->assertTrue( extrachill_users_validate_password_registration( 'person@example.com', 'valid-token' ) );
	}

	public function test_missing_and_invalid_turnstile_do_not_consume_admission(): void {
		foreach ( array( 'turnstile_missing_token', 'turnstile_failed' ) as $code ) {
			remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
			$this->use_turnstile_result( new WP_Error( $code, 'Rejected.' ) );
			$this->assertSame( $code, extrachill_users_validate_password_registration( 'person@example.com', '' )->get_error_code() );
		}
		$this->assertSame( array(), $this->counts );
	}

	public function test_spoofed_app_header_does_not_bypass_turnstile(): void {
		$_SERVER['HTTP_EXTRACHILL_CLIENT'] = 'app';
		$this->use_turnstile_result( new WP_Error( 'turnstile_missing_token', 'Rejected.' ) );
		$this->assertSame( 'turnstile_missing_token', extrachill_users_validate_password_registration( 'person@example.com', '' )->get_error_code() );
		$this->assertSame( array(), $this->counts );
	}

	public function test_turnstile_runs_before_atomic_admission(): void {
		$order = array();
		add_filter(
			'extrachill_users_registration_turnstile_verifier',
			static function () use ( &$order ) {
				return static function () use ( &$order ) {
					$order[] = 'turnstile';
					return true;
				};
			}
		);
		add_filter(
			'extrachill_users_registration_admitter',
			static function () use ( &$order ) {
				return static function () use ( &$order ) {
					$order[] = 'admission';
					return true;
				};
			}
		);
		extrachill_users_validate_password_registration( 'person@example.com', 'valid-token' );
		$this->assertSame( array( 'turnstile', 'admission' ), $order );
	}

	public function test_shared_limiter_admits_exactly_five_attempts(): void {
		for ( $attempt = 0; $attempt < EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT; ++$attempt ) {
			$this->assertTrue( extrachill_users_admit_registration_attempt() );
		}
		$result = extrachill_users_admit_registration_attempt();
		$this->assertSame( 'registration_rate_limited', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertLessThanOrEqual( EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW, $data['retry_after'] );
		$this->assertEqualsWithDelta( time() + $data['retry_after'], $data['expires_at'], 1 );
		$this->assertSame( 6, extrachill_rate_limit_count( extrachill_users_registration_attempt_key(), EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW ) );
	}

	public function test_requester_keys_are_private_stable_and_distinct(): void {
		$key = extrachill_users_registration_attempt_key();
		$this->assertStringNotContainsString( $_SERVER['REMOTE_ADDR'], $key );
		$this->assertSame( $key, extrachill_users_registration_attempt_key() );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.31';
		$this->assertNotSame( $key, extrachill_users_registration_attempt_key() );
		$this->assertTrue( extrachill_users_admit_registration_attempt() );
	}

	public function test_storage_failure_preserves_public_error(): void {
		$this->fail = true;
		$result = extrachill_users_admit_registration_attempt();
		$this->assertSame( 'registration_limiter_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_missing_remote_addr_fails_closed_after_turnstile(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->use_turnstile_result( true );
		$result = extrachill_users_validate_password_registration( 'person@example.com', 'valid-token' );
		$this->assertSame( 'registration_limiter_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_network_banned_domain_is_rejected_after_admission(): void {
		$this->use_turnstile_result( true );
		update_site_option( 'banned_email_domains', array( 'blocked.example' ) );
		$result = extrachill_users_validate_password_registration( 'person@sub.blocked.example', 'valid-token' );
		$this->assertSame( 'unsafe_email', $result->get_error_code() );
		$this->assertSame( 1, extrachill_rate_limit_count( extrachill_users_registration_attempt_key(), EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW ) );
	}

	public function test_rest_service_preserves_structured_rate_limit_error(): void {
		$this->use_turnstile_result( true );
		$error = extrachill_users_registration_rate_limit_error( time() + 300 );
		$this->use_admission_result( $error );
		$result = extrachill_users_register_with_tokens(
			array(
				'email' => 'person@example.com',
				'password' => 'secure-password',
				'password_confirm' => 'secure-password',
				'device_id' => '123e4567-e89b-42d3-a456-426614174000',
				'turnstile_response' => 'valid-token',
			)
		);
		$this->assertSame( $error, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_form_adapter_propagates_safe_policy_error(): void {
		$this->use_turnstile_result( true );
		$this->use_admission_result( new WP_Error( 'registration_rate_limited', 'Try later.', array( 'status' => 429 ) ) );
		$result = extrachill_users_validate_registration_form_request( array( 'extrachill_email' => 'person@example.com', 'cf-turnstile-response' => 'valid-token' ) );
		$this->assertSame( 'registration_rate_limited', $result->get_error_code() );
		$this->assertSame( 'Try later.', $result->get_error_message() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_google_registration_does_not_enter_password_policy(): void {
		$this->use_turnstile_result( new WP_Error( 'unexpected_turnstile', 'Password policy ran.' ) );
		$this->use_admission_result( new WP_Error( 'unexpected_admission', 'Password policy ran.' ) );
		$result = ec_oauth_google_user( array( 'google_id' => 'google-registration-policy-test', 'email' => 'google-user@example.com', 'name' => 'Google User' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['is_new'] );
	}

	private function use_turnstile_result( $result ): void {
		add_filter( 'extrachill_users_registration_turnstile_verifier', static function () use ( $result ) {
			return static function () use ( $result ) {
				return $result;
			};
		} );
	}

	private function use_admission_result( $result ): void {
		add_filter( 'extrachill_users_registration_admitter', static function () use ( $result ) {
			return static function () use ( $result ) {
				return $result;
			};
		} );
	}
}
