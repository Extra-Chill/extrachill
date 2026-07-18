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
 * Pageview event — one row per real (non-bot) front-end view, carrying the
 * anonymous first-party visitor_id when present. Payload fields are post_id,
 * route_family, view_kind, referrer_host, and the server-owned is_bot verdict.
 * This is the deterministic per-visitor history the retention rollups read.
 */
const EC_ANALYTICS_EVENT_PAGEVIEW = 'pageview';

/**
 * Active platform event contracts.
 *
 * These names are emitted in production and consumed by first-party reports.
 * Server-owned emitters keep using the flexible track-event ability, but MUST
 * limit payloads to the documented fields and preserve the existing privacy
 * behavior:
 *
 * - user_registration: user_id, source, method, referrer, utm.
 * - newsletter_signup: context, list_id.
 * - search: search_term, result_count; source and is_bot are server stamps.
 * - search_attack: the search fields plus classification, pattern_family,
 *   matched_token, ip, user_agent, and is_bot security diagnostics.
 * - 404_error: requested_url, referer, user_agent, ip_hash, is_bot.
 * - email_sent: recipient_count, context, is_bot.
 * - email_failed: recipient_count, context, error_code, is_bot.
 * - redirect_fire: rule_id, from_url, to_url, dest_host, dest_path, is_bot.
 *
 * Registration and newsletter payloads are intentionally documented rather
 * than enforced here: their trusted server emitters need the existing flexible
 * ability contract, including additive attribution fields.
 */
const EC_ANALYTICS_EVENT_USER_REGISTRATION = 'user_registration';
const EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP = 'newsletter_signup';
const EC_ANALYTICS_EVENT_SEARCH            = 'search';
const EC_ANALYTICS_EVENT_SEARCH_ATTACK     = 'search_attack';
const EC_ANALYTICS_EVENT_404_ERROR         = '404_error';
const EC_ANALYTICS_EVENT_EMAIL_SENT        = 'email_sent';
const EC_ANALYTICS_EVENT_EMAIL_FAILED      = 'email_failed';
const EC_ANALYTICS_EVENT_REDIRECT_FIRE     = 'redirect_fire';

/**
 * Public browser-adapter event contracts.
 *
 * These payloads cross unauthenticated REST adapters before reaching the
 * track-event ability, so write-integrity.php enforces the listed fields and
 * bounds. `is_bot` is always server-owned and removed from browser input.
 *
 * - share_click: destination, share_url.
 * - bridge_click / bridge_impression: dest_site, source_post, source_site, term.
 * - outbound_click: dest_host, dest_url, category.
 */
const EC_ANALYTICS_EVENT_SHARE_CLICK       = 'share_click';
const EC_ANALYTICS_EVENT_BRIDGE_CLICK      = 'bridge_click';
const EC_ANALYTICS_EVENT_BRIDGE_IMPRESSION = 'bridge_impression';
const EC_ANALYTICS_EVENT_OUTBOUND_CLICK    = 'outbound_click';

/**
 * First-consumer experiment contract.
 *
 * Network owns assignment and validates its code-owned definition before
 * firing bounded server-side hooks. Analytics persists only these exact names
 * and fields. Assignment records allocation; exposure records actual 50%
 * viewport visibility. Neither event implies the other.
 */
const EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT           = 'experiment_assignment';
const EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE             = 'experiment_exposure';
const EC_ANALYTICS_EXPERIMENT_GEO_BRIDGE_HOLDOUT         = 'geo-bridge-holdout';
const EC_ANALYTICS_EXPERIMENT_SURFACE_SINGLE_POST_BRIDGE = 'single-post-bridge';
const EC_ANALYTICS_EXPERIMENT_VARIANTS                   = array( 'control', 'treatment' );

/**
 * Browser-originated events admitted by the public adapter boundary.
 *
 * @var string[]
 */
const EC_ANALYTICS_PUBLIC_BROWSER_EVENTS = array(
	EC_ANALYTICS_EVENT_SHARE_CLICK,
	EC_ANALYTICS_EVENT_BRIDGE_CLICK,
	EC_ANALYTICS_EVENT_BRIDGE_IMPRESSION,
	EC_ANALYTICS_EVENT_OUTBOUND_CLICK,
);

