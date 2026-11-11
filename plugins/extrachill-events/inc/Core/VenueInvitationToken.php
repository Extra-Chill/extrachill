<?php
/**
 * Legacy invitation binding compatibility.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__, 3 ) . '/extrachill-users/inc/teams/TeamInvitationToken.php';

/** Preserves existing token hashes while users owns the cryptographic primitive. */
class VenueInvitationToken extends \ExtraChill\Users\Teams\TeamInvitationToken {

	/** Normalize the legacy binding before hashing it. */
	public function hash( string $token, array $invitation ): string {
		$invitation['team_type']    = $invitation['team_type'] ?? 'venue';
		$invitation['team_term_id'] = $invitation['team_term_id'] ?? $invitation['venue_term_id'] ?? 0;
		return parent::hash( $token, $invitation );
	}
}
