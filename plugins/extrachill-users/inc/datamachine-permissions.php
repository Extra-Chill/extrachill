<?php
/**
 * Data Machine analytics permission bridge.
 *
 * Data Machine exposes a read-only analytics surface (the GA4 / Search
 * Console routes behind data-machine-business) gated on the
 * `datamachine_view_analytics` capability tier added in data-machine#2807.
 * That cap tier exists precisely so analytics-READ can be handed out without
 * granting the Data Machine write/admin surface. The Studio Network tab
 * (extrachill-studio#104) consumes the GA route and gracefully degrades to
 * "coming soon" whenever the caller lacks the cap.
 *
 * This bridge wires the EC `extra_chill_team` concept into that generic
 * Data Machine capability. It is the correct layer for this policy: Data
 * Machine knows nothing about Extra Chill; this plugin is the one place where
 * EC-specific knowledge (the team role) meets that generic permission surface
 * — exactly how `inc/brand-socials-permissions.php` bridges the team into the
 * socials surface and how extrachill-roadie bridges `access_roadie` into
 * `datamachine_can_access_agent`.
 *
 * Precedent A (extrachill-roadie/inc/contribute-code/capabilities.php): the
 * grant is a pure `user_has_cap` filter. `datamachine_view_analytics` lives in
 * a FOREIGN namespace (Data Machine's), so we deliberately do NOT write it onto
 * the `extra_chill_team` role object via add_cap / ec_users_get_team_role_caps().
 * Keeping it out of the role's persisted cap set means the EC role stays the
 * source of truth for EC-owned caps only, and the foreign cap is resolved
 * dynamically at check time without touching stored role state.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Data Machine read-only analytics capability granted to the team.
 *
 * Owned by Data Machine (data-machine#2807), not by this plugin. Named here as
 * a constant only so the filter below reads cleanly; it is intentionally absent
 * from ec_users_get_team_role_caps() — see the file docblock.
 */
const EC_DATAMACHINE_VIEW_ANALYTICS_CAP = 'datamachine_view_analytics';

/**
 * Grant `datamachine_view_analytics` to Extra Chill team members.
 *
 * Hooks `user_has_cap` and dynamically resolves the foreign-namespace cap to
 * true for any user holding the `extra_chill_team` role. This can only ever
 * BROADEN access for a team member — it never strips a cap (an existing true
 * value short-circuits) and never touches non-team users.
 *
 * @since 0.21.2
 *
 * @param array<string,bool> $allcaps All capabilities currently resolved for the user.
 * @param string[]           $caps    Required primitive caps for the check (unused).
 * @param array              $args    [0] requested cap, [1] user ID, [2] object ID (unused).
 * @param WP_User|null       $user    The user object being checked.
 * @return array<string,bool> Filtered capability map.
 */
function ec_grant_datamachine_view_analytics_cap( array $allcaps, array $caps, array $args, $user ): array {
	unset( $caps, $args );

	if ( ! $user || empty( $user->roles ) ) {
		return $allcaps;
	}

	// Already granted (e.g. an admin/super-admin) — nothing to add.
	if ( ! empty( $allcaps[ EC_DATAMACHINE_VIEW_ANALYTICS_CAP ] ) ) {
		return $allcaps;
	}

	$team_role = defined( 'EC_USERS_TEAM_ROLE' ) ? EC_USERS_TEAM_ROLE : 'extra_chill_team';

	if ( in_array( $team_role, (array) $user->roles, true ) ) {
		$allcaps[ EC_DATAMACHINE_VIEW_ANALYTICS_CAP ] = true;
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'ec_grant_datamachine_view_analytics_cap', 10, 4 );
