<?php
/**
 * Integration tests for bearer token authentication.
 *
 * Tests the functions in inc/auth-tokens/bearer-auth.php
 */

use PHPUnit\Framework\TestCase;

class Test_Bearer_Auth extends TestCase {

	/**
	 * Test user ID.
	 */
	private $test_user_id = 42;

	/**
	 * Test device ID.
	 */
	private $test_device_id = '550e8400-e29b-41d4-a716-446655440000';

	/**
	 * Set up before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		reset_wp_mocks();

		// Clear server vars.
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		// Clear test headers.
		$GLOBALS['__test_headers'] = array();

		// Create test user.
		$GLOBALS['__test_users'][ $this->test_user_id ] = array(
			'ID'         => $this->test_user_id,
			'user_login' => 'testuser',
			'user_email' => 'test@example.com',
		);
	}

	/**
	 * Test bearer token extraction from HTTP_AUTHORIZATION header.
	 */
	public function test_get_bearer_token_from_http_authorization() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer my-test-token';

		$token = extrachill_users_get_bearer_token();

		$this->assertEquals( 'my-test-token', $token );
	}

	/**
	 * Test bearer token extraction from REDIRECT_HTTP_AUTHORIZATION header.
	 */
	public function test_get_bearer_token_from_redirect_http_authorization() {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect-token';

		$token = extrachill_users_get_bearer_token();

		$this->assertEquals( 'redirect-token', $token );
	}

	/**
	 * Test bearer token extraction from getallheaders().
	 */
	public function test_get_bearer_token_from_getallheaders() {
		set_test_headers( array( 'Authorization' => 'Bearer header-token' ) );

		$token = extrachill_users_get_bearer_token();

		$this->assertEquals( 'header-token', $token );
	}

	/**
	 * Test bearer token extraction with lowercase authorization header.
	 */
	public function test_get_bearer_token_lowercase_header() {
		set_test_headers( array( 'authorization' => 'Bearer lowercase-token' ) );

		$token = extrachill_users_get_bearer_token();

		$this->assertEquals( 'lowercase-token', $token );
	}

	/**
	 * Test bearer token returns null when no header present.
	 */
	public function test_get_bearer_token_missing_header() {
		$token = extrachill_users_get_bearer_token();

		$this->assertNull( $token );
	}

	/**
	 * Test bearer token returns null for non-Bearer auth.
	 */
	public function test_get_bearer_token_wrong_scheme() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

		$token = extrachill_users_get_bearer_token();

		$this->assertNull( $token );
	}

	/**
	 * Test valid access token validation returns payload.
	 */
	public function test_validate_access_token_valid() {
		$token_data = extrachill_users_generate_access_token( $this->test_user_id, $this->test_device_id );

		$payload = extrachill_users_validate_access_token( $token_data['token'] );

		$this->assertIsArray( $payload );
		$this->assertEquals( $this->test_user_id, $payload['user_id'] );
		$this->assertEquals( $this->test_device_id, $payload['device_id'] );
	}

	/**
	 * Test expired token returns null.
	 */
	public function test_validate_access_token_expired() {
		// Generate a token, then manually create an expired version.
		$expired_payload = array(
			'user_id'   => $this->test_user_id,
			'device_id' => $this->test_device_id,
			'iat'       => time() - 3600,
			'exp'       => time() - 1, // Expired 1 second ago.
		);

		$header     = array( 'alg' => 'HS256', 'typ' => 'JWT' );
		$header_b64 = extrachill_users_base64url_encode( wp_json_encode( $header ) );
		$payload_b64 = extrachill_users_base64url_encode( wp_json_encode( $expired_payload ) );
		$signature   = hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", wp_salt( 'auth' ), true );
		$signature_b64 = extrachill_users_base64url_encode( $signature );

		$expired_token = "{$header_b64}.{$payload_b64}.{$signature_b64}";

		$payload = extrachill_users_validate_access_token( $expired_token );

		$this->assertNull( $payload );
	}

	/**
	 * Test tampered token signature fails validation.
	 */
	public function test_validate_access_token_tampered() {
		$token_data = extrachill_users_generate_access_token( $this->test_user_id, $this->test_device_id );

		// Tamper with the signature.
		$parts     = explode( '.', $token_data['token'] );
		$parts[2]  = 'tampered_signature_that_should_fail';
		$tampered_token = implode( '.', $parts );

		$payload = extrachill_users_validate_access_token( $tampered_token );

		$this->assertNull( $payload );
	}

	/**
	 * Test invalid token format (not 3 parts) fails.
	 */
	public function test_validate_access_token_invalid_format() {
		$invalid_tokens = array(
			'not-a-jwt',
			'only.two.parts.here.extra',
			'',
			'single',
		);

		foreach ( $invalid_tokens as $token ) {
			$payload = extrachill_users_validate_access_token( $token );
			$this->assertNull( $payload, "Token '$token' should fail validation" );
		}
	}

	/**
	 * Test token for non-existent user fails.
	 */
	public function test_validate_access_token_user_not_found() {
		$non_existent_user_id = 99999;
		$token_data = extrachill_users_generate_access_token( $non_existent_user_id, $this->test_device_id );

		$payload = extrachill_users_validate_access_token( $token_data['token'] );

		$this->assertNull( $payload );
	}

	/**
	 * Test authenticate_bearer_token returns user ID for valid token.
	 */
	public function test_authenticate_bearer_token_valid() {
		$token_data = extrachill_users_generate_access_token( $this->test_user_id, $this->test_device_id );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token_data['token'];

		$result = extrachill_users_authenticate_bearer_token( false );

		$this->assertEquals( $this->test_user_id, $result );
	}

	/**
	 * Test authenticate_bearer_token passes through if user already authenticated.
	 */
	public function test_authenticate_bearer_token_already_authenticated() {
		$existing_user_id = 123;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-token';

		$result = extrachill_users_authenticate_bearer_token( $existing_user_id );

		$this->assertEquals( $existing_user_id, $result );
	}

	/**
	 * Test authenticate_bearer_token returns false when no token.
	 */
	public function test_authenticate_bearer_token_no_token() {
		$result = extrachill_users_authenticate_bearer_token( false );

		$this->assertFalse( $result );
	}

	/**
	 * Test authenticate_bearer_token returns false for invalid token.
	 */
	public function test_authenticate_bearer_token_invalid_token() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid.token.here';

		$result = extrachill_users_authenticate_bearer_token( false );

		$this->assertFalse( $result );
	}
}
