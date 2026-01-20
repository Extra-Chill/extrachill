<?php
/**
 * Unit tests for token helper functions.
 *
 * Tests the pure functions in inc/auth-tokens/tokens.php
 */

use PHPUnit\Framework\TestCase;

class Test_Tokens extends TestCase {

	/**
	 * Set up before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		reset_wp_mocks();
	}

	/**
	 * Test base64url encoding with basic string.
	 */
	public function test_base64url_encode_basic() {
		$input    = 'hello world';
		$encoded  = extrachill_users_base64url_encode( $input );
		$expected = rtrim( strtr( base64_encode( $input ), '+/', '-_' ), '=' );

		$this->assertEquals( $expected, $encoded );
	}

	/**
	 * Test base64url encoding produces URL-safe output (no +, /, =).
	 */
	public function test_base64url_encode_special_chars() {
		// Input that would produce +, /, or = in standard base64.
		$input   = '>>>???'; // Produces characters that need URL-safe encoding.
		$encoded = extrachill_users_base64url_encode( $input );

		$this->assertStringNotContainsString( '+', $encoded );
		$this->assertStringNotContainsString( '/', $encoded );
		$this->assertStringNotContainsString( '=', $encoded );
	}

	/**
	 * Test base64url decoding basic string.
	 */
	public function test_base64url_decode_basic() {
		$original = 'hello world';
		$encoded  = extrachill_users_base64url_encode( $original );
		$decoded  = extrachill_users_base64url_decode( $encoded );

		$this->assertEquals( $original, $decoded );
	}

	/**
	 * Test base64url roundtrip - encode then decode returns original.
	 */
	public function test_base64url_roundtrip() {
		$test_strings = array(
			'simple',
			'with spaces',
			'special!@#$%^&*()',
			'{"json":"data","array":[1,2,3]}',
			str_repeat( 'a', 1000 ), // Long string.
		);

		foreach ( $test_strings as $original ) {
			$encoded = extrachill_users_base64url_encode( $original );
			$decoded = extrachill_users_base64url_decode( $encoded );

			$this->assertEquals( $original, $decoded, "Failed roundtrip for: $original" );
		}
	}

	/**
	 * Test valid UUIDv4 returns true.
	 */
	public function test_is_uuid_v4_valid() {
		$valid_uuids = array(
			'550e8400-e29b-41d4-a716-446655440000',
			'6ba7b810-9dad-41d4-80b4-00c04fd430c8',
			'f47ac10b-58cc-4372-a567-0e02b2c3d479',
		);

		foreach ( $valid_uuids as $uuid ) {
			$this->assertTrue(
				extrachill_users_is_uuid_v4( $uuid ),
				"Should be valid UUIDv4: $uuid"
			);
		}
	}

	/**
	 * Test invalid UUID format returns false.
	 */
	public function test_is_uuid_v4_invalid_format() {
		$invalid_uuids = array(
			'not-a-uuid',
			'550e8400e29b41d4a716446655440000', // Missing dashes.
			'550e8400-e29b-41d4-a716', // Too short.
			'550e8400-e29b-41d4-a716-446655440000-extra', // Too long.
			'',
			'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx',
		);

		foreach ( $invalid_uuids as $uuid ) {
			$this->assertFalse(
				extrachill_users_is_uuid_v4( $uuid ),
				"Should be invalid UUIDv4: $uuid"
			);
		}
	}

	/**
	 * Test wrong UUID version returns false.
	 */
	public function test_is_uuid_v4_wrong_version() {
		// UUIDv1 (time-based) - version digit is 1, not 4.
		$uuid_v1 = '550e8400-e29b-11d4-a716-446655440000';
		$this->assertFalse( extrachill_users_is_uuid_v4( $uuid_v1 ) );

		// UUIDv3 (MD5 hash) - version digit is 3, not 4.
		$uuid_v3 = '550e8400-e29b-31d4-a716-446655440000';
		$this->assertFalse( extrachill_users_is_uuid_v4( $uuid_v3 ) );

		// UUIDv5 (SHA-1 hash) - version digit is 5, not 4.
		$uuid_v5 = '550e8400-e29b-51d4-a716-446655440000';
		$this->assertFalse( extrachill_users_is_uuid_v4( $uuid_v5 ) );
	}

	/**
	 * Test UUIDv4 validation is case insensitive.
	 */
	public function test_is_uuid_v4_case_insensitive() {
		$lowercase = '550e8400-e29b-41d4-a716-446655440000';
		$uppercase = '550E8400-E29B-41D4-A716-446655440000';
		$mixed     = '550e8400-E29B-41d4-A716-446655440000';

		$this->assertTrue( extrachill_users_is_uuid_v4( $lowercase ) );
		$this->assertTrue( extrachill_users_is_uuid_v4( $uppercase ) );
		$this->assertTrue( extrachill_users_is_uuid_v4( $mixed ) );
	}

