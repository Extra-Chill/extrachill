<?php
/**
 * Unit tests for claiming accounts through password reset completion.
 */

class Test_Password_Claim extends WP_UnitTestCase {

	private function create_user( bool $unclaimed = true ): WP_User {
		$user_id = self::factory()->user->create( array( 'user_pass' => 'original-password' ) );

		if ( $unclaimed ) {
			update_user_meta( $user_id, 'ec_unclaimed', 1 );
		}

		return get_user_by( 'ID', $user_id );
	}

	public function test_successful_custom_password_claim_runs_completion_in_order(): void {
		$user = $this->create_user();
		$key  = get_password_reset_key( $user );
		$order = array();
		$before_reset = static function ( $reset_user ) use ( &$order ): void {
			$order[] = 'password_reset:' . get_user_meta( $reset_user->ID, 'ec_unclaimed', true );
		};
		$after_reset  = static function ( $reset_user ) use ( &$order ): void {
			$order[] = 'after_password_reset:' . get_user_meta( $reset_user->ID, 'ec_unclaimed', true );
		};
		add_action( 'password_reset', $before_reset );
		add_action( 'after_password_reset', $after_reset, 20 );

		$this->assertNotWPError( $key );

		try {
			$result = ec_process_reset_password_submission( $key, $user->user_login, 'new-secure-password', 'new-secure-password' );
		} finally {
			remove_action( 'password_reset', $before_reset );
			remove_action( 'after_password_reset', $after_reset, 20 );
		}

		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertSame( array( 'password_reset:1', 'after_password_reset:' ), $order );
		$this->assertSame( '', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertTrue( wp_check_password( 'new-secure-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_invalid_custom_reset_key_does_not_clear_unclaimed_marker(): void {
		$user = $this->create_user();
		$result = ec_process_reset_password_submission( 'invalid-key', $user->user_login, 'new-secure-password', 'new-secure-password' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_reset_key', $result->get_error_code() );
		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertTrue( wp_check_password( 'original-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_custom_password_mismatch_does_not_clear_unclaimed_marker(): void {
		$user = $this->create_user();
		$key  = get_password_reset_key( $user );

		$this->assertNotWPError( $key );
		$result = ec_process_reset_password_submission( $key, $user->user_login, 'new-secure-password', 'different-password' );

		$this->assertWPError( $result );
		$this->assertSame( 'password_mismatch', $result->get_error_code() );
		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertNotWPError( check_password_reset_key( $key, $user->user_login ) );
		$this->assertTrue( wp_check_password( 'original-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_custom_password_validation_failure_does_not_clear_unclaimed_marker(): void {
		$user = $this->create_user();
		$key  = get_password_reset_key( $user );

		$this->assertNotWPError( $key );
		$result = ec_process_reset_password_submission( $key, $user->user_login, 'short', 'short' );

		$this->assertWPError( $result );
		$this->assertSame( 'password_too_short', $result->get_error_code() );
		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertNotWPError( check_password_reset_key( $key, $user->user_login ) );
		$this->assertTrue( wp_check_password( 'original-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_core_password_reset_clears_unclaimed_marker(): void {
		$user = $this->create_user();

		reset_password( $user, 'new-secure-password' );

		$this->assertSame( '', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
	}

	public function test_core_reset_preserves_unclaimed_marker_when_deletion_fails(): void {
		$user = $this->create_user();
		$block_deletion = static function ( $check, $object_id, $meta_key ) use ( $user ) {
			if ( $user->ID === (int) $object_id && 'ec_unclaimed' === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'delete_user_metadata', $block_deletion, 10, 3 );

		try {
			reset_password( $user, 'new-secure-password' );
		} finally {
			remove_filter( 'delete_user_metadata', $block_deletion, 10 );
		}

		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertTrue( wp_check_password( 'new-secure-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_completion_does_not_clear_marker_without_persisted_password(): void {
		$user = $this->create_user();

		$result = extrachill_users_clear_unclaimed_after_password_reset( $user, 'password-that-was-not-stored' );

		$this->assertFalse( $result );
		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
	}

	public function test_custom_claim_reports_marker_deletion_failure(): void {
		$user = $this->create_user();
		$key  = get_password_reset_key( $user );
		$block_deletion = static function ( $check, $object_id, $meta_key ) use ( $user ) {
			if ( $user->ID === (int) $object_id && 'ec_unclaimed' === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'delete_user_metadata', $block_deletion, 10, 3 );

		$this->assertNotWPError( $key );

		try {
			$result = ec_process_reset_password_submission( $key, $user->user_login, 'new-secure-password', 'new-secure-password' );
		} finally {
			remove_filter( 'delete_user_metadata', $block_deletion, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'unclaimed_state_clear_failed', $result->get_error_code() );
		$this->assertSame( '1', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertTrue( wp_check_password( 'new-secure-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_repeated_completion_hook_is_harmless(): void {
		$user = $this->create_user();

		reset_password( $user, 'new-secure-password' );
		do_action( 'after_password_reset', $user, 'new-secure-password' );
		do_action( 'after_password_reset', $user, 'new-secure-password' );

		$this->assertSame( '', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
	}

	public function test_password_reset_for_already_claimed_account_is_harmless(): void {
		$user = $this->create_user( false );

		reset_password( $user, 'new-secure-password' );

		$this->assertSame( '', get_user_meta( $user->ID, 'ec_unclaimed', true ) );
		$this->assertTrue( wp_check_password( 'new-secure-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}
}
