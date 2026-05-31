<?php
/**
 * Unit tests for the extrachill-users token helpers.
 *
 * As of the eu#76 auth-stack consolidation, the token PRIMITIVES (signed-JWT
 * access tokens, base64url codecs, refresh-token hashing) were deleted from
 * extrachill-users and are now owned + tested by wp-native-auth. The only
 * helper that remains here is the UUID v4 validator.
 */

class Test_Tokens extends WP_UnitTestCase {

	public function test_is_uuid_v4_valid(): void {
		$this->assertTrue( extrachill_users_is_uuid_v4( '550e8400-e29b-41d4-a716-446655440000' ) );
	}

	public function test_is_uuid_v4_invalid_format(): void {
		$this->assertFalse( extrachill_users_is_uuid_v4( 'not-a-uuid' ) );
	}
}
