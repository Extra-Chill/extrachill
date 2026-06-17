<?php
/**
 * Studio Team Experience Analytics Events
 *
 * Thin emit helper for Studio usage analytics events.
 *
 * SHARED EVENT CONTRACT (Extra-Chill/extrachill-users#127)
 * --------------------------------------------------------
 * The team-experience instrumentation spans three plugins
 * (extrachill-users, extrachill-studio, extrachill-roadie). The event
 * NAMES and payload shape are a shared contract defined once and reused
 * verbatim at every emit site, so the team-cohort rollup in
 * extrachill-users (`extrachill/get-team-experience-stats`) can join the
 * events against the `extra_chill_team` role on `user_id`.
 *
 * Event types emitted by extrachill-studio:
 *   - studio_draft_created          (compose REST create, status draft)
 *   - studio_submitted_for_review   (compose REST create/update, status pending)
 *   - studio_transcription_run      (transcription completion callback)
 *
 * Payload convention: every event carries a `user_id` key in event_data
 * identifying the SUBJECT (the team member who took the action). Studio
 * emit sites pass it explicitly because some run in contexts where the
 * acting user the analytics table records may be 0 (e.g. the HMAC-signed
 * transcription callback is unauthenticated).
 *
 * All emits route through the existing `extrachill/track-analytics-event`
 * ability — never write the analytics table directly.
 *
 * @package    ExtraChillStudio
 * @subpackage TeamExperience
 * @since      0.17.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emit a Studio team-experience analytics event via the canonical ability.
 *
 * No-op (returns 0) when the analytics ability is unavailable, so emit
 * sites never need to guard the call themselves.
 *
 * @param string $event_type Event type identifier from the shared contract.
 * @param int    $user_id    Subject user ID (the team member who acted).
 * @param array  $extra      Optional additional payload keys merged into event_data.
 * @return int Event ID on success, 0 on failure / when unavailable.
 */
function ec_studio_emit_team_experience_event( string $event_type, int $user_id, array $extra = array() ): int {
	if ( '' === $event_type ) {
		return 0;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return 0;
	}

	$ability = wp_get_ability( 'extrachill/track-analytics-event' );
	if ( ! $ability ) {
		return 0;
	}

	$event_data = array_merge( array( 'user_id' => $user_id ), $extra );

	$result = $ability->execute(
		array(
			'event_type' => $event_type,
			'event_data' => $event_data,
		)
	);

	return is_int( $result ) ? $result : 0;
}
