<?php
/**
 * Canonical Analytics Event-Name Contract
 *
 * SINGLE cross-plugin source of truth for the team-experience and
 * artist-funnel analytics event_type strings (Extra-Chill/extrachill-users#129).
 *
 * Why extrachill-analytics owns these names
 * -----------------------------------------
 * extrachill-analytics owns the analytics substrate: the events table and
 * the `extrachill/track-analytics-event` ability that EVERY emitter across
 * extrachill-users / -studio / -roadie / -artist-platform already calls at
 * runtime. That ability call is an existing hard runtime dependency on this
 * plugin — so referencing a constant defined here adds ZERO new coupling.
 * extrachill-analytics is also `Network: true` (active on every site), so
 * these constants are guaranteed defined wherever an emitter or reader runs.
 *
 * Defining the names ONCE here — and having every emit site AND every reader
 * reference the constant instead of a bare string literal — means a rename
 * happens in exactly one place and can never silently desync an emit from a
 * read (the "permanently-zero metric, no error" failure mode #129 is about).
 *
 * Consumers reference these constants directly. They do NOT need a local
 * string fallback: if extrachill-analytics were ever absent, the
 * `extrachill/track-analytics-event` ability would also be absent and each
 * emit helper's existing `if ( ! $ability ) { return 0; }` guard no-ops the
 * emit before the event_type is ever used.
 *
 * @package ExtraChill\Analytics
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pageview event — one row per real (non-bot) front-end singular view, carrying
 * the anonymous first-party visitor_id when present. This is the deterministic
 * per-visitor history the retention rollups read.
 */
const EC_ANALYTICS_EVENT_PAGEVIEW = 'pageview';

/** Team-experience events (team membership + Studio + Roadie usage). */
const EC_ANALYTICS_EVENT_TEAM_MEMBER_ADDED        = 'team_member_added';
const EC_ANALYTICS_EVENT_TEAM_MEMBER_REMOVED      = 'team_member_removed';
const EC_ANALYTICS_EVENT_STUDIO_DRAFT_CREATED     = 'studio_draft_created';
const EC_ANALYTICS_EVENT_STUDIO_SUBMITTED         = 'studio_submitted_for_review';
const EC_ANALYTICS_EVENT_STUDIO_TRANSCRIPTION_RUN = 'studio_transcription_run';
const EC_ANALYTICS_EVENT_ROADIE_SESSION_STARTED   = 'roadie_session_started';
const EC_ANALYTICS_EVENT_ROADIE_TOOL_INVOKED      = 'roadie_tool_invoked';

/** Artist-funnel events (access requests/approvals + profile creation). */
const EC_ANALYTICS_EVENT_ARTIST_ACCESS_REQUESTED = 'artist_access_requested';
const EC_ANALYTICS_EVENT_ARTIST_ACCESS_APPROVED  = 'artist_access_approved';
const EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED  = 'artist_profile_created';

/**
 * The team-experience event set surfaced by the cohort rollup
 * (`extrachill/get-team-experience-stats` in extrachill-users). Readers
 * build their query array by iterating this group instead of re-listing
 * the strings.
 *
 * @var string[]
 */
const EC_ANALYTICS_TEAM_EXPERIENCE_EVENTS = array(
	EC_ANALYTICS_EVENT_TEAM_MEMBER_ADDED,
	EC_ANALYTICS_EVENT_TEAM_MEMBER_REMOVED,
	EC_ANALYTICS_EVENT_STUDIO_DRAFT_CREATED,
	EC_ANALYTICS_EVENT_STUDIO_SUBMITTED,
	EC_ANALYTICS_EVENT_STUDIO_TRANSCRIPTION_RUN,
	EC_ANALYTICS_EVENT_ROADIE_SESSION_STARTED,
	EC_ANALYTICS_EVENT_ROADIE_TOOL_INVOKED,
);

/**
 * The artist-funnel event set.
 *
 * @var string[]
 */
const EC_ANALYTICS_ARTIST_FUNNEL_EVENTS = array(
	EC_ANALYTICS_EVENT_ARTIST_ACCESS_REQUESTED,
	EC_ANALYTICS_EVENT_ARTIST_ACCESS_APPROVED,
	EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED,
);
