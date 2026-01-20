<?php
/**
 * Unit tests for password validation.
 *
 * Tests the validation rules used in registration (inc/auth/register.php).
 * Since validation is inline, we test the rules directly.
 */

use PHPUnit\Framework\TestCase;

class Test_Password_Validation extends TestCase {

	/**
	 * Set up before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		reset_wp_mocks();
	}

	/**
	 * Validate password length (minimum 8 characters).
	 *
	 * @param string $password Password to validate.
	 * @return bool True if valid length.
	 */
	private function is_password_valid_length( $password ) {
		return strlen( $password ) >= 8;
	}

	/**
	 * Validate passwords match.
	 *
	 * @param string $password         Password.
	 * @param string $password_confirm Confirmation password.
	 * @return bool True if passwords match.
	 */
	private function passwords_match( $password, $password_confirm ) {
		return $password === $password_confirm;
	}

	/**
	 * Test password under 8 characters fails validation.
	 */
	public function test_password_minimum_length_fails() {
		$short_passwords = array(
			'',
			'a',
			'abc',
			'1234567', // 7 chars
		);

		foreach ( $short_passwords as $password ) {
			$this->assertFalse(
				$this->is_password_valid_length( $password ),
				"Password '$password' (length " . strlen( $password ) . ') should fail validation'
			);
		}
	}

	/**
	 * Test password 8 or more characters passes validation.
	 */
	public function test_password_valid_length_passes() {
		$valid_passwords = array(
			'12345678',      // Exactly 8 chars.
			'123456789',     // 9 chars.
			'mysecurepassword',
			str_repeat( 'a', 100 ), // Long password.
		);

		foreach ( $valid_passwords as $password ) {
			$this->assertTrue(
				$this->is_password_valid_length( $password ),
				"Password (length " . strlen( $password ) . ') should pass validation'
			);
		}
	}

	/**
	 * Test mismatched passwords fail.
	 */
	public function test_password_mismatch_fails() {
		$this->assertFalse( $this->passwords_match( 'password1', 'password2' ) );
		$this->assertFalse( $this->passwords_match( 'Password', 'password' ) ); // Case sensitive.
		$this->assertFalse( $this->passwords_match( 'test ', 'test' ) ); // Whitespace.
	}

	/**
	 * Test matching passwords pass.
	 */
	public function test_password_match_passes() {
		$this->assertTrue( $this->passwords_match( 'samepassword', 'samepassword' ) );
		$this->assertTrue( $this->passwords_match( '', '' ) );
		$this->assertTrue( $this->passwords_match( 'P@ssw0rd!', 'P@ssw0rd!' ) );
	}

	/**
	 * Test full validation combines both checks.
	 */
	public function test_password_full_validation() {
		$validate = function ( $password, $confirm ) {
			if ( ! $this->passwords_match( $password, $confirm ) ) {
				return 'mismatch';
			}
			if ( ! $this->is_password_valid_length( $password ) ) {
				return 'too_short';
			}
			return true;
		};

		// Valid case.
		$this->assertTrue( $validate( 'validpass', 'validpass' ) );

		// Mismatch (checked first).
		$this->assertEquals( 'mismatch', $validate( 'password1', 'password2' ) );

		// Too short.
		$this->assertEquals( 'too_short', $validate( 'short', 'short' ) );
	}

	/**
	 * Test onboarding username validation - too short.
	 */
	public function test_onboarding_username_too_short() {
		$result = ec_validate_onboarding_username( 'ab', 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'username_too_short', $result->get_error_code() );
	}

	/**
	 * Test onboarding username validation - too long.
	 */
	public function test_onboarding_username_too_long() {
		$long_username = str_repeat( 'a', 61 );
		$result        = ec_validate_onboarding_username( $long_username, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'username_too_long', $result->get_error_code() );
	}

	/**
	 * Test onboarding username validation - invalid characters get stripped.
	 *
	 * Note: sanitize_user($username, true) strips invalid characters,
	 * so 'user@name' becomes 'username' which is valid.
	 */
	public function test_onboarding_username_invalid_chars_stripped() {
		// user@name becomes username after sanitize_user(, true).
		$result = ec_validate_onboarding_username( 'user@name', 1 );

		// Since sanitize_user strips the @, this becomes 'username' which is valid.
		$this->assertTrue( $result );
	}

	/**
	 * Test onboarding username with only special chars becomes too short.
	 */
	public function test_onboarding_username_only_special_chars() {
		// @#$%^& becomes empty string after sanitize, then fails length check.
		$result = ec_validate_onboarding_username( '@#', 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'username_too_short', $result->get_error_code() );
	}

	/**
	 * Test onboarding username validation - already exists.
	 */
	public function test_onboarding_username_exists() {
		$GLOBALS['__test_users'][2] = array(
			'ID'         => 2,
			'user_login' => 'existinguser',
			'user_email' => 'existing@example.com',
		);

		$result = ec_validate_onboarding_username( 'existinguser', 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'username_exists', $result->get_error_code() );
	}

	/**
	 * Test onboarding username validation - same user can keep username.
	 */
	public function test_onboarding_username_same_user_allowed() {
		$GLOBALS['__test_users'][1] = array(
			'ID'         => 1,
			'user_login' => 'myusername',
			'user_email' => 'my@example.com',
		);

		$result = ec_validate_onboarding_username( 'myusername', 1 );

		$this->assertTrue( $result );
	}

	/**
	 * Test onboarding username validation - reserved usernames.
	 */
	public function test_onboarding_username_reserved() {
		$reserved = array( 'admin', 'administrator', 'extrachill', 'support', 'help', 'moderator' );

		foreach ( $reserved as $username ) {
			$result = ec_validate_onboarding_username( $username, 1 );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEquals( 'username_reserved', $result->get_error_code(), "Username '$username' should be reserved" );
		}
	}

	/**
	 * Test onboarding username validation - valid username.
	 */
	public function test_onboarding_username_valid() {
		$valid_usernames = array(
			'validuser',
			'user123',
			'test_user',
			'test-user',
			'TestUser',
		);

		foreach ( $valid_usernames as $username ) {
			$result = ec_validate_onboarding_username( $username, 1 );

			$this->assertTrue( $result, "Username '$username' should be valid" );
		}
	}
}
