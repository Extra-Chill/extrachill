<?php
/**
 * Venue claim and invitation security tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Standalone token tests include the WordPress salt stub.

use ExtraChill\Users\Teams\TeamInvitationToken;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-' . $scheme . '-salt';
	}
}

require_once __DIR__ . '/../../extrachill-users/inc/teams/TeamMembershipRepository.php';

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
			public function authorize( int $actor, int $id ) { return true; }
			public function authorize_locked( int $actor, int $id, array $members ) { return true; }
			public function accepted_url( int $team_id, bool $accepted ): string { return ""; }
			public function registration_source(): string { return 'artist_invitation'; }
			public function invitation_email( array $invitation, string $token ) { return array(); }
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

	/** All onboarding types preserve invited rows without treating them as active. */
	public function test_invited_membership_rows_preserve_typed_identifiers(): void {
		$repository = new \ExtraChill\Users\Teams\TeamMembershipRepository();
		foreach ( array( 'artist' => 'artist_id', 'venue' => 'venue_term_id', 'promoter' => 'promoter_term_id' ) as $type => $key ) {
			$row = $repository->new_row( $type, 42, 7, false, 1, 'invited' );
			$row['id'] = 10;
			$hydrated = $repository->hydrate( $type, $row );
			$this->assertIsArray( $hydrated );
			$this->assertSame( 42, $hydrated[ $key ] );
			$this->assertSame( 'invited', $hydrated['status'] );
			$this->assertNotSame( 'active', $hydrated['status'] );
		}
	}

	/** The typed artist key becomes the canonical storage key on insert. */
	public function test_artist_invited_row_is_inserted_with_canonical_team_id(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = new class {
			public $base_prefix = 'wp_';
			public $stored;
			public function insert( $table, $row ) {
				$this->stored = $row;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $db;
		try {
			$repository = new \ExtraChill\Users\Teams\TeamMembershipRepository();
			$row = $repository->new_row( 'artist', 42, 7, false, 1, 'invited' );
			$this->assertSame( 1, $repository->insert( 'artist', $row ) );
			$this->assertSame( 42, $db->stored['team_id'] );
			$this->assertSame( 'artist', $db->stored['team_type'] );
			$this->assertSame( 'invited', $db->stored['status'] );
			$this->assertArrayNotHasKey( 'artist_id', $db->stored );
			$this->assertArrayNotHasKey( 'artist_term_id', $db->stored );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}

	public function test_artist_adapter_authority_requires_active_membership_not_ownership(): void {
		require_once __DIR__ . '/../../extrachill-artist-platform/inc/artist-profiles/roster/ArtistOnboardingAdapter.php';
		$adapter = new \ExtraChillArtistPlatform\ArtistOnboardingAdapter();
		$this->assertSame( array( 'invitations' ), $adapter->operations() );
		foreach ( array( 'invited', 'revoked' ) as $status ) {
			$this->assertWPErrorDenied( $adapter->authorize_locked( 7, 42, array( array( 'user_id' => 7, 'status' => $status, 'is_owner' => true ) ) ) );
		}
		$this->assertTrue( $adapter->authorize_locked( 7, 42, array( array( 'user_id' => 7, 'status' => 'active', 'is_owner' => false ) ) ) );
		$this->assertWPErrorDenied( $adapter->authorize_locked( 8, 42, array( array( 'user_id' => 7, 'status' => 'active' ) ) ) );
		$this->assertSame( 'artist_invitation', $adapter->registration_source() );
		$this->assertSame( 'invalid_artist_invitation', $adapter->error_code( 'invalid_team_invitation' ) );
	}

	private function assertWPErrorDenied( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	public function test_generic_accept_route_dispatches_failure_to_selected_adapter(): void {
		require_once __DIR__ . '/../../extrachill-users/inc/teams/accept-invitation.php';
		$adapter = new class extends \ExtraChillEvents\Core\VenueOnboardingAdapter {
			public function accepted_url( int $team_id, bool $accepted ): string {
				return 'selected:' . $team_id . ':' . ( $accepted ? 'accepted' : 'failed' );
			}
		};
		$previous = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new VenueMembershipWpdb();
		$filter = static function ( $adapters ) use ( $adapter ) { $adapters['venue'] = $adapter; return $adapters; };
		add_filter( 'extrachill_users_team_onboarding_adapters', $filter );
		try {
			$this->assertSame( 'selected:42:failed', ec_users_team_invitation_target( array( 'team_type' => 'venue', 'team_id' => 42 ), 7 ) );
			$this->assertInstanceOf( WP_Error::class, ec_users_team_invitation_target( array( 'team_type' => 'unknown' ), 7 ) );
		} finally {
			remove_filter( 'extrachill_users_team_onboarding_adapters', $filter );
			$GLOBALS['wpdb'] = $previous;
		}
	}

	private function invitation( array $overrides = array() ): array {
		return array_merge(
			array(
				'public_id'     => '18b61ee7-9965-43b3-b6af-df4b6b10f24f',
				'team_type'     => 'venue',
				'team_id'       => 55,
				'user_id'       => 7,
				'is_owner'      => false,
			),
			$overrides
		);
	}

	public function test_tokens_are_strong_hashed_and_exactly_bound(): void {
		$tokens     = new TeamInvitationToken();
		$token      = $tokens->generate();
		$invitation = $this->invitation();
		$hash       = $tokens->hash( $token, $invitation );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
		$this->assertNotSame( $token, $hash );
		$this->assertTrue( $tokens->verify( $token, $invitation, $hash ) );
		$this->assertFalse( $tokens->verify( $token, $this->invitation( array( 'team_id' => 56 ) ), $hash ) );
		$this->assertFalse( $tokens->verify( $token, $this->invitation( array( 'user_id' => 8 ) ), $hash ) );
		$this->assertFalse( $tokens->verify( $token, $this->invitation( array( 'is_owner' => true ) ), $hash ) );
	}

	public function test_token_cannot_cross_team_types_with_the_same_term_id(): void {
		$tokens   = new TeamInvitationToken();
		$token    = $tokens->generate();
		$venue    = $this->invitation();
		$promoter = array_merge( $venue, array( 'team_type' => 'promoter' ) );
		$this->assertFalse( $tokens->verify( $token, $promoter, $tokens->hash( $token, $venue ) ) );
	}

	public function test_rotation_invalidates_the_previous_token(): void {
		$tokens     = new TeamInvitationToken();
		$invitation = $this->invitation();
		$old_token  = $tokens->generate();
		$new_token  = $tokens->generate();
		$new_hash   = $tokens->hash( $new_token, $invitation );

		$this->assertNotSame( $old_token, $new_token );
		$this->assertFalse( $tokens->verify( $old_token, $invitation, $new_hash ) );
		$this->assertTrue( $tokens->verify( $new_token, $invitation, $new_hash ) );
	}

	public function test_legacy_binding_hash_is_unchanged_by_users_extraction(): void {
		$tokens = new TeamInvitationToken();
		$token  = str_repeat( 'a', 64 );
		$grant  = $this->invitation();
		$expected = hash_hmac( 'sha256', $token . '|' . $grant['public_id'] . '|venue|55|7|member', wp_salt( 'auth' ) );
		$this->assertSame( $expected, $tokens->hash( $token, $grant ) );
	}

	public function test_token_hash_does_not_expose_binding_values(): void {
		$tokens     = new TeamInvitationToken();
		$token      = $tokens->generate();
		$invitation = $this->invitation();
		$hash       = $tokens->hash( $token, $invitation );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertNotSame( hash( 'sha256', $token ), $hash );
		$this->assertTrue( $tokens->verify( $token, $invitation, $hash ) );
	}
}
