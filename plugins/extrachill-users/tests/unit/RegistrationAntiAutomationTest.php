<?php
/**
 * Password registration anti-automation policy tests.
 *
 * @package ExtraChill\Users
 */

class Registration_Rate_Limit_Test_Cache {
	/** @var array<string,array{value:int,expires_at:int}> */
	private $data = array();

	/** @var int */
	public $now;

	/** @var callable|null */
	public $after_failed_add;

	public function __construct( int $now ) {
		$this->now = $now;
	}

	public function add( $key, $value, $group = 'default', $expiration = 0 ): bool {
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( $found ) {
			if ( is_callable( $this->after_failed_add ) ) {
				$callback               = $this->after_failed_add;
				$this->after_failed_add = null;
				$callback();
			}
			return false;
		}

		$this->data[ $this->id( $key, $group ) ] = array(
			'value'      => (int) $value,
			'expires_at' => $expiration ? $this->now + (int) $expiration : 0,
		);
		return true;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$id    = $this->id( $key, $group );
		$entry = $this->data[ $id ] ?? null;
		if ( is_array( $entry ) && ( 0 === $entry['expires_at'] || $entry['expires_at'] > $this->now ) ) {
			$found = true;
			return $entry['value'];
		}

		unset( $this->data[ $id ] );
		$found = false;
		return false;
	}

	public function incr( $key, $offset = 1, $group = 'default' ): int {
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		$id    = $this->id( $key, $group );
		if ( ! $found ) {
			// Redis INCRBY creates a non-expiring key when the key is absent.
			$this->data[ $id ] = array(
				'value'      => (int) $offset,
				'expires_at' => 0,
			);
			return (int) $offset;
		}

		$this->data[ $id ]['value'] = (int) $value + (int) $offset;
		return $this->data[ $id ]['value'];
	}

	public function delete( $key, $group = 'default' ): bool {
		$id = $this->id( $key, $group );
		if ( ! isset( $this->data[ $id ] ) ) {
			return false;
		}

		unset( $this->data[ $id ] );
		return true;
	}

	public function add_global_groups( $groups ): void {
		// Registration counters are always global in this deterministic cache.
	}

	public function ttl( $key, $group ): int {
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return -2;
		}

		$expires_at = $this->data[ $this->id( $key, $group ) ]['expires_at'];
		return 0 === $expires_at ? -1 : $expires_at - $this->now;
	}

	private function id( $key, $group ): string {
		return $group . ':' . $key;
	}
}

class Test_Registration_Anti_Automation extends WP_UnitTestCase {
	private const IP = '203.0.113.30';

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = self::IP;
		unset( $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		wp_cache_add_global_groups( EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP );
		$this->clear_registration_counter();
	}

	protected function tearDown(): void {
		$this->clear_registration_counter();
		remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
		remove_all_filters( 'extrachill_users_registration_admitter' );
		delete_site_option( 'banned_email_domains' );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_EXTRACHILL_CLIENT'] );
		parent::tearDown();
	}

	public function test_valid_registration_policy_passes(): void {
		$this->use_turnstile_result( true );
		$this->use_admission_result( true );

		$this->assertTrue( extrachill_users_validate_password_registration( 'person@example.com', 'valid-token' ) );
	}

	public function test_missing_and_invalid_turnstile_do_not_consume_admission(): void {
		$admission_calls = 0;
		$this->use_admission_callback(
			static function () use ( &$admission_calls ) {
				++$admission_calls;
				return true;
			}
		);
		$this->use_turnstile_error_for_token( '', 'turnstile_missing_token' );

		$missing = extrachill_users_validate_password_registration( 'person@example.com', '' );
		$this->assertSame( 'turnstile_missing_token', $missing->get_error_code() );
		$this->assertSame( 0, $admission_calls );

		remove_all_filters( 'extrachill_users_registration_turnstile_verifier' );
		$this->use_turnstile_error_for_token( 'invalid-token', 'turnstile_failed' );
		$invalid = extrachill_users_validate_password_registration( 'person@example.com', 'invalid-token' );
		$this->assertSame( 'turnstile_failed', $invalid->get_error_code() );
		$this->assertSame( 0, $admission_calls );
	}

	public function test_spoofed_app_header_does_not_bypass_turnstile(): void {
		$_SERVER['HTTP_EXTRACHILL_CLIENT'] = 'app';
		$this->use_turnstile_error_for_token( '', 'turnstile_missing_token' );

		$result = extrachill_users_validate_password_registration( 'person@example.com', '' );

		$this->assertSame( 'turnstile_missing_token', $result->get_error_code() );
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
		$this->use_admission_callback(
			static function () use ( &$order ) {
				$order[] = 'admission';
				return true;
			}
		);

		extrachill_users_validate_password_registration( 'person@example.com', 'valid-token' );

		$this->assertSame( array( 'turnstile', 'admission' ), $order );
	}

