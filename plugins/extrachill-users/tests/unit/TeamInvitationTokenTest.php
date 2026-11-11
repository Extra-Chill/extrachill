<?php
/** Standalone tests for the shared invitation credential primitive. */

use ExtraChill\Users\Teams\TeamInvitationToken;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-' . $scheme . '-salt';
	}
}
require_once dirname( __DIR__, 2 ) . '/inc/teams/TeamInvitationToken.php';

final class TeamInvitationTokenTest extends TestCase {
	public function test_each_grant_field_is_bound_and_rotation_invalidates_credentials(): void {
		$tokens = new TeamInvitationToken();
		$grant = array( 'public_id' => 'opaque-id', 'team_type' => 'fake', 'team_id' => 42, 'user_id' => 7, 'is_owner' => false );
		$token = $tokens->generate();
		$hash = $tokens->hash( $token, $grant );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$this->assertTrue( $tokens->verify( $token, $grant, $hash ) );
		foreach ( array( 'public_id' => 'other', 'team_type' => 'other', 'team_id' => 43, 'user_id' => 8, 'is_owner' => true ) as $key => $value ) {
			$this->assertFalse( $tokens->verify( $token, array_merge( $grant, array( $key => $value ) ), $hash ), $key );
		}
		$this->assertFalse( $tokens->verify( $tokens->generate(), $grant, $hash ) );
		$this->assertFalse( $tokens->verify( '', $grant, $hash ) );
		$this->assertFalse( $tokens->verify( $token, $grant, 'invalid-hash' ) );
		$this->assertSame( $hash, $tokens->hash( $token, array_merge( $grant, array( 'team_term_id' => 42 ) ) ) );
	}
}
