<?php
/**
 * Shared permission check for team-readable analytics report routes.
 *
 * The reporting abilities in extrachill-analytics (summary, retention,
 * surface-growth, meta, conversion-map) were relaxed to the team-readable tier
 * (the `access_studio` cap, granted by the extra_chill_team role) so the Studio
 * "Network" tab can render for the whole team. The REST wrapper is a SECOND,
 * independent gate — if it stayed admin-only, team members would be blocked
 * before the ability ran. This helper mirrors the ability-layer policy so both
 * gates line up.
 *
 * TIERED POLICY (settled, see analytics issue #92):
 *   - Team-readable: traffic, growth, retention, top content, conversion.
 *   - Admin-only (unchanged): revenue, attacks/scanner, PHP errors, purges —
 *     those routes keep manage_network_options and must NOT use this helper.
 *
 * Uses the `access_studio` cap directly (the cap behind ec_is_team_member())
 * to avoid a cross-plugin function dependency.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current actor may read team-readable analytics reports over REST.
 *
 * True for network/site admins and for extra_chill_team members
 * (the access_studio cap).
 *
 * @return bool
 */
function extrachill_api_analytics_reports_permission_check() {
	return current_user_can( 'manage_network_options' )
		|| current_user_can( 'manage_options' )
		|| current_user_can( 'access_studio' );
}
