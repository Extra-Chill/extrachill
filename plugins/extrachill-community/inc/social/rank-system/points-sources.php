<?php
/**
 * Community → Rank Engine source contributions.
 *
 * extrachill-community is now a CONSUMER of the points engine owned by
 * extrachill-users (inc/rank-system/points-engine.php). This file is where
 * community registers its bbPress-specific sources with the engine:
 *
 *   - SCALAR points (via `ec_points_sources`):
 *       forum topics   (x2 each)
 *       forum replies  (x2 each)
 *       upvotes received (x0.5 each)
 *
 *   - DATED events (via `ec_contribution_events`):
 *       forum topics + replies aggregated by post_date
 *       (upvotes are EXCLUDED — they are a scalar counter with no per-day
 *       timestamp trail; see extrachill-community#147 non-goals)
 *
 * The sub-source count transients (`user_topic_count_{id}`,
 * `user_reply_count_{id}`) are preserved here so the cache-busting contract
 * with inc/core/cache-invalidation.php stays intact.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Scalar point sources ────────────────────────────────────────────────────

/**
 * Contribute bbPress forum engagement points to the rank engine.
 *
 * Hooks the engine's `ec_points_sources` filter. Topic and reply counts are
 * cached in the existing per-user sub-transients so cache-invalidation.php can
 * continue busting them on bbPress lifecycle events. Upvotes read the
 * incrementally-maintained `extrachill_upvotes_received` counter.
 *
 * @param array $sources Source-id => points map.
 * @param int   $user_id WordPress user ID.
 * @return array
 */
function extrachill_community_forum_points_sources( $sources, $user_id ) {
	// Topic count (cached sub-transient, busted by cache-invalidation.php).
	$topic_count = get_transient( 'user_topic_count_' . $user_id );
	if ( false === $topic_count ) {
		$topic_count = intval( bbp_get_user_topic_count( $user_id ) ?? 0 );
		set_transient( 'user_topic_count_' . $user_id, $topic_count, HOUR_IN_SECONDS );
	}

	// Reply count (cached sub-transient, busted by cache-invalidation.php).
	$reply_count = get_transient( 'user_reply_count_' . $user_id );
	if ( false === $reply_count ) {
		$reply_count = intval( bbp_get_user_reply_count( $user_id ) ?? 0 );
		set_transient( 'user_reply_count_' . $user_id, $reply_count, HOUR_IN_SECONDS );
	}

	$sources['forum_topics']  = (float) ( $topic_count * 2 );
	$sources['forum_replies'] = (float) ( $reply_count * 2 );

	if ( function_exists( 'extrachill_get_user_total_upvotes' ) ) {
		$sources['forum_upvotes'] = floatval( extrachill_get_user_total_upvotes( $user_id ) ) * 0.5;
	}

	return $sources;
}
add_filter( 'ec_points_sources', 'extrachill_community_forum_points_sources', 10, 2 );

// ─── Dated contribution events ───────────────────────────────────────────────

/**
 * Contribute dated forum activity events to the contribution seam.
 *
 * SELECTs the UTC-authoritative `post_date_gmt` column for the user's published
 * topics + replies, then delegates day computation to the shared
 * ec_bucket_utc_events_by_local_day() helper in extrachill-users. Upvotes are
 * excluded — they have no per-day timestamp trail (see
 * extrachill-community#147).
 *
 * This contributor runs in the community blog context (community is active on
 * community.extrachill.com where bbPress content lives), so $wpdb->posts is the
 * community blog's posts table. No switch_to_blog needed.
 *
 * The helper lives in extrachill-users; if that plugin is not loaded, this
 * contributor returns $events unchanged (graceful-guard pattern, consistent
 * with the engine-call guards elsewhere).
 *
 * @param array  $events    Running event list.
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
 * @return array
 */
function extrachill_community_forum_contribution_events( $events, $user_id, $since_ymd ) {
	// The shared day-bucketing helper lives in extrachill-users. Guard so the
	// contributor no-ops gracefully if the engine is not loaded.
	if ( ! function_exists( 'ec_bucket_utc_events_by_local_day' ) ) {
		return $events;
	}

	global $wpdb;

	$topic_type = bbp_get_topic_post_type();
	$reply_type = bbp_get_reply_post_type();

	// bbPress post-type slugs are sanitize_key'd; safe for a direct IN list.
	$types_csv = "'" . implode( "','", array_map( 'esc_sql', array( $topic_type, $reply_type ) ) ) . "'";

	// SELECT the UTC-authoritative column; day computation is centralized in
	// ec_bucket_utc_events_by_local_day().
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $types_csv is esc_sql'd from trusted bbPress post-type slugs.
	$utc_timestamps = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_date_gmt
			 FROM {$wpdb->posts}
			 WHERE post_author = %d AND post_type IN ({$types_csv})
			   AND post_status = 'publish'",
			$user_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! is_array( $utc_timestamps ) || empty( $utc_timestamps ) ) {
		return $events;
	}

	return array_merge(
		$events,
		ec_bucket_utc_events_by_local_day( $utc_timestamps, 'forum', $since_ymd )
	);
}
add_filter( 'ec_contribution_events', 'extrachill_community_forum_contribution_events', 10, 3 );
