<?php
/**
 * Team invitation token generation and binding.
 *
 * @package ExtraChill\Users\Teams
 */

namespace ExtraChill\Users\Teams;

defined( 'ABSPATH' ) || exit;

/** Generates opaque tokens and binds their hashes to one invitation grant. */
class TeamInvitationToken {

	/** Generate 256 bits of cryptographically secure token material. */
	public function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/** Hash a token together with its immutable team, user, and authority grant. */
	public function hash( string $token, array $invitation ): string {
		$binding = implode(
			'|',
			array(
				(string) ( $invitation['public_id'] ?? '' ),
				(string) ( $invitation['team_type'] ?? '' ),
				(string) (int) ( $invitation['team_term_id'] ?? $invitation['team_id'] ?? 0 ),
				(string) (int) ( $invitation['user_id'] ?? 0 ),
				! empty( $invitation['is_owner'] ) ? 'owner' : 'member',
			)
		);

		return hash_hmac( 'sha256', $token . '|' . $binding, wp_salt( 'auth' ) );
	}

	/** Verify an opaque token without exposing timing information. */
	public function verify( string $token, array $invitation, string $expected_hash ): bool {
		if ( '' === $token || ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			return false;
		}

		return hash_equals( $expected_hash, $this->hash( $token, $invitation ) );
	}
}
