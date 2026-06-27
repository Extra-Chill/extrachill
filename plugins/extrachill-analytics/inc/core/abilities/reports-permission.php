<?php
/**
 * Reporting-ability permission policy
 *
 * Single home for the read-permission policy shared by the team-readable
 * reporting abilities (traffic, growth, retention, top content, conversion).
 * Centralizing the cap check here means the tiered policy lives in ONE place
 * instead of being copy-pasted into each ability's inline permission_callback.
 *
 * TIERED POLICY (settled product decision, see issue #92):
 *   - Team-readable: traffic, growth, retention, top content, conversion. These
 *     abilities use this helper and are readable by the broader extra_chill_team
 *     role (the `access_studio` cap) in addition to network/site admins and
 *     WP-CLI, so the Studio "Network" tab can render for the whole team.
 *   - Admin-only (unchanged): revenue (Mediavine), attack/scanner summaries,
 *     PHP error summaries, destructive purges. Those abilities keep their own
 *     manage_options gate and must NOT call this helper.
 *
 * Uses the `access_studio` cap directly — the underlying cap behind
 * ec_is_team_member() — to avoid a cross-plugin function dependency.
 *
 * @package ExtraChill\Analytics
 * @since 0.23.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current actor may read team-readable analytics reports.
 *
 * True for network/site admins (manage_options), team members (access_studio,
 * granted by the extra_chill_team role), and WP-CLI. The cap policy for the
 * team-readable reporting tier lives here so it is defined once.
 *
 * @return bool
 */
function extrachill_analytics_can_read_reports() {
	return current_user_can( 'manage_options' )
		|| current_user_can( 'access_studio' )
		|| ( defined( 'WP_CLI' ) && WP_CLI );
}
