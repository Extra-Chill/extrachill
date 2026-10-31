<?php
/**
 * Concert tracking → dated contribution events.
 *
 * The dated sibling of rank-points.php. While rank-points.php contributes the
 * SCALAR concert points (via ec_rank_extra_points), this file contributes the
 * DATED concert check-in events (via ec_contribution_events) so the heatmap
 * (extrachill-community#147) can show concert attendance on a calendar grid.
 *
 * Source: the ec_concert_tracking table, which stores `created_at` as UTC.
 * Day computation + timezone normalization is delegated to the shared
 * ec_bucket_utc_events_by_local_day() helper in the rank-system seam — this
 * contributor never reasons about timezones.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contribute dated concert check-in events.
 *
 * SELECTs the raw `created_at` UTC column and delegates to
 * ec_bucket_utc_events_by_local_day(), which handles the UTC→site-local day
 * conversion AND the since_ymd window uniformly.
 *
 * @param array  $events    Running event list.
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
 * @return array
 */
function ec_users_concert_contribution_events( $events, $user_id, $since_ymd ) {
	if ( ! function_exists( 'ec_users_get_user_dated_event_checks' ) ) {
		return $events;
	}

	if ( ! function_exists( 'ec_bucket_utc_events_by_local_day' ) ) {
		return $events;
	}

	$utc_timestamps = ec_users_get_user_dated_event_checks( (int) $user_id );

	if ( empty( $utc_timestamps ) ) {
		return $events;
	}

	return array_merge(
		$events,
		ec_bucket_utc_events_by_local_day( $utc_timestamps, 'concert', $since_ymd )
	);
}
add_filter( 'ec_contribution_events', 'ec_users_concert_contribution_events', 10, 3 );