/** Team-experience events (team membership + Studio + Roadie usage). */
const EC_ANALYTICS_EVENT_TEAM_MEMBER_ADDED        = 'team_member_added';
const EC_ANALYTICS_EVENT_TEAM_MEMBER_REMOVED      = 'team_member_removed';
const EC_ANALYTICS_EVENT_STUDIO_DRAFT_CREATED     = 'studio_draft_created';
const EC_ANALYTICS_EVENT_STUDIO_SUBMITTED         = 'studio_submitted_for_review';
const EC_ANALYTICS_EVENT_STUDIO_TRANSCRIPTION_RUN = 'studio_transcription_run';
const EC_ANALYTICS_EVENT_ROADIE_SESSION_STARTED   = 'roadie_session_started';
const EC_ANALYTICS_EVENT_ROADIE_TOOL_INVOKED      = 'roadie_tool_invoked';

/**
 * General account-onboarding events.
 *
 * The ordered happy path is `onboarding_viewed` -> `onboarding_completed`.
 * Submission failures describe validation or persistence failures alongside
 * that path. Reminder events record reminder delivery and a later completion
 * attributed to a reminder without exposing the reminder recipient.
 *
 * Emitters MUST keep event_data privacy-safe. The payload contract is limited
 * to `user_id`, registration `source`, `method`, and `surface`, plus boolean
 * state such as `has_local_scene`. The anonymous `visitor_id`, when available,
 * remains a top-level ability argument for pre/post-login stitching. Never
 * record a Local Scene slug or name, or PII such as an email address, username,
 * display name, IP address, or free-form user input.
 */
const EC_ANALYTICS_EVENT_ONBOARDING_VIEWED             = 'onboarding_viewed';
const EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED  = 'onboarding_submission_failed';
const EC_ANALYTICS_EVENT_ONBOARDING_COMPLETED          = 'onboarding_completed';
const EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_SENT      = 'onboarding_reminder_sent';
const EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_RECOVERED = 'onboarding_reminder_recovered';

/**
 * Local Scene prompt events.
 *
 * Emitters MUST keep event_data privacy-safe. Payloads are limited to
 * `user_id` and boolean state such as `visibility` or `has_local_scene`.
 * Never record a Local Scene slug or name, or PII such as an email address,
 * username, display name, IP address, or free-form user input.
 */
const EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_VIEWED    = 'local_scene_prompt_viewed';
const EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_DISMISSED = 'local_scene_prompt_dismissed';
const EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_COMPLETED = 'local_scene_prompt_completed';

/**
 * Artist-funnel events.
 *
 * The activation funnel a new member walks while trying to build/claim an
 * artist page, ordered start -> finish:
 *
 *   user_registration        (emitted by extrachill-users on account create)
 *     -> artist_signup_started        entered the create-artist flow
 *       -> artist_profile_created     profile row inserted
 *         -> artist_profile_first_publish   link page created/published
 *
 * Every emit site carries the anonymous first-party `visitor_id` (as the
 * top-level ability arg) AND the `user_id` (in event_data) so a single
 * member's pre/post-login path stitches into one queryable sequence and the
 * step-to-step drop-off (and the specific abandon step) is computable. This
 * is the same visitor_id<->user_id stitching the registration referrer/UTM
 * work keys on (Extra-Chill/extrachill-users#145) — built once, shared.
 *
 * The access-gate events (`artist_access_requested` / `_approved`) sit
 * upstream of this funnel for users who must request access first.
 */
const EC_ANALYTICS_EVENT_ARTIST_ACCESS_REQUESTED      = 'artist_access_requested';
const EC_ANALYTICS_EVENT_ARTIST_ACCESS_APPROVED       = 'artist_access_approved';
const EC_ANALYTICS_EVENT_ARTIST_SIGNUP_STARTED        = 'artist_signup_started';
const EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED       = 'artist_profile_created';
const EC_ANALYTICS_EVENT_ARTIST_PROFILE_FIRST_PUBLISH = 'artist_profile_first_publish';

