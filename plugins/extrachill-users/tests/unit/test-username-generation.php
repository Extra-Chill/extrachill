<?php
/**
 * Unit tests for username generation functions.
 *
 * Tests the functions in inc/onboarding/service.php
 */

use PHPUnit\Framework\TestCase;

class Test_Username_Generation extends TestCase {

	/**
	 * Set up before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		reset_wp_mocks();
	}

	/**
	 * Test basic email to username conversion.
	 */
	public function test_username_from_email_basic() {
		$username = ec_generate_username_from_email( 'user@example.com' );

		$this->assertEquals( 'user', $username );
	}

	/**
	 * Test email with dots converts to username without dots.
	 */
	public function test_username_from_email_with_dots() {
		$username = ec_generate_username_from_email( 'first.last@example.com' );

		$this->assertEquals( 'firstlast', $username );
	}

	/**
	 * Test email with special characters strips them.
	 */
	public function test_username_from_email_strips_special_chars() {
		$username = ec_generate_username_from_email( 'user+tag@example.com' );

		$this->assertEquals( 'usertag', $username );
	}

	/**
	 * Test email with numbers preserves numbers.
	 */
	public function test_username_from_email_preserves_numbers() {
		$username = ec_generate_username_from_email( 'user123@example.com' );

		$this->assertEquals( 'user123', $username );
	}

	/**
	 * Test username uniqueness by appending numbers.
	 */
	public function test_username_from_email_uniqueness() {
		// Create existing users with the base username.
		$GLOBALS['__test_users'][1] = array(
			'ID'         => 1,
			'user_login' => 'john',
			'user_email' => 'john@first.com',
		);
		$GLOBALS['__test_users'][2] = array(
			'ID'         => 2,
			'user_login' => 'john1',
			'user_email' => 'john@second.com',
		);

		// New user with same base should get john2.
		$username = ec_generate_username_from_email( 'john@third.com' );

		$this->assertEquals( 'john2', $username );
	}

	/**
	 * Test very short email local part falls back to 'user'.
	 */
	public function test_username_from_email_short_fallback() {
		$username = ec_generate_username_from_email( 'ab@example.com' );

		$this->assertEquals( 'user', $username );
	}

	/**
	 * Test invalid email falls back to 'user'.
	 */
	public function test_username_from_email_invalid() {
		$username = ec_generate_username_from_email( 'not-an-email' );

		// When there's no @, strstr returns false, falls back to 'user'.
		$this->assertEquals( 'user', $username );
	}

	/**
	 * Test sanitize_username_base converts to lowercase.
	 */
	public function test_sanitize_username_base_lowercase() {
		$result = ec_sanitize_username_base( 'JohnDoe' );

		$this->assertEquals( 'johndoe', $result );
	}

	/**
	 * Test sanitize_username_base removes special characters.
	 */
	public function test_sanitize_username_base_removes_special() {
		$result = ec_sanitize_username_base( 'john.doe-123' );

		$this->assertEquals( 'johndoe123', $result );
	}

	/**
	 * Test sanitize_username_base truncates to 50 chars.
	 */
	public function test_sanitize_username_base_max_length() {
		$long_input = str_repeat( 'a', 100 );
		$result     = ec_sanitize_username_base( $long_input );

		$this->assertEquals( 50, strlen( $result ) );
	}

	/**
	 * Test sanitize_username_base with only special chars falls back.
	 */
	public function test_sanitize_username_base_all_special() {
		$result = ec_sanitize_username_base( '@#$%^&' );

		$this->assertEquals( 'user', $result );
	}

	/**
	 * Test get_unique_username with no collision.
	 */
	public function test_get_unique_username_no_collision() {
		$result = ec_get_unique_username( 'newuser' );

		$this->assertEquals( 'newuser', $result );
	}

	/**
	 * Test get_unique_username increments on collision.
	 */
	public function test_get_unique_username_with_collision() {
		$GLOBALS['__test_users'][1] = array(
			'ID'         => 1,
			'user_login' => 'testuser',
			'user_email' => 'test@example.com',
		);

		$result = ec_get_unique_username( 'testuser' );

		$this->assertEquals( 'testuser1', $result );
	}

	/**
	 * Test get_unique_username handles multiple collisions.
	 */
	public function test_get_unique_username_multiple_collisions() {
		$GLOBALS['__test_users'][1] = array(
			'ID'         => 1,
			'user_login' => 'test',
			'user_email' => 'test1@example.com',
		);
		$GLOBALS['__test_users'][2] = array(
			'ID'         => 2,
			'user_login' => 'test1',
			'user_email' => 'test2@example.com',
		);
		$GLOBALS['__test_users'][3] = array(
			'ID'         => 3,
			'user_login' => 'test2',
			'user_email' => 'test3@example.com',
		);

		$result = ec_get_unique_username( 'test' );

		$this->assertEquals( 'test3', $result );
	}

	/**
	 * Test generate_username_from_name basic functionality.
	 */
	public function test_username_from_name_basic() {
		$result = ec_generate_username_from_name( 'John Smith' );

		$this->assertEquals( 'johnsmith', $result );
	}

	/**
	 * Test generate_username_from_name with empty input.
	 */
	public function test_username_from_name_empty() {
		$result = ec_generate_username_from_name( '' );

		$this->assertEquals( 'user', $result );
	}
}
