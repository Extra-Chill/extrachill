<?php
/**
 * Integration tests for Auth Route handlers.
 *
 * Tests validation logic and error handling.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_Auth_Routes extends TestCase {

	/**
	 * Valid UUID v4 for testing.
	 */
	const VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';

	/**
	 * Test login handler returns error when identifier is missing.
	 */
	public function test_login_missing_identifier() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/login' );
		$request->set_param( 'identifier', '' );
		$request->set_param( 'password', 'test123' );
		$request->set_param( 'device_id', self::VALID_UUID );

		$result = extrachill_api_auth_login_handler( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test login handler returns error when password is missing.
	 */
	public function test_login_missing_password() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/login' );
		$request->set_param( 'identifier', 'testuser' );
		$request->set_param( 'password', '' );
		$request->set_param( 'device_id', self::VALID_UUID );

		$result = extrachill_api_auth_login_handler( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test login handler returns error when device_id is missing.
	 */
	public function test_login_missing_device_id() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/login' );
		$request->set_param( 'identifier', 'testuser' );
		$request->set_param( 'password', 'test123' );
		$request->set_param( 'device_id', '' );

		$result = extrachill_api_auth_login_handler( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_device_id', $result->get_error_code() );
	}

	/**
	 * Test login handler returns error when device_id is not UUID v4.
	 */
	public function test_login_invalid_device_id_format() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/login' );
		$request->set_param( 'identifier', 'testuser' );
		$request->set_param( 'password', 'test123' );
		$request->set_param( 'device_id', 'not-a-valid-uuid' );

		$result = extrachill_api_auth_login_handler( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_device_id', $result->get_error_code() );
		$this->assertStringContainsString( 'UUID v4', $result->get_error_message() );
	}

	/**
	 * Test login handler passes validation with valid inputs.
	 */
	public function test_login_valid_inputs_reach_handler() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/login' );
		$request->set_param( 'identifier', 'testuser' );
		$request->set_param( 'password', 'test123' );
		$request->set_param( 'device_id', self::VALID_UUID );

		$result = extrachill_api_auth_login_handler( $request );

		// With our mock, valid inputs reach the mock function which returns a specific error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'mock_not_implemented', $result->get_error_code() );
	}

	/**
	 * Test register handler returns error when device_id is missing.
	 */
	public function test_register_missing_device_id() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/register' );
		$request->set_param( 'email', 'test@example.com' );
		$request->set_param( 'password', 'test123' );
		$request->set_param( 'password_confirm', 'test123' );
		$request->set_param( 'device_id', '' );

		$result = extrachill_api_auth_register_handler( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_device_id', $result->get_error_code() );
	}

	/**
	 * Test register handler returns error when device_id is not UUID v4.
	 */
	public function test_register_invalid_device_id() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/register' );
		$request->set_param( 'email', 'test@example.com' );
		$request->set_param( 'password', 'test123' );
		$request->set_param( 'password_confirm', 'test123' );
		$request->set_param( 'device_id', 'invalid-uuid-format' );

		$result = extrachill_api_auth_register_handler( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_device_id', $result->get_error_code() );
	}

	/**
	 * Test register handler passes validation with valid inputs.
	 */
	public function test_register_valid_inputs_reach_handler() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/auth/register' );
		$request->set_param( 'email', 'test@example.com' );
		$request->set_param( 'password', 'test123' );
		$request->set_param( 'password_confirm', 'test123' );
		$request->set_param( 'device_id', self::VALID_UUID );

		$result = extrachill_api_auth_register_handler( $request );

		// With our mock, valid inputs reach the mock function which returns a specific error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'mock_not_implemented', $result->get_error_code() );
	}

	/**
	 * Test UUID v4 validator helper accepts valid UUID.
	 */
	public function test_uuid_validator_valid() {
		$this->assertTrue( extrachill_users_is_uuid_v4( '550e8400-e29b-41d4-a716-446655440000' ) );
		$this->assertTrue( extrachill_users_is_uuid_v4( 'f47ac10b-58cc-4372-a567-0e02b2c3d479' ) );
	}

	/**
	 * Test UUID v4 validator helper rejects invalid UUIDs.
	 */
	public function test_uuid_validator_invalid() {
		// Wrong format.
		$this->assertFalse( extrachill_users_is_uuid_v4( 'not-a-uuid' ) );

		// UUID v1 (version digit not 4).
		$this->assertFalse( extrachill_users_is_uuid_v4( '550e8400-e29b-11d4-a716-446655440000' ) );

		// Wrong variant.
		$this->assertFalse( extrachill_users_is_uuid_v4( '550e8400-e29b-41d4-c716-446655440000' ) );

		// Too short.
		$this->assertFalse( extrachill_users_is_uuid_v4( '550e8400-e29b-41d4' ) );

		// Empty.
		$this->assertFalse( extrachill_users_is_uuid_v4( '' ) );

		// Null.
		$this->assertFalse( extrachill_users_is_uuid_v4( null ) );
	}
}
