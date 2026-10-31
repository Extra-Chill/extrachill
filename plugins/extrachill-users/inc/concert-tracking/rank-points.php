<?php
/**
 * Concert tracking → community rank points.
 *
 * Registers concert-going (My Shows) as a pluggable point source for the
 * cross-site rank/points system. The points engine (extrachill-users/inc/rank-
 * system/points-engine.php) exposes a generic, feature-agnostic extensibility
 * seam — the `ec_rank_extra_points` filter — and this file is the
 * concert-specific consumer of it. The engine never references concerts;
 * concerts opt into rank via the filter from here. Layer purity preserved.
 *
 * Weight rationale: each tracked show is worth more than a forum contribution
 * (2 points) because attending a concert is a higher-intent, harder-to-fake
 * signal of genuine scene participation than posting. 3 points per show is the
 * chosen weight; tune via the `ec_users_concert_rank_points_per_show` filter
 * below.
 *
 * Performance: this runs inside extrachill_get_user_total_points(), whose TOTAL
 * is transient-cached for one hour, so the show count query (a single COUNT(*)
 * via ec_users_get_user_event_count) only executes on cache misses.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default rank points awarded per tracked show.
 *
 * Concerts are higher-intent than a forum reply (2 pts), hence a higher weight.
 */
const EC_USERS_CONCERT_RANK_POINTS_PER_SHOW = 3;

/**
 * Contribute concert-attendance points to a user's rank total.
 *
 * Hooks the points engine's generic `ec_rank_extra_points` seam and adds
 * (shows attended x per-show weight) to the running extra-points value. Returns
 * the value unchanged when the count helper is unavailable so the rank total
 * never breaks if concert tracking is disabled.
 *
 * @param float $points  Running total of externally contributed points.
 * @param int   $user_id WordPress user ID being scored.
 * @return float Running total plus this source's contribution.
 */
function ec_users_concert_rank_extra_points( $points, $user_id ) {
	if ( ! function_exists( 'ec_users_get_user_event_count' ) ) {
		return (float) $points;
	}

	$shows = ec_users_get_user_event_count( (int) $user_id );

	/**
	 * Filter the rank points awarded per tracked show.
	 *
	 * @param int $points_per_show Default per-show weight.
	 * @param int $user_id         WordPress user ID being scored.
	 */
	$per_show = (int) apply_filters(
		'ec_users_concert_rank_points_per_show',
		EC_USERS_CONCERT_RANK_POINTS_PER_SHOW,
		(int) $user_id
	);

	return (float) $points + ( $shows * $per_show );
}
add_filter( 'ec_rank_extra_points', 'ec_users_concert_rank_extra_points', 10, 2 );
