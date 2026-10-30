<?php
/**
 * Venue claim and invitation security tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\VenueInvitationToken;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-' . $scheme . '-salt';
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/VenueInvitationToken.php';

final class VenueOnboardingTest extends TestCase {

	private function invitation( array $overrides = array() ): array {
		return array_merge(
			array(
				'public_id'     => '18b61ee7-9965-43b3-b6af-df4b6b10f24f',
				'venue_term_id' => 55,
				'user_id'       => 7,
				'is_owner'      => false,
			),
			$overrides
		);
	}

	public function test_tokens_are_strong_hashed_and_exactly_bound(): void {
		$tokens     = new VenueInvitationToken();
		$token      = $tokens->generate();
		$invitation = $this->invitation();
		$hash       = $tokens->hash( $token, $invitation );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
		$this->assertNotSame( $token, $hash );
		$this->assertTrue( $tokens->verify( $token, $invitation, $hash ) );
		$this->assertFalse( $tokens->verify( $token, $this->invitation( array( 'venue_term_id' => 56 ) ), $hash ) );
		$this->assertFalse( $tokens->verify( $token, $this->invitation( array( 'user_id' => 8 ) ), $hash ) );
		$this->assertFalse( $tokens->verify( $token, $this->invitation( array( 'is_owner' => true ) ), $hash ) );
	}

	public function test_rotation_invalidates_the_previous_token(): void {
		$tokens     = new VenueInvitationToken();
		$invitation = $this->invitation();
		$old_token  = $tokens->generate();
		$new_token  = $tokens->generate();
		$new_hash   = $tokens->hash( $new_token, $invitation );

		$this->assertNotSame( $old_token, $new_token );
		$this->assertFalse( $tokens->verify( $old_token, $invitation, $new_hash ) );
		$this->assertTrue( $tokens->verify( $new_token, $invitation, $new_hash ) );
	}

	public function test_token_hash_does_not_expose_binding_values(): void {
		$tokens     = new VenueInvitationToken();
		$token      = $tokens->generate();
		$invitation = $this->invitation();
		$hash       = $tokens->hash( $token, $invitation );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertNotSame( hash( 'sha256', $token ), $hash );
		$this->assertTrue( $tokens->verify( $token, $invitation, $hash ) );
	}
}
