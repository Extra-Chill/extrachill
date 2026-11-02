<?php
/**
 * Concert tracking → community rank points.
 *
 * Registers `concert_attendance` on the `ec_points_sources` registry. Only
 * tracked past shows earn points; upcoming marks, ongoing shows, and ratings
 * never contribute. The dated contribution/heatmap source is independent.
 *
 * Each attended show earns 3 points by default, configurable through
 * `ec_users_concert_rank_points_per_show`. The points engine caches the total
 * for one hour, so the past-show COUNT query only runs on cache misses.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default rank points awarded per tracked past show.
 */
const EC_USERS_CONCERT_RANK_POINTS_PER_SHOW = 3;

/**
 * Register concert-attendance points from tracked past shows.
 *
 * Returns the sources unchanged if concert tracking is unavailable.
 *
 * @param array $sources Source-id => points map.
 * @param int   $user_id WordPress user ID being scored.
 * @return array Sources including this user's concert-attendance contribution.
 */
function ec_users_concert_attendance_points_source( $sources, $user_id ) {
	if ( ! function_exists( 'ec_users_get_user_past_event_count' ) ) {
		return $sources;
	}

	$shows = ec_users_get_user_past_event_count( (int) $user_id );

	/**
	 * Filter the rank points awarded per tracked past show.
	 *
	 * @param int $points_per_show Default per-show weight.
	 * @param int $user_id         WordPress user ID being scored.
	 */
	$per_show = (int) apply_filters(
		'ec_users_concert_rank_points_per_show',
		EC_USERS_CONCERT_RANK_POINTS_PER_SHOW,
		(int) $user_id
	);

	$sources['concert_attendance'] = $shows * $per_show;
	return $sources;
}
add_filter( 'ec_points_sources', 'ec_users_concert_attendance_points_source', 10, 2 );
