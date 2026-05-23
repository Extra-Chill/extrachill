<?php
/**
 * Unit tests for password reset rate-limiter functions.
 *
 * Covers the trio added in #35:
 *   - ec_get_password_reset_attempt_key()
 *   - ec_is_password_reset_blocked()
 *   - ec_record_password_reset_attempt()
 *
 * The rate limit is intentionally keyed on requester IP only (not on
 * submitted user_login) so attackers cannot bypass it by varying input.
 * Threshold is 5 attempts per 15-minute window.
 */

class Test_Password_Reset_Rate_Limit extends WP_UnitTestCase {

	private const IP_A = '203.0.113.10';
	private const IP_B = '203.0.113.20';

	protected function setUp(): void {
		parent::setUp();

		// Defensive: the plugin loader pulls this file in, but ensure the
		// functions are available even if a test runs in isolation.
		if ( ! function_exists( 'ec_get_password_reset_attempt_key' ) ) {
			require_once dirname( __DIR__, 2 ) . '/inc/auth/password-reset.php';
		}

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	protected function tearDown(): void {
		// Clean transients for both IPs and the empty-IP case so tests don't bleed.
		foreach ( array( self::IP_A, self::IP_B, '' ) as $ip ) {
			delete_transient( 'ec_password_reset_attempts_' . md5( $ip ) );
		}

		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// ec_get_password_reset_attempt_key()
	// ---------------------------------------------------------------------

	public function test_key_uses_expected_prefix_and_md5_format(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		$key                    = ec_get_password_reset_attempt_key();

		$this->assertStringStartsWith( 'ec_password_reset_attempts_', $key );
		$this->assertMatchesRegularExpression( '/^ec_password_reset_attempts_[a-f0-9]{32}$/', $key );
	}

	public function test_key_is_deterministic_for_same_ip(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;

		$this->assertSame( ec_get_password_reset_attempt_key(), ec_get_password_reset_attempt_key() );
	}

	public function test_key_differs_per_ip(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		$key_a                  = ec_get_password_reset_attempt_key();

		$_SERVER['REMOTE_ADDR'] = self::IP_B;
		$key_b                  = ec_get_password_reset_attempt_key();

		$this->assertNotSame( $key_a, $key_b );
	}

	public function test_key_handles_missing_remote_addr(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$key = ec_get_password_reset_attempt_key();

		// Empty IP → md5('') = d41d8cd98f00b204e9800998ecf8427e.
		$this->assertSame( 'ec_password_reset_attempts_' . md5( '' ), $key );
	}

	// ---------------------------------------------------------------------
	// ec_is_password_reset_blocked()
	// ---------------------------------------------------------------------

	public function test_not_blocked_when_no_transient(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;

		$this->assertFalse( ec_is_password_reset_blocked() );
	}

	/**
	 * @dataProvider below_threshold_attempt_counts
	 */
	public function test_not_blocked_below_threshold( int $attempts ): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		set_transient( ec_get_password_reset_attempt_key(), $attempts, 15 * MINUTE_IN_SECONDS );

		$this->assertFalse( ec_is_password_reset_blocked(), "Expected not blocked at {$attempts} attempts" );
	}

	public function below_threshold_attempt_counts(): array {
		return array(
			'1 attempt'  => array( 1 ),
			'2 attempts' => array( 2 ),
			'3 attempts' => array( 3 ),
			'4 attempts' => array( 4 ),
		);
	}

	public function test_blocked_at_threshold(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		set_transient( ec_get_password_reset_attempt_key(), 5, 15 * MINUTE_IN_SECONDS );

		$this->assertTrue( ec_is_password_reset_blocked() );
	}

	public function test_blocked_above_threshold(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		set_transient( ec_get_password_reset_attempt_key(), 42, 15 * MINUTE_IN_SECONDS );

		$this->assertTrue( ec_is_password_reset_blocked() );
	}

	// ---------------------------------------------------------------------
	// ec_record_password_reset_attempt()
	// ---------------------------------------------------------------------

	public function test_record_first_attempt_sets_counter_to_one(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		ec_record_password_reset_attempt();

		$this->assertSame( 1, get_transient( ec_get_password_reset_attempt_key() ) );
	}

	public function test_record_subsequent_attempts_increment(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		ec_record_password_reset_attempt();
		ec_record_password_reset_attempt();
		ec_record_password_reset_attempt();

		$this->assertSame( 3, get_transient( ec_get_password_reset_attempt_key() ) );
	}

	public function test_record_isolates_counters_per_ip(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		ec_record_password_reset_attempt();
		ec_record_password_reset_attempt();
		$key_a = ec_get_password_reset_attempt_key();

		$_SERVER['REMOTE_ADDR'] = self::IP_B;
		ec_record_password_reset_attempt();
		$key_b = ec_get_password_reset_attempt_key();

		$this->assertSame( 2, get_transient( $key_a ), 'IP A counter should be 2 after two records' );
		$this->assertSame( 1, get_transient( $key_b ), 'IP B counter should be 1 after one record' );
	}

	// ---------------------------------------------------------------------
	// End-to-end: record → check
	// ---------------------------------------------------------------------

	public function test_four_records_does_not_block(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;

		for ( $i = 0; $i < 4; $i++ ) {
			ec_record_password_reset_attempt();
		}

		$this->assertFalse( ec_is_password_reset_blocked() );
	}

	public function test_five_records_blocks(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;

		for ( $i = 0; $i < 5; $i++ ) {
			ec_record_password_reset_attempt();
		}

		$this->assertTrue( ec_is_password_reset_blocked() );
	}

	public function test_block_does_not_affect_other_ip(): void {
		$_SERVER['REMOTE_ADDR'] = self::IP_A;
		for ( $i = 0; $i < 5; $i++ ) {
			ec_record_password_reset_attempt();
		}
		$this->assertTrue( ec_is_password_reset_blocked(), 'IP A should be blocked' );

		// Switch to IP B — should be unaffected.
		$_SERVER['REMOTE_ADDR'] = self::IP_B;
		$this->assertFalse( ec_is_password_reset_blocked(), 'IP B should NOT be blocked by IP A activity' );
	}
}
