<?php
/**
 * Tests for authenticated password changes.
 *
 * @package ExtraChill\Users
 */

class Test_Change_User_Password_Ability extends WP_UnitTestCase {

	private const CURRENT_PASSWORD = 'current-password';

	/**
	 * Create and authenticate a user with a known password.
	 *
	 * @return WP_User
	 */
	private function create_current_user(): WP_User {
		$user_id = self::factory()->user->create( array( 'user_pass' => self::CURRENT_PASSWORD ) );
		wp_set_current_user( $user_id );

		return get_userdata( $user_id );
	}

	/**
	 * Capture mutable credential state that validation failures must preserve.
	 *
	 * @param WP_User $user User to inspect.
	 * @return array
	 */
	private function capture_credential_state( WP_User $user ): array {
		$reset_key = get_password_reset_key( $user );
		$this->assertNotWPError( $reset_key );

		$sessions = WP_Session_Tokens::get_instance( $user->ID );
		$sessions->create( time() + HOUR_IN_SECONDS );

		return array(
			'hash'             => get_userdata( $user->ID )->user_pass,
			'recovery_key'     => get_userdata( $user->ID )->user_activation_key,
			'sessions'         => $sessions->get_all(),
			'auth_cookie'      => isset( $_COOKIE[ AUTH_COOKIE ] ) ? $_COOKIE[ AUTH_COOKIE ] : null,
			'logged_in_cookie' => isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null,
		);
	}

	/**
	 * Assert credential state was not touched.
	 *
	 * @param WP_User $user   User to inspect.
	 * @param array   $before Original state.
	 */
	private function assert_credential_state_unchanged( WP_User $user, array $before ): void {
		$persisted = get_userdata( $user->ID );

		$this->assertSame( $before['hash'], $persisted->user_pass );
		$this->assertSame( $before['recovery_key'], $persisted->user_activation_key );
		$this->assertSame( $before['sessions'], WP_Session_Tokens::get_instance( $user->ID )->get_all() );
		$this->assertSame( $before['auth_cookie'], isset( $_COOKIE[ AUTH_COOKIE ] ) ? $_COOKIE[ AUTH_COOKIE ] : null );
		$this->assertSame( $before['logged_in_cookie'], isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null );
	}

	public function test_one_character_password_is_rejected_without_mutation(): void {
		$user   = $this->create_current_user();
		$before = $this->capture_credential_state( $user );
		$result = extrachill_users_ability_change_password(
			array(
				'current_password' => self::CURRENT_PASSWORD,
				'new_password'     => 'x',
				'confirm_password' => 'x',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'password_too_short', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assert_credential_state_unchanged( $user, $before );
	}

	public function test_seven_character_password_is_rejected_and_eight_is_accepted(): void {
		$user   = $this->create_current_user();
		$before = $this->capture_credential_state( $user );
		$result = extrachill_users_ability_change_password(
			array(
				'current_password' => self::CURRENT_PASSWORD,
				'new_password'     => '1234567',
				'confirm_password' => '1234567',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'password_too_short', $result->get_error_code() );
		$this->assert_credential_state_unchanged( $user, $before );

		$result = extrachill_users_ability_change_password(
			array(
				'current_password' => self::CURRENT_PASSWORD,
				'new_password'     => '12345678',
				'confirm_password' => '12345678',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( wp_check_password( '12345678', get_userdata( $user->ID )->user_pass, $user->ID ) );
	}

	public function test_malformed_password_is_rejected_without_mutation(): void {
		$user   = $this->create_current_user();
		$before = $this->capture_credential_state( $user );
		$result = extrachill_users_ability_change_password(
			array(
				'current_password' => self::CURRENT_PASSWORD,
				'new_password'     => 'bad\\password',
				'confirm_password' => 'bad\\password',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_password', $result->get_error_code() );
		$this->assert_credential_state_unchanged( $user, $before );
	}

	public function test_wrong_current_password_and_confirmation_do_not_mutate(): void {
		$user   = $this->create_current_user();
		$before = $this->capture_credential_state( $user );

		$wrong_current = extrachill_users_ability_change_password(
			array(
				'current_password' => 'incorrect-password',
				'new_password'     => 'new-password',
				'confirm_password' => 'new-password',
			)
		);
		$this->assertWPError( $wrong_current );
		$this->assertSame( 'incorrect_password', $wrong_current->get_error_code() );
		$this->assert_credential_state_unchanged( $user, $before );

		$mismatch = extrachill_users_ability_change_password(
			array(
				'current_password' => self::CURRENT_PASSWORD,
				'new_password'     => 'new-password',
				'confirm_password' => 'other-password',
			)
		);
		$this->assertWPError( $mismatch );
		$this->assertSame( 'password_mismatch', $mismatch->get_error_code() );
		$this->assert_credential_state_unchanged( $user, $before );
	}

	public function test_user_id_injection_cannot_change_another_user_password(): void {
		$user         = $this->create_current_user();
		$other_id     = self::factory()->user->create( array( 'user_pass' => 'other-password' ) );
		$other_before = get_userdata( $other_id )->user_pass;
		$result       = extrachill_users_ability_change_password(
			array(
				'user_id'          => $other_id,
				'current_password' => self::CURRENT_PASSWORD,
				'new_password'     => 'changed-password',
				'confirm_password' => 'changed-password',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( wp_check_password( 'changed-password', get_userdata( $user->ID )->user_pass, $user->ID ) );
		$this->assertSame( $other_before, get_userdata( $other_id )->user_pass );
		$this->assertTrue( wp_check_password( 'other-password', get_userdata( $other_id )->user_pass, $other_id ) );
	}
}
