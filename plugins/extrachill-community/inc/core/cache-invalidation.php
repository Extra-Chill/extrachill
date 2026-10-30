<?php
/**
 * Cache Invalidation
 *
 * Resets bbPress/forum-related caches, user point transients, and edge caches
 * whenever topics or replies are created, edited, trashed, or deleted.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Register cache invalidation hooks for bbPress lifecycle.
 */
function extrachill_register_forum_cache_invalidation() {
	$events = array(
		'bbp_new_topic',
		'bbp_new_reply',
		'bbp_edit_topic',
		'bbp_edit_reply',
		'bbp_trash_topic',
		'bbp_trash_reply',
		'bbp_untrash_topic',
		'bbp_delete_topic',
		'bbp_delete_reply',
		'bbp_approve_topic',
		'bbp_approve_reply',
		'bbp_unapprove_topic',
		'bbp_unapprove_reply',
	);

	foreach ( $events as $event ) {
		add_action($event, 'extrachill_handle_forum_cache_invalidation', 999, 1);
	}
}
add_action('plugins_loaded', 'extrachill_register_forum_cache_invalidation', 20);

/**
 * Main handler for cache invalidation events.
 *
 * @param int $post_id Topic or reply post ID.
 */
function extrachill_handle_forum_cache_invalidation($post_id) {
	if ( empty($post_id) ) {
		return;
	}

	$post_type = get_post_type($post_id);

	if ( bbp_get_topic_post_type() !== $post_type && bbp_get_reply_post_type() !== $post_type ) {
		return;
	}

	$topic_id = ( bbp_get_topic_post_type() === $post_type ) ? $post_id : bbp_get_reply_topic_id($post_id);
	$reply_id = ( bbp_get_reply_post_type() === $post_type ) ? $post_id : 0;
	$forum_id = $topic_id ? bbp_get_topic_forum_id($topic_id) : 0;

	extrachill_delete_leaderboard_cache();

	// Determine affected users (author + last poster if available).
	$user_ids  = array();
	$author_id = get_post_field('post_author', $post_id);
	if ( $author_id ) {
		$user_ids[] = (int) $author_id;
	}

	if ( $reply_id ) {
		$reply_author = bbp_get_reply_author_id($reply_id);
		if ( $reply_author ) {
			$user_ids[] = (int) $reply_author;
		}
	} else {
		$topic_author = bbp_get_topic_author_id($topic_id);
		if ( $topic_author ) {
			$user_ids[] = (int) $topic_author;
		}
	}

	$user_ids = array_unique(array_filter($user_ids));

	extrachill_clear_user_points_cache($user_ids);
	extrachill_update_parent_forum_last_active_times($forum_id);
}

/**
 * Delete leaderboard cache transients to keep rankings fresh.
 */
function extrachill_delete_leaderboard_cache() {
	delete_transient('extrachill_leaderboard_users');
	delete_transient('extrachill_leaderboard_total_users');
}

/**
 * Clear user-related point transients and queue a deferred recalculation.
 *
 * Busts the per-user count/points transients so the next read recomputes a
 * fresh total, and enqueues the affected users for the hourly cron recompute
 * (extrachill_process_points_recalculation_queue). It deliberately does NOT
 * recompute inline: the full recompute walks O(author lifetime post count)
 * rows and previously ran synchronously inside the request that posted/edited
 * a topic or reply, making the cost of a write grow with the author's history.
 *
 * Display falls back to a lazy recompute-on-cache-miss (see
 * extrachill_display_user_points()), and upvote totals are now maintained
 * incrementally, so the eager inline recompute is redundant.
 *
 * @param array $user_ids List of user IDs.
 */
function extrachill_clear_user_points_cache($user_ids) {
	if ( empty($user_ids) ) {
		return;
	}

	foreach ( $user_ids as $user_id ) {
		delete_transient('user_topic_count_' . $user_id);
		delete_transient('user_reply_count_' . $user_id);
		delete_transient('user_points_' . $user_id);
		// Bust the contribution-calendar cache so the heatmap + streaks stay
		// fresh on new forum activity (see contribution-calendar-ability.php).
		delete_transient('ec_contrib_calendar_' . $user_id);
	}

	extrachill_queue_user_points_recalculation($user_ids);
}