/**
 * Artist-funnel FRICTION events — the failure/thrash modes that sit alongside
 * the ordered happy-path steps above but are NOT themselves steps.
 *
 * These make onboarding funnel leaks visible: instead of a person silently
 * vanishing between two happy-path steps, an anomaly emit records WHY the
 * funnel thrashed. They carry the same `visitor_id` (top-level ability arg)
 * AND `user_id` (event_data) stitching as the steps, so a friction event
 * attributes to the same person the funnel reader already counts.
 *
 *   artist_profile_duplicate_created — a member who already has an artist
 *     profile created ANOTHER one (a dead-end thrash: the second profile is
 *     not the activation the funnel is measuring, it is wasted motion).
 *   user_reregistration_attempt — an already-known person tried to register a
 *     fresh account (a duplicate-account / re-registration signal: the top of
 *     the funnel is leaking returning users into new identities).
 *
 * The emit CALLS live in extrachill-artist-platform / extrachill-users
 * (separate PRs); this contract gives those emits a canonical name to write.
 */
const EC_ANALYTICS_EVENT_ARTIST_PROFILE_DUPLICATE_CREATED = 'artist_profile_duplicate_created';
const EC_ANALYTICS_EVENT_USER_REREGISTRATION_ATTEMPT      = 'user_reregistration_attempt';

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
 * All general account-onboarding events. Order follows the lifecycle; failure
 * and reminder signals are not additional required funnel steps.
 *
 * @var string[]
 */
const EC_ANALYTICS_ONBOARDING_FUNNEL_EVENTS = array(
	EC_ANALYTICS_EVENT_ONBOARDING_VIEWED,
	EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED,
	EC_ANALYTICS_EVENT_ONBOARDING_COMPLETED,
	EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_SENT,
	EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_RECOVERED,
);

/**
 * Ordered required steps in the general account-onboarding happy path.
 *
 * @var string[]
 */
const EC_ANALYTICS_ONBOARDING_HAPPY_PATH = array(
	EC_ANALYTICS_EVENT_ONBOARDING_VIEWED,
	EC_ANALYTICS_EVENT_ONBOARDING_COMPLETED,
);

/**
 * Local Scene prompt events in lifecycle order.
 *
 * @var string[]
 */
const EC_ANALYTICS_LOCAL_SCENE_PROMPT_EVENTS = array(
	EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_VIEWED,
	EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_DISMISSED,
	EC_ANALYTICS_EVENT_LOCAL_SCENE_PROMPT_COMPLETED,
);

/**
 * The artist-funnel event set.
 *
 * @var string[]
 */
const EC_ANALYTICS_ARTIST_FUNNEL_EVENTS = array(
	EC_ANALYTICS_EVENT_ARTIST_ACCESS_REQUESTED,
	EC_ANALYTICS_EVENT_ARTIST_ACCESS_APPROVED,
	EC_ANALYTICS_EVENT_ARTIST_SIGNUP_STARTED,
	EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED,
	EC_ANALYTICS_EVENT_ARTIST_PROFILE_FIRST_PUBLISH,
);

/**
 * The activation sub-funnel — the ordered start->finish steps a new member
 * walks when building an artist page, EXCLUDING the upstream access-gate
 * events. Readers iterate this to compute step-to-step conversion and locate
 * the abandon step. Order is significant (funnel sequence).
 *
 * @var string[]
 */
const EC_ANALYTICS_ARTIST_ACTIVATION_STEPS = array(
	EC_ANALYTICS_EVENT_ARTIST_SIGNUP_STARTED,
	EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED,
	EC_ANALYTICS_EVENT_ARTIST_PROFILE_FIRST_PUBLISH,
);

/**
 * The activation FRICTION event set — failure/thrash signals that run
 * alongside the ordered activation steps but are not steps themselves. The
 * funnel reader counts each of these by DISTINCT person to surface a
 * measurable thrash/abandon signal next to the happy-path conversion. Order
 * is NOT significant (these are independent anomalies, not a sequence).
 *
 * @var string[]
 */
const EC_ANALYTICS_ARTIST_ACTIVATION_FRICTION_EVENTS = array(
	EC_ANALYTICS_EVENT_ARTIST_PROFILE_DUPLICATE_CREATED,
	EC_ANALYTICS_EVENT_USER_REREGISTRATION_ATTEMPT,
);
