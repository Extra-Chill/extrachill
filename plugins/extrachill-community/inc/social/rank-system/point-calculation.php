<?php
/**
 * Rank Points — community-side display, queue, and leaderboard helpers.
 *
 * The points ENGINE (the full compute + cache path) has been PROMOTED to
 * extrachill-users (inc/rank-system/points-engine.php) as the network-wide
 * single source of truth — see Extra-Chill/extrachill-users#165. This file now
 * holds only the community-side concerns:
 *
 *   - `extrachill_display_user_points()`  — read wrapper with lazy recompute
 *     (calls the users-owned engine, guarded for graceful degradation)
 *   - `extrachill_increment_user_points()` — real-time upvote delta on the
 *     `extrachill_total_points` meta convention
 *   - `extrachill_queue_points_recalculation()` / process queue — bbPress-
 *     triggered deferred recompute
 *   - `extrachill_get_leaderboard_users()` / total — leaderboard user queries
 *
 * Community's bbPress point SOURCES are contributed to the engine via
 * inc/social/rank-system/points-sources.php (hooks `ec_points_sources`).
 *
 * Storage conventions (owned by the engine, unchanged):
 *   - User meta key: `extrachill_total_points`
 *   - Total transient: `user_points_{id}` (1-hour TTL)
 *
 * @package ExtraChillCommunity
 */

/**
 * Queue user for points recalculation after bbPress activity
 *
 * Adds user to recalculation queue when they create topics or replies.
 * Queue is processed hourly via WP Cron for performance optimization.
 *
 * @param int $post_id bbPress topic or reply post ID
 */
function extrachill_queue_points_recalculation($post_id) {
	$user_id = bbp_is_reply($post_id) ? bbp_get_reply_author_id($post_id) : bbp_get_topic_author_id($post_id);
	// Add the user_id to a queue for later processing
	$queue             = get_option('extrachill_points_recalculation_queue', array());
	$queue[ $user_id ] = true;
	update_option('extrachill_points_recalculation_queue', $queue);
}

/**
 * Retrieve leaderboard users with caching.
 *
 * @param int $items_per_page Number per page.
 * @param int $offset Offset for pagination.
 * @return array
 */
function extrachill_get_leaderboard_users($items_per_page = 25, $offset = 0) {
	$cache_key = 'extrachill_leaderboard_users';
	$cache     = get_transient($cache_key);

	if ( $cache && isset($cache['items'][ $items_per_page ][ $offset ]) ) {
		return $cache['items'][ $items_per_page ][ $offset ];
	}

	$args = array(
		'orderby'  => 'meta_value_num',
		'meta_key' => 'extrachill_total_points',
		'order'    => 'DESC',
		'number'   => $items_per_page,
		'offset'   => $offset,
	);

	$user_query = new WP_User_Query($args);
	$results    = $user_query->get_results();

	if ( ! $cache ) {
		$cache = array(
			'items' => array(),
		);
	}

	if ( ! isset($cache['items'][ $items_per_page ]) ) {
		$cache['items'][ $items_per_page ] = array();
	}

	$cache['items'][ $items_per_page ][ $offset ] = $results;
	set_transient($cache_key, $cache, MINUTE_IN_SECONDS * 5);

	return $results;
}

/**
 * Get total leaderboard users count with caching.
 *
 * @return int
 */
function extrachill_get_leaderboard_total_users() {
	$cache_key = 'extrachill_leaderboard_total_users';
	$total     = get_transient($cache_key);

	if ( false !== $total ) {
		return (int) $total;
	}

	$user_query = new WP_User_Query(array(
		'orderby'  => 'meta_value_num',
		'meta_key' => 'extrachill_total_points',
		'fields'   => 'ID',
	));

	$total = $user_query->get_total();
	set_transient($cache_key, $total, MINUTE_IN_SECONDS * 5);

	return (int) $total;
}

// The 'extrachill_hourly_points_recalculation' cron event is scheduled on plugin
// activation and cleared on deactivation (see inc/core/activation.php). Scheduling
// no longer happens at include time, which previously left an orphaned recurring
// cron firing against a missing callback after the plugin was deactivated.
add_action('extrachill_hourly_points_recalculation', 'extrachill_process_points_recalculation_queue');

/**
 * Process queued user point recalculations via WP Cron
 *
 * Processes all users in the recalculation queue and clears queue after completion.
 * Triggered hourly by WP Cron event 'extrachill_daily_points_recalculation'.
 */
function extrachill_process_points_recalculation_queue() {
	// The engine (extrachill_get_user_total_points) lives in extrachill-users.
	// Guard so the queue no-ops gracefully if that plugin is not loaded.
	if ( ! function_exists( 'extrachill_get_user_total_points' ) ) {
		return;
	}

	$queue = get_option('extrachill_points_recalculation_queue', array());

	foreach ( array_keys($queue) as $user_id ) {
		extrachill_get_user_total_points($user_id);
		// Remove the user from the queue after processing
		unset($queue[ $user_id ]);
	}

	// Update the queue after processing all users
	update_option('extrachill_points_recalculation_queue', $queue);
}

// Hook the queueing functions to bbPress actions
add_action('bbp_new_topic', 'extrachill_queue_points_recalculation');
add_action('bbp_new_reply', 'extrachill_queue_points_recalculation');

// Handle upvotes action
add_action('custom_upvote_action', function($post_id, $post_author_id, $upvoted) {
	if ( $upvoted ) {
		// Upvote added, increment points
		extrachill_increment_user_points($post_author_id, 0.5);
	} else {
		// Upvote removed, decrement points
		extrachill_increment_user_points($post_author_id, -0.5);
	}
}, 10, 3);




/**
 * Display user points with caching fallback
 *
 * Returns cached points if available, otherwise retrieves from user meta.
 * If user meta is empty, triggers full point recalculation.
 *
 * @param int $user_id WordPress user ID
 * @return float User's total points
 */
function extrachill_display_user_points($user_id) {
	// Check if user points are already cached in a transient
	$cached_points = get_transient('user_points_' . $user_id);

	if ( false !== $cached_points ) {
		return $cached_points; // Return cached points if available
	}

	// Retrieve the total points from user meta
	$total_points = get_user_meta($user_id, 'extrachill_total_points', true);

	// If total points is not set or empty, calculate and update it via the
	// users-owned engine. Guard so the profile renders cleanly (returns 0) if
	// the engine is not loaded — matches the function_exists() pattern used
	// for ec_get_last_seen() / ec_get_rank_progress() in bbpress/user-details.php.
	if ( empty($total_points) ) {
		if ( function_exists( 'extrachill_get_user_total_points' ) ) {
			$total_points = extrachill_get_user_total_points($user_id);
			update_user_meta($user_id, 'extrachill_total_points', $total_points);
		} else {
			$total_points = 0;
		}
	}

	return $total_points;
}



/**
 * Increment or decrement user points by specified amount
 *
 * Updates user meta for extrachill_total_points by adding/subtracting points.
 * Used for real-time upvote adjustments.
 *
 * @param int   $user_id WordPress user ID
 * @param float $points  Points to add (positive) or subtract (negative)
 */
function extrachill_increment_user_points($user_id, $points) {
	// Retrieve current points and ensure it's treated as a float
	$current_points = floatval(get_user_meta($user_id, 'extrachill_total_points', true) ?? 0);
	$total_points   = $current_points + $points;
	update_user_meta($user_id, 'extrachill_total_points', $total_points);
}
