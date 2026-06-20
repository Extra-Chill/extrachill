<?php
/**
 * Artist-signup activation funnel instrumentation.
 *
 * Emits the ordered activation-funnel analytics events a new member walks
 * while building/claiming an artist page:
 *
 *   user_registration                (emitted by extrachill-users)
 *     -> artist_signup_started        entered the create-artist flow
 *       -> artist_profile_created     profile row inserted (create-artist handler)
 *         -> artist_profile_first_publish   link page created/published
 *
 * Every emit carries the anonymous first-party `visitor_id` (top-level ability
 * arg) AND the `user_id` (in event_data), so a single member's pre/post-login
 * path stitches into one queryable sequence and the step-to-step drop-off
 * (and the specific abandon step) is computable. This is the same
 * visitor_id<->user_id stitching the registration referrer/UTM work keys on
 * (Extra-Chill/extrachill-users#145) — built once, shared.
 *
 * Event-name contract: the funnel event_type strings are defined ONCE in
 * extrachill-analytics (inc/core/event-types.php) as
 * EC_ANALYTICS_EVENT_ARTIST_* constants. extrachill-analytics owns the
 * analytics substrate and the `extrachill/track-analytics-event` ability this
 * file calls at runtime, and is network-active, so the constants are
 * guaranteed present here — referencing them adds no new coupling and lets a
 * rename happen in exactly one place.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emit a single artist-funnel analytics event with visitor_id stitching.
 *
 * Thin wrapper over the extrachill/track-analytics-event ability that:
 *   - no-ops cleanly when the analytics plugin (and its ability) is absent,
 *   - resolves the anonymous first-party visitor_id from the analytics cookie
 *     helper (empty string when the visitor opted out via GPC/DNT, which the
 *     ability stores as NULL),
 *   - always carries user_id in the payload for cohort joins.
 *
 * The events table also auto-stamps user_id (from the current user) and
 * blog_id (from the current context) on the row itself.
 *
 * @param string $event_type One of the EC_ANALYTICS_EVENT_ARTIST_* constants.
 * @param array  $event_data Payload; user_id should be included by the caller.
 * @return void
 */
function ec_artist_platform_emit_funnel_event( $event_type, array $event_data ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return;
	}

	$ability = wp_get_ability( 'extrachill/track-analytics-event' );
	if ( ! $ability ) {
		return;
	}

	$visitor_id = function_exists( 'extrachill_analytics_get_or_mint_visitor_id' )
		? (string) extrachill_analytics_get_or_mint_visitor_id()
		: '';

	$ability->execute(
		array(
			'event_type' => $event_type,
			'event_data' => $event_data,
			'visitor_id' => $visitor_id,
		)
	);
}

/**
 * Emit artist_profile_first_publish when a link page is created/published.
 *
 * Hooks the decoupled `ec_link_page_created` action fired by
 * ec_create_link_page() (inc/core/filters/create.php), so this fires exactly
 * once when an artist's link page is actually created — the "finished, not
 * just registered a bare profile" activation signal — regardless of which
 * code path triggered creation. Skips forced/programmatic re-creation.
 *
 * @param int  $link_page_id Newly created link page ID.
 * @param int  $artist_id    Associated artist profile ID.
 * @param bool $force        Whether creation was forced (programmatic re-create).
 * @return void
 */
function ec_artist_platform_emit_first_publish( $link_page_id, $artist_id, $force = false ) {
	if ( $force ) {
		return;
	}

	if ( ! defined( 'EC_ANALYTICS_EVENT_ARTIST_PROFILE_FIRST_PUBLISH' ) ) {
		return;
	}

	ec_artist_platform_emit_funnel_event(
		EC_ANALYTICS_EVENT_ARTIST_PROFILE_FIRST_PUBLISH,
		array(
			'user_id'      => get_current_user_id(),
			'artist_id'    => (int) $artist_id,
			'link_page_id' => (int) $link_page_id,
		)
	);
}
add_action( 'ec_link_page_created', 'ec_artist_platform_emit_first_publish', 10, 3 );
