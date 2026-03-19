<?php
/**
 * Integration tests for bearer token authentication.
 */

class Test_Bearer_Auth extends WP_UnitTestCase {

	private int $test_user_id;

	private string $test_device_id = '550e8400-e29b-41d4-a716-446655440000';

	protected function setUp(): void {
		parent::setUp();

		$this->test_user_id = self::factory()->user->create(
			array(
				'user_login' => 'testuser',
				'user_email' => 'test@example.com',
			)
		);

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		parent::tearDown();
	}

	public function test_get_bearer_token_from_http_authorization(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer my-test-token';
		$this->assertSame( 'my-test-token', extrachill_users_get_bearer_token() );
	}

	public function test_get_bearer_token_from_redirect_http_authorization(): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect-token';
		$this->assertSame( 'redirect-token', extrachill_users_get_bearer_token() );
	}

	public function test_get_bearer_token_missing_header(): void {
		$this->assertNull( extrachill_users_get_bearer_token() );
	}

	public function test_get_bearer_token_wrong_scheme(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
		$this->assertNull( extrachill_users_get_bearer_token() );
	}

	public function test_validate_access_token_valid(): void {
		$token_data = extrachill_users_generate_access_token( $this->test_user_id, $this->test_device_id );
		$payload    = extrachill_users_validate_access_token( $token_data['token'] );

		$this->assertIsArray( $payload );
		$this->assertSame( $this->test_user_id, $payload['user_id'] );
		$this->assertSame( $this->test_device_id, $payload['device_id'] );
	}

	public function test_validate_access_token_expired(): void {
		$expired_payload = array(
			'user_id'   => $this->test_user_id,
			'device_id' => $this->test_device_id,
			'iat'       => time() - 3600,
			'exp'       => time() - 1,
		);

		$header        = array( 'alg' => 'HS256', 'typ' => 'JWT' );
		$header_b64    = extrachill_users_base64url_encode( wp_json_encode( $header ) );
		$payload_b64   = extrachill_users_base64url_encode( wp_json_encode( $expired_payload ) );
		$signature     = hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", wp_salt( 'auth' ), true );
		$signature_b64 = extrachill_users_base64url_encode( $signature );
		$expired_token = "{$header_b64}.{$payload_b64}.{$signature_b64}";

		$this->assertNull( extrachill_users_validate_access_token( $expired_token ) );
	}

	public function test_validate_access_token_user_not_found(): void {
		$token_data = extrachill_users_generate_access_token( 999999, $this->test_device_id );
		$this->assertNull( extrachill_users_validate_access_token( $token_data['token'] ) );
	}

	public function test_authenticate_bearer_token_valid(): void {
		$token_data                    = extrachill_users_generate_access_token( $this->test_user_id, $this->test_device_id );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token_data['token'];

		$this->assertSame( $this->test_user_id, extrachill_users_authenticate_bearer_token( false ) );
	}

	public function test_authenticate_bearer_token_already_authenticated(): void {
		$this->assertSame( 123, extrachill_users_authenticate_bearer_token( 123 ) );
	}

	public function test_authenticate_bearer_token_invalid_token(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid.token.here';
		$this->assertFalse( extrachill_users_authenticate_bearer_token( false ) );
	}
}
