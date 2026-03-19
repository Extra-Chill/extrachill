<?php
/**
 * Unit tests for token helper functions.
 */

class Test_Tokens extends WP_UnitTestCase {

	public function test_base64url_encode_basic(): void {
		$input    = 'hello world';
		$encoded  = extrachill_users_base64url_encode( $input );
		$expected = rtrim( strtr( base64_encode( $input ), '+/', '-_' ), '=' );

		$this->assertSame( $expected, $encoded );
	}

	public function test_base64url_decode_basic(): void {
		$original = 'hello world';
		$encoded  = extrachill_users_base64url_encode( $original );

		$this->assertSame( $original, extrachill_users_base64url_decode( $encoded ) );
	}

	public function test_is_uuid_v4_valid(): void {
		$this->assertTrue( extrachill_users_is_uuid_v4( '550e8400-e29b-41d4-a716-446655440000' ) );
	}

	public function test_is_uuid_v4_invalid_format(): void {
		$this->assertFalse( extrachill_users_is_uuid_v4( 'not-a-uuid' ) );
	}

	public function test_generate_access_token_structure(): void {
		$result = extrachill_users_generate_access_token( 1, '550e8400-e29b-41d4-a716-446655440000' );
		$this->assertArrayHasKey( 'token', $result );
		$this->assertArrayHasKey( 'expires_at', $result );
	}

	public function test_generate_access_token_payload(): void {
		$result  = extrachill_users_generate_access_token( 42, '550e8400-e29b-41d4-a716-446655440000' );
		$parts   = explode( '.', $result['token'] );
		$payload = json_decode( extrachill_users_base64url_decode( $parts[1] ), true );

		$this->assertSame( 42, $payload['user_id'] );
		$this->assertArrayHasKey( 'iat', $payload );
		$this->assertArrayHasKey( 'exp', $payload );
	}

	public function test_hash_refresh_token_deterministic(): void {
		$token = 'my-refresh-token-123';
		$this->assertSame( extrachill_users_hash_refresh_token( $token ), extrachill_users_hash_refresh_token( $token ) );
	}
}
