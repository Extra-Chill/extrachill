<?php
/**
 * Venue invitation token generation and binding.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Generates opaque tokens and binds their hashes to one invitation grant. */
class VenueInvitationToken {

	/** Generate 256 bits of cryptographically secure token material. */
	public function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Hash a token together with its immutable venue, user, and authority grant.
	 *
	 * @param string $token      Raw invitation token.
	 * @param array  $invitation Immutable invitation binding.
	 */
	public function hash( string $token, array $invitation ): string {
		$binding = implode(
			'|',
			array(
				(string) ( $invitation['public_id'] ?? '' ),
				(string) (int) ( $invitation['venue_term_id'] ?? 0 ),
				(string) (int) ( $invitation['user_id'] ?? 0 ),
				! empty( $invitation['is_owner'] ) ? 'owner' : 'member',
			)
		);

		return hash_hmac( 'sha256', $token . '|' . $binding, wp_salt( 'auth' ) );
	}

	/**
	 * Verify an opaque token without exposing timing information.
	 *
	 * @param string $token         Raw invitation token.
	 * @param array  $invitation    Immutable invitation binding.
	 * @param string $expected_hash Stored invitation hash.
	 */
	public function verify( string $token, array $invitation, string $expected_hash ): bool {
		if ( '' === $token || ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			return false;
		}

		return hash_equals( $expected_hash, $this->hash( $token, $invitation ) );
	}
}
