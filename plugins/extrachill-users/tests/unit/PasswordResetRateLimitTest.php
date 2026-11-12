<?php
/**
 * Authentication adapters use the shared fixed-window limiter.
 *
 * @package ExtraChill\Users
 */
class Test_Authentication_Rate_Limits extends WP_UnitTestCase {
	private $counts = array();
	private $fail = false;

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		add_filter(
			'extrachill_rate_limit_store',
			function () {
				return function ( $key, $ttl, $operation = 'increment' ) {
					if ( $this->fail ) {
						return extrachill_rate_limit_unavailable();
					}
					if ( 'reset' === $operation ) {
						unset( $this->counts[ $key ] );
						return true;
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
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_authenticate_admits_fifth_failure_and_blocks_sixth(): void {
		for ( $attempt = 0; $attempt < EXTRACHILL_USERS_LOGIN_RATE_LIMIT; ++$attempt ) {
			$result = apply_filters( 'authenticate', new WP_Error( 'incorrect_password', 'No.' ), 'person@example.com', 'wrong' );
			$this->assertSame( 'incorrect_password', $result->get_error_code() );
		}
		$this->assertTrue( ec_is_login_blocked( 'person@example.com' ) );
		$result = apply_filters( 'authenticate', new WP_Error( 'incorrect_password', 'No.' ), 'person@example.com', 'wrong' );
		$this->assertSame( 'ec_login_blocked', $result->get_error_code() );
		$this->assertSame( 6, extrachill_rate_limit_count( ec_get_login_attempt_key( 'person@example.com' ), EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) );
	}

	public function test_successful_credentials_are_blocked_at_limit(): void {
		for ( $attempt = 0; $attempt < EXTRACHILL_USERS_LOGIN_RATE_LIMIT; ++$attempt ) {
			ec_record_failed_login( 'person@example.com' );
		}
		$result = ec_rate_limit_login( new WP_User(), 'person@example.com' );
		$this->assertSame( 'ec_login_blocked', $result->get_error_code() );
	}

	public function test_successful_login_resets_and_later_failure_survives(): void {
		ec_record_failed_login( 'person@example.com' );
		do_action( 'wp_login', 'person@example.com', new WP_User() );
		$this->assertSame( 0, extrachill_rate_limit_count( ec_get_login_attempt_key( 'person@example.com' ), EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) );
		$this->assertTrue( ec_record_failed_login( 'person@example.com' ) );
		$this->assertSame( 1, extrachill_rate_limit_count( ec_get_login_attempt_key( 'person@example.com' ), EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) );
	}

	public function test_two_factor_success_resets_attempts(): void {
		$user_id = self::factory()->user->create();
		$user    = get_user_by( 'id', $user_id );
		ec_record_failed_login( $user->user_login );
		do_action( 'two_factor_user_authenticated', $user );
		$this->assertSame( 0, extrachill_rate_limit_count( ec_get_login_attempt_key( $user->user_login ), EXTRACHILL_USERS_LOGIN_RATE_WINDOW ) );
	}

	public function test_login_identity_aliases_share_canonical_counter_key(): void {
		self::factory()->user->create( array( 'user_login' => 'canonical-person', 'user_email' => 'canonical@example.com' ) );
		$this->assertSame( ec_get_login_attempt_key( 'canonical-person' ), ec_get_login_attempt_key( 'canonical@example.com' ) );
	}

	public function test_password_reset_boundary_and_independent_budget(): void {
		for ( $attempt = 0; $attempt < EXTRACHILL_USERS_PASSWORD_RESET_RATE_LIMIT; ++$attempt ) {
			$this->assertFalse( ec_is_password_reset_blocked() );
			$this->assertTrue( ec_record_password_reset_attempt() );
		}
		$this->assertTrue( ec_is_password_reset_blocked() );
		$retry = ec_record_password_reset_attempt();
		$this->assertIsInt( $retry );
		$this->assertGreaterThan( 0, $retry );
		$this->assertLessThanOrEqual( EXTRACHILL_USERS_PASSWORD_RESET_RATE_WINDOW, $retry );
		$this->assertFalse( ec_is_login_blocked( 'person@example.com' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		$this->assertFalse( ec_is_password_reset_blocked() );
	}

	public function test_storage_failure_preserves_public_errors_and_fails_closed(): void {
		$this->fail = true;
		$this->assertSame( 'ec_login_limiter_unavailable', ec_record_failed_login( 'person@example.com' )->get_error_code() );
		$this->assertSame( 'ec_login_limiter_unavailable', ec_clear_login_attempts( 'person@example.com' )->get_error_code() );
		$this->assertSame( 'ec_login_limiter_unavailable', ec_rate_limit_login( new WP_User(), 'person@example.com' )->get_error_code() );
		$this->assertSame( 'ec_password_reset_limiter_unavailable', ec_record_password_reset_attempt()->get_error_code() );
		$this->assertTrue( ec_is_login_blocked( 'person@example.com' ) );
		$this->assertTrue( ec_is_password_reset_blocked() );
	}

	public function test_missing_request_ip_fails_closed(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertTrue( ec_is_login_blocked( 'person@example.com' ) );
		$this->assertTrue( ec_is_password_reset_blocked() );
		$this->assertWPError( ec_record_failed_login( 'person@example.com' ) );
		$this->assertWPError( ec_record_password_reset_attempt() );
		$this->assertWPError( ec_clear_login_attempts( 'person@example.com' ) );
	}
}
