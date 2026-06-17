<?php
/**
 * Team Experience Analytics Events
 *
 * Thin emit helper for team-experience + artist-funnel analytics events.
 *
 * SHARED EVENT CONTRACT (Extra-Chill/extrachill-users#127)
 * --------------------------------------------------------
 * The team-experience instrumentation spans three plugins
 * (extrachill-users, extrachill-studio, extrachill-roadie) plus the
 * artist-funnel work (extrachill-artist-platform#72). The event NAMES
 * and payload shape are a shared contract: defined once here, reused
 * verbatim at every emit site. Each plugin owns its own emit helper but
 * uses the SAME event_type strings and the SAME `user_id`-in-payload
 * convention so the cohort rollups can join on the `extra_chill_team`
 * role.
 *
 * Event types emitted by extrachill-users:
 *   - team_member_added        (ec_users_grant_team_role)
 *   - team_member_removed      (ec_users_revoke_team_role)
 *   - artist_access_requested  (request-artist-access ability)
 *   - artist_access_approved   (approve-artist-access ability)
 *
 * Event types emitted by sibling plugins (documented here for the
 * single-source contract — emitted in their own plugins):
 *   - extrachill-studio:  studio_draft_created, studio_submitted_for_review,
 *                         studio_transcription_run
 *   - extrachill-roadie:  roadie_session_started, roadie_tool_invoked
 *   - extrachill-artist-platform: artist_profile_created (sibling-owned)
 *
 * Payload convention: every event carries a `user_id` key in event_data
 * identifying the SUBJECT of the event (the user the action is about),
 * which may differ from the acting/authenticated user the analytics
 * table records in its own `user_id` column. This lets cohort rollups
 * join the subject against the `extra_chill_team` role regardless of who
 * triggered the action.
 *
 * All emits route through the existing `extrachill/track-analytics-event`
 * ability — never write the `c8c_extrachill_analytics_events` table
 * directly (RULES.md: use the ability).
 *
 * @package ExtraChill\Users
 * @since   0.18.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emit a team-experience analytics event via the canonical ability.
 *
 * No-op (returns 0) when the analytics ability is unavailable, so emit
 * sites never need to guard the call themselves.
 *
 * @param string $event_type Event type identifier from the shared contract.
 * @param int    $user_id    Subject user ID (the user the event is about).
 * @param array  $extra      Optional additional payload keys merged into event_data.
 * @return int Event ID on success, 0 on failure / when unavailable.
 */
function ec_users_emit_team_experience_event( $event_type, $user_id, $extra = array() ) {
	if ( empty( $event_type ) ) {
		return 0;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return 0;
	}

	$ability = wp_get_ability( 'extrachill/track-analytics-event' );
	if ( ! $ability ) {
		return 0;
	}

	$event_data = array_merge(
		array( 'user_id' => (int) $user_id ),
		is_array( $extra ) ? $extra : array()
	);

	$result = $ability->execute(
		array(
			'event_type' => $event_type,
			'event_data' => $event_data,
		)
	);

	return is_int( $result ) ? $result : 0;
}
