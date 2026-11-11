<?php
/**
 * Venue claim and invitation security tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Standalone token tests include the WordPress salt stub.

use ExtraChillEvents\Core\VenueInvitationToken;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-' . $scheme . '-salt';
	}
}

require_once __DIR__ . '/../../extrachill-users/inc/teams/TeamMembershipRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueInvitationToken.php';

final class VenueOnboardingTest extends TestCase {

	/** The extracted store delegates the anchor lock and rolls back adapter failures. */
	public function test_generic_repository_uses_adapter_lock_and_error_namespace(): void {
		require_once dirname( __DIR__ ) . '/inc/Core/VenueOnboardingRepository.php';
		$adapter = new class implements \ExtraChill\Users\Teams\TeamOnboardingAdapter {
			public $checks = array();
			public function team_type(): string { return 'artist'; }
			public function operations(): array { return array( 'invitations', 'claims' ); }
			public function check_team( int $id, bool $lock ) {
				$this->checks[] = array( $id, $lock );
				return new WP_Error( 'artist_anchor_unavailable', 'Unavailable' );
			}
			public function authorize_locked( int $actor, int $id, array $members ) { return true; }
			public function error_code( string $code ): string { return str_replace( 'team', 'artist', $code ); }
			public function public_record( array $record ): array { return $record; }
		};
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = new class {
			public $queries = array();
			public function query( $sql ) { $this->queries[] = $sql; return 1; }
		};
		$GLOBALS['wpdb'] = $db;
		try {
			$repository = new \ExtraChill\Users\Teams\TeamOnboardingRepository( $adapter );
			$result = $repository->list_invitations( 1, 42 );
			$this->assertSame( 'artist_anchor_unavailable', $result->get_error_code() );
			$this->assertSame( array( array( 42, true ) ), $adapter->checks );
			$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->queries );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}

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

	public function test_token_cannot_cross_team_types_with_the_same_term_id(): void {
		$tokens   = new VenueInvitationToken();
		$token    = $tokens->generate();
		$venue    = $this->invitation( array(
			'team_type'    => 'venue',
			'team_term_id' => 55,
		) );
		$promoter = array_merge( $venue, array( 'team_type' => 'promoter' ) );
		$this->assertFalse( $tokens->verify( $token, $promoter, $tokens->hash( $token, $venue ) ) );
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

	public function test_legacy_binding_hash_is_unchanged_by_users_extraction(): void {
		$tokens = new VenueInvitationToken();
		$token  = str_repeat( 'a', 64 );
		$grant  = $this->invitation();
		$expected = hash_hmac( 'sha256', $token . '|' . $grant['public_id'] . '|venue|55|7|member', wp_salt( 'auth' ) );
		$this->assertSame( $expected, $tokens->hash( $token, $grant ) );
		$this->assertSame( $expected, ( new \ExtraChill\Users\Teams\TeamInvitationToken() )->hash( $token, array_merge( $grant, array( 'team_type' => 'venue', 'team_id' => 55 ) ) ) );
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