	/**
	 * Test generate_access_token returns array with expected keys.
	 */
	public function test_generate_access_token_structure() {
		$result = extrachill_users_generate_access_token( 1, '550e8400-e29b-41d4-a716-446655440000' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'token', $result );
		$this->assertArrayHasKey( 'expires_at', $result );
	}

	/**
	 * Test access token has JWT format (3 dot-separated parts).
	 */
	public function test_generate_access_token_jwt_format() {
		$result = extrachill_users_generate_access_token( 1, '550e8400-e29b-41d4-a716-446655440000' );
		$parts  = explode( '.', $result['token'] );

		$this->assertCount( 3, $parts, 'JWT should have 3 parts: header.payload.signature' );
		$this->assertNotEmpty( $parts[0], 'Header should not be empty' );
		$this->assertNotEmpty( $parts[1], 'Payload should not be empty' );
		$this->assertNotEmpty( $parts[2], 'Signature should not be empty' );
	}

	/**
	 * Test access token header contains correct algorithm and type.
	 */
	public function test_generate_access_token_header() {
		$result = extrachill_users_generate_access_token( 1, '550e8400-e29b-41d4-a716-446655440000' );
		$parts  = explode( '.', $result['token'] );
		$header = json_decode( extrachill_users_base64url_decode( $parts[0] ), true );

		$this->assertEquals( 'HS256', $header['alg'] );
		$this->assertEquals( 'JWT', $header['typ'] );
	}

	/**
	 * Test access token payload contains required claims.
	 */
	public function test_generate_access_token_payload() {
		$user_id   = 42;
		$device_id = '550e8400-e29b-41d4-a716-446655440000';
		$result    = extrachill_users_generate_access_token( $user_id, $device_id );
		$parts     = explode( '.', $result['token'] );
		$payload   = json_decode( extrachill_users_base64url_decode( $parts[1] ), true );

		$this->assertEquals( $user_id, $payload['user_id'] );
		$this->assertEquals( $device_id, $payload['device_id'] );
		$this->assertArrayHasKey( 'iat', $payload );
		$this->assertArrayHasKey( 'exp', $payload );
		$this->assertIsInt( $payload['iat'] );
		$this->assertIsInt( $payload['exp'] );
	}

	/**
	 * Test access token expires in 15 minutes.
	 */
	public function test_generate_access_token_expiration() {
		$before = time();
		$result = extrachill_users_generate_access_token( 1, '550e8400-e29b-41d4-a716-446655440000' );
		$after  = time();

		// Token TTL is 15 minutes (EXTRACHILL_USERS_ACCESS_TOKEN_TTL).
		$expected_ttl = 15 * MINUTE_IN_SECONDS;

		// expires_at should be ~15 minutes from now.
		$this->assertGreaterThanOrEqual( $before + $expected_ttl, $result['expires_at'] );
		$this->assertLessThanOrEqual( $after + $expected_ttl, $result['expires_at'] );

		// Verify via payload.
		$parts   = explode( '.', $result['token'] );
		$payload = json_decode( extrachill_users_base64url_decode( $parts[1] ), true );

		$this->assertEquals( $result['expires_at'], $payload['exp'] );
		$this->assertEquals( $expected_ttl, $payload['exp'] - $payload['iat'] );
	}

	/**
	 * Test hash_refresh_token is deterministic (same input = same output).
	 */
	public function test_hash_refresh_token_deterministic() {
		$token = 'my-refresh-token-123';

		$hash1 = extrachill_users_hash_refresh_token( $token );
		$hash2 = extrachill_users_hash_refresh_token( $token );

		$this->assertEquals( $hash1, $hash2 );
	}

	/**
	 * Test hash_refresh_token produces different hashes for different inputs.
	 */
	public function test_hash_refresh_token_different_inputs() {
		$token1 = 'refresh-token-one';
		$token2 = 'refresh-token-two';

		$hash1 = extrachill_users_hash_refresh_token( $token1 );
		$hash2 = extrachill_users_hash_refresh_token( $token2 );

		$this->assertNotEquals( $hash1, $hash2 );
	}

	/**
	 * Test hash_refresh_token returns non-empty string.
	 */
	public function test_hash_refresh_token_returns_hash() {
		$token = 'test-token';
		$hash  = extrachill_users_hash_refresh_token( $token );

		$this->assertIsString( $hash );
		$this->assertNotEmpty( $hash );
		$this->assertEquals( 64, strlen( $hash ), 'SHA256 hash should be 64 hex characters' );
	}
}
