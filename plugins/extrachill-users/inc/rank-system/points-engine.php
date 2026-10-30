<?php
/**
 * Points Engine — network-wide rank points compute + cache.
 *
 * This is the canonical points engine for the Extra Chill Platform. It was
 * promoted from extrachill-community (see #165) so that rank/points — a
 * per-user, network-wide concept — lives in extrachill-users (Network: true),
 * the single source of truth for user primitives, alongside the rank tiers it
 * already owns (rank-tiers.php).
 *
 * The engine is source-agnostic: it sums point contributions from registered
 * sources via the `ec_points_sources` filter, plus the legacy additive
 * `ec_rank_extra_points` filter. It contains NO knowledge of bbPress, forums,
 * or any feature-plugin's content model — feature plugins contribute their own
 * sources through the filters. Main-site published posts are a network-level
 * concern and are contributed by this engine itself as a built-in source.
 *
 * Storage conventions (PRESERVED from the community-era engine — do not rename):
 *   - User meta key: `extrachill_total_points` (read by rank-tiers.php + leaderboard sorting)
 *   - Total transient: `user_points_{id}` (1-hour TTL)
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculate total rank points for a user across all registered sources.
 *
 * Sums contributions from the `ec_points_sources` registry filter and the
 * `ec_rank_extra_points` additive filter, then caches the total. Storage
 * conventions (`extrachill_total_points` meta, `user_points_{id}` transient,
 * 1-hour TTL) are preserved for leaderboard sorting and rank-tier resolution.
 *
 * @param int $user_id WordPress user ID.
 * @return float Total calculated points.
 */
function extrachill_get_user_total_points( $user_id ) {
	// Serve from the total-points transient when present.
	$cached = get_transient( 'user_points_' . $user_id );
	if ( false !== $cached ) {
		// Keep meta in sync (mirrors the original engine behaviour).
		update_user_meta( $user_id, 'extrachill_total_points', $cached );
		return (float) $cached;
	}

	/**
	 * Register a point source's scalar contribution.
	 *
	 * Each contributor returns an associative array of source-id => points
	 * (float|int). The engine sums all values. Source ids are opaque to the
	 * engine (used for debugging/display); the engine never inspects them.
	 *
	 * Built-in source contributed by this engine:
	 *   - `main_posts`: published posts on the main site (x10 each)
	 *
	 * Feature plugins register their own sources through this filter.
	 *
	 * @param array<string,float|int> $sources Source-id => points map.
	 * @param int                     $user_id WordPress user ID being scored.
	 */
	$sources = (array) apply_filters( 'ec_points_sources', array(), (int) $user_id );

	$source_total = 0.0;
	foreach ( $sources as $points ) {
		$source_total += (float) $points;
	}

	/**
	 * Additive scalar point contributions (legacy seam).
	 *
	 * Preserved from the original engine. Concert attendance
	 * (inc/concert-tracking/rank-points.php) contributes here. New sources
	 * should prefer the structured `ec_points_sources` registry above.
	 *
	 * @param float $extra   Running extra-points total. Default 0.0.
	 * @param int   $user_id WordPress user ID.
	 */
	$extra = (float) apply_filters( 'ec_rank_extra_points', 0.0, (int) $user_id );

	$total = $source_total + $extra;

	set_transient( 'user_points_' . $user_id, $total, HOUR_IN_SECONDS );
	update_user_meta( $user_id, 'extrachill_total_points', $total );

	return (float) $total;
}

/**
 * Built-in source: main-site published posts.
 *
 * Contributes 10 points per published `post` on the main site (resolved via
 * ec_get_blog_id('main')). This is a network-level source owned by the engine
 * because main-site content is a network concern, not a feature-plugin
 * concern.
 *
 * Hooks `ec_points_sources`.
 *
 * @param array $sources Source-id => points map.
 * @param int   $user_id WordPress user ID.
 * @return array
 */
function ec_users_main_posts_points_source( $sources, $user_id ) {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		$sources['main_posts'] = 0.0;
		return $sources;
	}

	switch_to_blog( $main_blog_id );
	try {
		$count = count_user_posts( $user_id, 'post', true );
	} finally {
		// Always restore blog context, even if count_user_posts() throws,
		// to avoid leaking the switched context into the rest of the request.
		restore_current_blog();
	}

	$sources['main_posts'] = (float) $count * 10;
	return $sources;
}
add_filter( 'ec_points_sources', 'ec_users_main_posts_points_source', 10, 2 );