/**
 * Queue affected users for the hourly deferred points recalculation.
 *
 * Mirrors extrachill_queue_points_recalculation() (which queues by post ID)
 * but accepts already-resolved user IDs from the cache-invalidation path.
 * The queue is drained by extrachill_process_points_recalculation_queue() on
 * the hourly cron event.
 *
 * @param array $user_ids List of user IDs.
 */
function extrachill_queue_user_points_recalculation($user_ids) {
	if ( empty($user_ids) ) {
		return;
	}

	$queue = get_option('extrachill_points_recalculation_queue', array());

	foreach ( $user_ids as $user_id ) {
		$queue[ (int) $user_id ] = true;
	}

	update_option('extrachill_points_recalculation_queue', $queue);
}

/**
 * Register targeted forum/topic edge cache invalidation with extrachill-cache.
 *
 * Runs on plugins_loaded so bbPress helper functions are guaranteed to
 * exist by the time the filter fires later (transition_post_status /
 * before_delete_post happen well after plugin bootstrap).
 */
add_action('plugins_loaded', function () {
	add_filter('extrachill_cache_post_change_urls', 'extrachill_community_cache_invalidation_urls', 10, 3);
});

/**
 * Return forum pages affected by a bbPress topic/reply/forum change.
 *
 * Keeps bbPress-specific URL knowledge out of the generic cache layer,
 * mirroring the extrachill-events integration pattern
 * (extrachill-events/inc/core/page-cache.php).
 *
 * @param null|array $urls      URLs supplied by another integration.
 * @param int        $post_id   Changed post ID.
 * @param string     $post_type Changed post type.
 * @return null|array Exact URLs to invalidate, or the incoming value untouched.
 */
function extrachill_community_cache_invalidation_urls($urls, $post_id, $post_type) {
	if ( ! function_exists('bbp_get_topic_post_type') ) {
		return $urls;
	}

	$topic_post_type = bbp_get_topic_post_type();
	$reply_post_type = bbp_get_reply_post_type();
	$forum_post_type = bbp_get_forum_post_type();

	if ( ! in_array($post_type, array( $topic_post_type, $reply_post_type, $forum_post_type ), true) ) {
		return $urls;
	}

	if ( $reply_post_type === $post_type ) {
		$topic_id = bbp_get_reply_topic_id($post_id);
	} elseif ( $topic_post_type === $post_type ) {
		$topic_id = $post_id;
	} else {
		$topic_id = 0;
	}

	$forum_id = 0;
	if ( $forum_post_type === $post_type ) {
		$forum_id = $post_id;
	} elseif ( $topic_id ) {
		$forum_id = bbp_get_topic_forum_id($topic_id);
	}

	$edge_urls = array();

	if ( $forum_id ) {
		$forum_url = bbp_get_forum_permalink($forum_id);
		if ( $forum_url ) {
			$edge_urls[] = $forum_url;
		}
	}

	if ( $topic_id ) {
		$topic_url = bbp_get_topic_permalink($topic_id);
		if ( $topic_url ) {
			$edge_urls[] = $topic_url;
		}
	}

	$edge_urls[] = home_url('/community');
	$edge_urls[] = home_url('/recent');

	return array_values(array_unique(array_filter($edge_urls)));
}

/**
 * Update parent forum last active times when subforum activity occurs.
 *
 * When a topic/reply is created in a subforum, walks up the forum hierarchy
 * and updates the _bbp_last_active_time meta field for all parent forums.
 *
 * @param int $forum_id The forum ID where activity occurred.
 */
function extrachill_update_parent_forum_last_active_times($forum_id) {
	if ( empty($forum_id) || ! function_exists('bbp_update_forum_last_active_time') ) {
		return;
	}

	$current_forum_id = $forum_id;

	// Walk up the forum hierarchy
	while ( $current_forum_id ) {
		// Update this forum's last active time
		bbp_update_forum_last_active_time($current_forum_id);

		// Get the parent forum ID
		$parent_id = wp_get_post_parent_id($current_forum_id);

		// Stop if we've reached the top level (no parent) or if parent is the same (loop prevention)
		if ( $parent_id === $current_forum_id || 0 === $parent_id ) {
			break;
		}

		$current_forum_id = $parent_id;
	}
}
