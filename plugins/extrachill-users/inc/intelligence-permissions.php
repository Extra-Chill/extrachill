<?php
/**
 * Intelligence permission bridge.
 *
 * Intelligence owns the generic `intelligence_read` capability contract, while
 * Extra Chill Users owns the `extra_chill_team` role and the policy that team
 * members may read the private Studio corpus. Keep that foreign capability off
 * the persisted role and resolve the grant dynamically at check time.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Intelligence-owned read capability granted by this policy.
 */
const EC_USERS_INTELLIGENCE_READ_CAP = 'intelligence_read';

/**
 * Grant Intelligence reads to Extra Chill team members on Studio only.
 *
 * This bridge only broadens the requested primitive capability. Existing
 * grants and unrelated capabilities are returned unchanged.
 *
 * @param array<string,bool> $allcaps All capabilities currently resolved for the user.
 * @param string[]           $caps    Required primitive capabilities for the check.
 * @param array              $args    Capability check arguments; requested cap is at index 0.
 * @param mixed              $user    The user object being checked.
 * @return array<string,bool> Filtered capability map.
 */
function ec_users_grant_studio_intelligence_read( array $allcaps, array $caps, array $args, $user ): array {
	unset( $caps );

	if ( ! empty( $allcaps[ EC_USERS_INTELLIGENCE_READ_CAP ] ) ) {
		return $allcaps;
	}

	if ( empty( $args[0] ) || EC_USERS_INTELLIGENCE_READ_CAP !== $args[0] ) {
		return $allcaps;
	}

	if ( ! is_object( $user ) || ! property_exists( $user, 'roles' ) || ! is_array( $user->roles ) ) {
		return $allcaps;
	}

	$studio_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'studio' ) : 0;
	if ( $studio_blog_id <= 0 || get_current_blog_id() !== $studio_blog_id ) {
		return $allcaps;
	}

	$team_role = defined( 'EC_USERS_TEAM_ROLE' ) ? EC_USERS_TEAM_ROLE : 'extra_chill_team';
	if ( in_array( $team_role, $user->roles, true ) ) {
		$allcaps[ EC_USERS_INTELLIGENCE_READ_CAP ] = true;
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'ec_users_grant_studio_intelligence_read', 10, 4 );
