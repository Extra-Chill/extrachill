<?php
/**
 * Concert attendance funnel analytics (Extra-Chill/extrachill-users#450).
 *
 * Emits the canonical attendance events owned by extrachill-analytics from
 * the interactive `extrachill/set-event-mark` path only. Bulk concert-history
 * imports call the service layer directly and never reach these emitters, so
 * imported history can't masquerade as live engagement.
 *
 * Clicks are not emitted here. The attendance button carries a `data-ec-track`
 * id, and the network-wide `cta_click` adapter records it with no per-button
 * code, split by anonymous vs. member through the id itself.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emit one attendance analytics event.
 *
 * Event names are canonical constants in extrachill-analytics. They are read
 * through `defined()` so a deploy where this plugin ships ahead of the
 * analytics release no-ops instead of fataling on an undefined constant.
 *
 * @param string $constant   Name of the EC_ANALYTICS_EVENT_* constant.
 * @param array  $event_data Privacy-safe payload.
 * @return int Event ID, or zero when analytics is unavailable.
 */
function ec_users_emit_attendance_event( string $constant, array $event_data ): int {
	if ( ! defined( $constant ) ) {
		return 0;
	}

	$ability = ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'extrachill/track-analytics-event' ) )
		? wp_get_ability( 'extrachill/track-analytics-event' )
		: null;
	if ( ! $ability ) {
		return 0;
	}

	$result = $ability->execute(
		array(
			'event_type' => (string) constant( $constant ),
			'event_data' => $event_data,
		)
	);

	return is_int( $result ) ? $result : 0;
}

/**
 * Record the funnel transitions for one interactive set-event-mark call.
 *
 * - `attendance_intent_resumed` whenever the call resumes a signed
 *   pre-login continuation, even if the event was already marked.
 * - `attendance_marked` / `attendance_unmarked` only on a real transition.
 *
 * @param int    $user_id    User whose attendance changed.
 * @param int    $event_id   Event post ID.
 * @param int    $blog_id    Events blog ID.
 * @param bool   $marked     Resulting attendance state.
 * @param bool   $changed    Whether the call changed state.
 * @param bool   $first_mark Whether this is the user's first-ever mark.
 * @param string $timing     upcoming|ongoing|past.
 * @param string $source     button|intent.
 * @return string[] Constants emitted, in order (for tests and diagnostics).
 */
function ec_users_record_attendance_transition( int $user_id, int $event_id, int $blog_id, bool $marked, bool $changed, bool $first_mark, string $timing, string $source ): array {
	$base    = array(
		'user_id'  => $user_id,
		'event_id' => $event_id,
		'blog_id'  => $blog_id,
	);
	$emitted = array();

	if ( 'intent' === $source ) {
		ec_users_emit_attendance_event( 'EC_ANALYTICS_EVENT_ATTENDANCE_INTENT_RESUMED', $base + array( 'timing' => $timing ) );
		$emitted[] = 'EC_ANALYTICS_EVENT_ATTENDANCE_INTENT_RESUMED';
	}

	if ( ! $changed ) {
		return $emitted;
	}

	if ( $marked ) {
		ec_users_emit_attendance_event(
			'EC_ANALYTICS_EVENT_ATTENDANCE_MARKED',
			$base + array(
				'timing'     => $timing,
				'first_mark' => $first_mark,
				'source'     => $source,
			)
		);
		$emitted[] = 'EC_ANALYTICS_EVENT_ATTENDANCE_MARKED';
		return $emitted;
	}

	ec_users_emit_attendance_event(
		'EC_ANALYTICS_EVENT_ATTENDANCE_UNMARKED',
		$base + array(
			'timing' => $timing,
			'source' => $source,
		)
	);
	$emitted[] = 'EC_ANALYTICS_EVENT_ATTENDANCE_UNMARKED';
	return $emitted;
}
