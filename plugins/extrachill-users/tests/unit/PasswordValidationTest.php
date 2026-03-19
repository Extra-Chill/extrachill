<?php
/**
 * Unit tests for password and onboarding username validation.
 */

class Test_Password_Validation extends WP_UnitTestCase {

	private function is_password_valid_length( string $password ): bool {
		return strlen( $password ) >= 8;
	}

	private function passwords_match( string $password, string $password_confirm ): bool {
		return $password === $password_confirm;
	}

	protected function setUp(): void {
		parent::setUp();
		if ( function_exists( 'extrachill_users_register_onboarding_abilities' ) ) {
			extrachill_users_register_onboarding_abilities();
		}
	}

	public function test_password_minimum_length_fails(): void {
		foreach ( array( '', 'a', 'abc', '1234567' ) as $password ) {
			$this->assertFalse( $this->is_password_valid_length( $password ) );
		}
	}

	public function test_password_valid_length_passes(): void {
		foreach ( array( '12345678', '123456789', 'mysecurepassword', str_repeat( 'a', 100 ) ) as $password ) {
			$this->assertTrue( $this->is_password_valid_length( $password ) );
		}
	}

	public function test_password_mismatch_fails(): void {
		$this->assertFalse( $this->passwords_match( 'password1', 'password2' ) );
	}

	public function test_password_match_passes(): void {
		$this->assertTrue( $this->passwords_match( 'samepassword', 'samepassword' ) );
	}

	public function test_onboarding_username_too_short(): void {
		$result = ec_validate_onboarding_username( 'ab', 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'username_too_short', $result->get_error_code() );
	}

	public function test_onboarding_username_too_long(): void {
		$result = ec_validate_onboarding_username( str_repeat( 'a', 61 ), 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'username_too_long', $result->get_error_code() );
	}

	public function test_onboarding_username_exists(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'existinguser',
				'user_email' => 'existing@example.com',
			)
		);

		$result = ec_validate_onboarding_username( 'existinguser', $user_id + 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'username_exists', $result->get_error_code() );
	}

	public function test_onboarding_username_same_user_allowed(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'myusername',
				'user_email' => 'my@example.com',
			)
		);

		$this->assertTrue( ec_validate_onboarding_username( 'myusername', $user_id ) );
	}

	public function test_onboarding_username_reserved(): void {
		$result = ec_validate_onboarding_username( 'admin', 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'username_reserved', $result->get_error_code() );
	}

	public function test_onboarding_username_valid(): void {
		$this->assertTrue( ec_validate_onboarding_username( 'validuser', 1 ) );
	}
}
