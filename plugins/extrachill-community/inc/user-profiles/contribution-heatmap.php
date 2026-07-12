<?php
/**
 * Contribution Heatmap Profile Card (block bridge)
 *
 * Renders the `extrachill/contribution-heatmap` block on the bbPress user
 * profile — a GitHub-style 53-week grid with client-side year navigation
 * back to the user's join year. The block owns everything: render.php
 * server-inlines the initial trailing-window calendar payload, and view.tsx
 * hydrates the grid + year tabs, fetching past years through the
 * `extrachill/get-user-contribution-calendar` ability without a reload.
 *
 * Data flow: block render.php → extrachill_community_get_contribution_calendar()
 * (inc/social/rank-system/contribution-calendar-ability.php) → the
 * users-owned dated-contributions seam (`ec_get_contribution_events`).
 *
 * Placement: full-width card via `bbp_template_after_user_profile` at
 * priority 1, below the About card. Concert History (priority 5) and the
 * Recent Conversations feed (priority 99) follow on the same hook.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display the Contribution Activity heatmap block on the bbPress user profile.
 *
 * The block's render.php handles all degradation (missing seam, missing
 * user) by rendering nothing, so this bridge stays a thin guard + one
 * render_block() call.
 */
function ec_community_display_contribution_heatmap() {
	// Only on the profile overview tab — the heatmap is a profile-body element,
	// not a header chrome element repeated across the Topics/Replies/Edit tabs.
	if ( function_exists( 'bbp_is_single_user_profile' ) && ! bbp_is_single_user_profile() ) {
		return;
	}

	echo render_block( array( 'blockName' => 'extrachill/contribution-heatmap' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block() returns block markup escaped at build time.
}

// Render below the About card that follows the profile hero. Priority 1 leads
// the post-grid sections; Concert History (priority 5) follows, with the
// Recent Conversations feed closing out the page (99).
add_action( 'bbp_template_after_user_profile', 'ec_community_display_contribution_heatmap', 1 );
