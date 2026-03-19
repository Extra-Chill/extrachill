<?php
/**
 * Unit tests for username generation helpers.
 */

class Test_Username_Generation extends WP_UnitTestCase {

	public function test_username_from_email_basic(): void {
		$this->assertSame( 'user', ec_generate_username_from_email( 'user@example.com' ) );
	}

	public function test_username_from_email_with_dots(): void {
		$this->assertSame( 'firstlast', ec_generate_username_from_email( 'first.last@example.com' ) );
	}

	public function test_username_from_email_strips_special_chars(): void {
		$this->assertSame( 'usertag', ec_generate_username_from_email( 'user+tag@example.com' ) );
	}

	public function test_username_from_email_uniqueness(): void {
		self::factory()->user->create( array( 'user_login' => 'john', 'user_email' => 'john@first.com' ) );
		self::factory()->user->create( array( 'user_login' => 'john1', 'user_email' => 'john@second.com' ) );

		$this->assertSame( 'john2', ec_generate_username_from_email( 'john@third.com' ) );
	}

	public function test_username_from_email_invalid_falls_back(): void {
		$this->assertSame( 'user', ec_generate_username_from_email( 'not-an-email' ) );
	}

	public function test_sanitize_username_base_lowercase(): void {
		$this->assertSame( 'johndoe', ec_sanitize_username_base( 'JohnDoe' ) );
	}

	public function test_sanitize_username_base_all_special(): void {
		$this->assertSame( 'user', ec_sanitize_username_base( '@#$%^&' ) );
	}

	public function test_get_unique_username_with_collision(): void {
		self::factory()->user->create( array( 'user_login' => 'testuser', 'user_email' => 'test@example.com' ) );
		$this->assertSame( 'testuser1', ec_get_unique_username( 'testuser' ) );
	}

	public function test_username_from_name_basic(): void {
		$this->assertSame( 'johnsmith', ec_generate_username_from_name( 'John Smith' ) );
	}
}
