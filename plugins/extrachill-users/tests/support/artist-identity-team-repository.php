<?php
/**
 * Team membership double for the standalone artist identity consumer smoke.
 *
 * @package ExtraChill\Users
 */

namespace ExtraChill\Users\Teams;

/**
 * Team membership double backed by $GLOBALS['ec_test_rosters'].
 */
class TeamMembershipRepository { // phpcs:ignore Generic.Classes.DuplicateClassName.Found -- Test double for the standalone smoke.
	/**
	 * Return the active artist IDs seeded for the user.
	 *
	 * @param string $team_type Team type.
	 * @param array  $criteria  Criteria with user_id.
	 * @return int[]
	 */
	public function term_ids( string $team_type, array $criteria ): array {
		$user_id = (int) ( $criteria['user_id'] ?? 0 );
		return 'artist' === $team_type ? ( $GLOBALS['ec_test_rosters'][ $user_id ] ?? array() ) : array();
	}
}