	public function test_atomic_increment_admits_exactly_five_attempts(): void {
		$key        = extrachill_users_registration_attempt_key();
		$expires_at = time() + EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW;

		for ( $attempt = 1; $attempt <= EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT; ++$attempt ) {
			$this->assertTrue( extrachill_users_increment_registration_attempt( $key, $expires_at ) );
		}

		$result = extrachill_users_increment_registration_attempt( $key, $expires_at );
		$this->assertSame( 'registration_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( $expires_at, $result->get_error_data()['expires_at'] );
		$this->assertSame( 6, wp_cache_get( $key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP ) );
	}

	public function test_counter_is_network_global_when_switching_blogs(): void {
		$key        = extrachill_users_registration_attempt_key();
		$expires_at = time() + EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW;
		extrachill_users_increment_registration_attempt( $key, $expires_at );

		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		try {
			$this->assertSame( $key, extrachill_users_registration_attempt_key() );
			extrachill_users_increment_registration_attempt( $key, $expires_at );
			$this->assertSame( 2, wp_cache_get( $key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_fixed_window_key_resets_at_expiry_boundary(): void {
		$now         = time();
		$window      = intdiv( $now, EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW );
		$expires_at  = ( $window + 1 ) * EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW;
		$current_key = extrachill_users_registration_attempt_key( $now );
		$next_key    = extrachill_users_registration_attempt_key( $expires_at );

		$this->assertNotSame( $current_key, $next_key );
		for ( $attempt = 0; $attempt < EXTRACHILL_USERS_REGISTRATION_RATE_LIMIT; ++$attempt ) {
			extrachill_users_increment_registration_attempt( $current_key, $expires_at );
		}
		$this->assertSame(
			'registration_rate_limited',
			extrachill_users_increment_registration_attempt( $current_key, $expires_at )->get_error_code()
		);
		$this->assertTrue(
			extrachill_users_increment_registration_attempt(
				$next_key,
				$expires_at + EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW
			)
		);

		wp_cache_delete( $next_key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP );
	}

	public function test_add_loser_retains_bounded_ttl_across_window_rollover(): void {
		$original_cache = $GLOBALS['wp_object_cache'];
		$window_end     = ( intdiv( time(), EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW ) + 1 ) * EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW;
		$cache          = new Registration_Rate_Limit_Test_Cache( $window_end - 1 );
		$old_key        = extrachill_users_registration_attempt_key( $cache->now );
		$next_key       = extrachill_users_registration_attempt_key( $window_end );

		$GLOBALS['wp_object_cache'] = $cache;
		try {
			$this->assertTrue( extrachill_users_increment_registration_attempt( $old_key, $window_end ) );
			$cache->after_failed_add = static function () use ( $cache ): void {
				$cache->now += 2;
			};

			$this->assertTrue( extrachill_users_increment_registration_attempt( $old_key, $window_end ) );
			$this->assertSame( 2, wp_cache_get( $old_key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP ) );
			$this->assertSame(
				( 2 * EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW ) - 2,
				$cache->ttl( $old_key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP )
			);
			$this->assertGreaterThan( 0, $cache->ttl( $old_key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP ) );
			$this->assertLessThanOrEqual( 2 * EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW, $cache->ttl( $old_key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP ) );

			$this->assertTrue( extrachill_users_increment_registration_attempt( $next_key, $window_end + EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW ) );
			$this->assertSame( 1, wp_cache_get( $next_key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP ) );

			$cache->now = ( $window_end - 1 ) + ( 2 * EXTRACHILL_USERS_REGISTRATION_RATE_WINDOW );
			$this->assertSame( -2, $cache->ttl( $old_key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP ) );
		} finally {
			$GLOBALS['wp_object_cache'] = $original_cache;
		}
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
		$this->use_admission_result( true );
		update_site_option( 'banned_email_domains', array( 'blocked.example' ) );

		$result = extrachill_users_validate_password_registration( 'person@sub.blocked.example', 'valid-token' );

		$this->assertSame( 'unsafe_email', $result->get_error_code() );
	}

	public function test_rest_service_preserves_structured_rate_limit_error(): void {
		$this->use_turnstile_result( true );
		$error = extrachill_users_registration_rate_limit_error( time() + 300 );
		$this->use_admission_result( $error );

		$result = extrachill_users_register_with_tokens(
			array(
				'email'              => 'person@example.com',
				'password'           => 'secure-password',
				'password_confirm'   => 'secure-password',
				'device_id'          => '123e4567-e89b-42d3-a456-426614174000',
				'turnstile_response' => 'valid-token',
			)
		);

		$this->assertSame( $error, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'retry_after', $result->get_error_data() );
		$this->assertArrayHasKey( 'expires_at', $result->get_error_data() );
	}

	public function test_form_adapter_propagates_safe_policy_error(): void {
		$this->use_turnstile_result( true );
		$this->use_admission_result( new WP_Error( 'registration_rate_limited', 'Try later.', array( 'status' => 429 ) ) );

		$result = extrachill_users_validate_registration_form_request(
			array(
				'extrachill_email'      => 'person@example.com',
				'cf-turnstile-response' => 'valid-token',
			)
		);

		$this->assertSame( 'registration_rate_limited', $result->get_error_code() );
		$this->assertSame( 'Try later.', $result->get_error_message() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_google_registration_does_not_enter_password_policy(): void {
		$this->use_turnstile_result( new WP_Error( 'unexpected_turnstile', 'Password policy ran.' ) );
		$this->use_admission_result( new WP_Error( 'unexpected_admission', 'Password policy ran.' ) );

		$result = ec_oauth_google_user(
			array(
				'google_id' => 'google-registration-policy-test',
				'email'     => 'google-user@example.com',
				'name'      => 'Google User',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['is_new'] );
	}

	private function clear_registration_counter(): void {
		$key = extrachill_users_registration_attempt_key();
		if ( '' !== $key ) {
			wp_cache_delete( $key, EXTRACHILL_USERS_REGISTRATION_CACHE_GROUP );
		}
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

	private function use_admission_result( $result ): void {
		$this->use_admission_callback(
			static function () use ( $result ) {
				return $result;
			}
		);
	}

	private function use_admission_callback( callable $callback ): void {
		add_filter(
			'extrachill_users_registration_admitter',
			static function () use ( $callback ) {
				return $callback;
			}
		);
	}
}
