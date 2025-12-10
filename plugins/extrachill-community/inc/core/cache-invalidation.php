<?php
/**
 * Cache Invalidation
 *
 * Resets bbPress/forum-related caches, user point transients, and edge caches
 * whenever topics or replies are created, edited, trashed, or deleted.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
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

    foreach ($events as $event) {
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
    if (empty($post_id)) {
        return;
    }

    $post_type = get_post_type($post_id);

    if ($post_type !== bbp_get_topic_post_type() && $post_type !== bbp_get_reply_post_type()) {
        return;
    }

    $topic_id = ($post_type === bbp_get_topic_post_type()) ? $post_id : bbp_get_reply_topic_id($post_id);
    $reply_id = ($post_type === bbp_get_reply_post_type()) ? $post_id : 0;
    $forum_id = $topic_id ? bbp_get_topic_forum_id($topic_id) : 0;

    extrachill_delete_leaderboard_cache();

    // Determine affected users (author + last poster if available).
    $user_ids = array();
    $author_id = get_post_field('post_author', $post_id);
    if ($author_id) {
        $user_ids[] = (int) $author_id;
    }

    if ($reply_id) {
        $reply_author = bbp_get_reply_author_id($reply_id);
        if ($reply_author) {
            $user_ids[] = (int) $reply_author;
        }
    } else {
        $topic_author = bbp_get_topic_author_id($topic_id);
        if ($topic_author) {
            $user_ids[] = (int) $topic_author;
        }
    }

    $user_ids = array_unique(array_filter($user_ids));

    extrachill_clear_user_points_cache($user_ids);
    extrachill_clear_forum_related_transients($topic_id, $forum_id);
    extrachill_purge_forum_edge_cache($topic_id, $forum_id);
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
 * Clear user-related point transients and cached totals.
 *
 * @param array $user_ids List of user IDs.
 */
function extrachill_clear_user_points_cache($user_ids) {
    if (empty($user_ids)) {
        return;
    }

    foreach ($user_ids as $user_id) {
        delete_transient('user_topic_count_' . $user_id);
        delete_transient('user_reply_count_' . $user_id);
        delete_transient('user_points_' . $user_id);
    }

    extrachill_recalculate_user_points($user_ids);
}

/**
 * Recalculate total points immediately after cache purge.
 *
 * @param array $user_ids List of user IDs.
 */
function extrachill_recalculate_user_points($user_ids) {
    if (empty($user_ids) || !function_exists('extrachill_get_user_total_points')) {
        return;
    }

    foreach ($user_ids as $user_id) {
        extrachill_get_user_total_points($user_id);
    }
}

/**
 * Clear forum/topic transients and recent feed caches.
 *
 * @param int $topic_id Topic ID.
 * @param int $forum_id Forum ID.
 */
function extrachill_clear_forum_related_transients($topic_id, $forum_id) {
    $keys = array(
        'extrachill_recent_feed',
        'extrachill_recent_feed_pagination',
        'extrachill_recent_topics',
        'extrachill_homepage_forums',
        'extrachill_forum_stats',
    );

    if ($forum_id) {
        $keys[] = 'extrachill_forum_stats_' . $forum_id;
        $keys[] = 'extrachill_forum_topics_' . $forum_id;
    }

    if ($topic_id) {
        $keys[] = 'extrachill_topic_reply_ids_' . $topic_id;
        $keys[] = 'extrachill_topic_stats_' . $topic_id;
    }

    foreach ($keys as $key) {
        delete_transient($key);
        wp_cache_delete($key, 'extrachill_community');
    }
}

/**
 * Purge Breeze/Varnish caches for affected URLs when available.
 *
 * @param int $topic_id Topic ID.
 * @param int $forum_id Forum ID.
 */
function extrachill_purge_forum_edge_cache($topic_id, $forum_id) {
    $urls = array();

    if ($forum_id) {
        $urls[] = bbp_get_forum_permalink($forum_id);
    }

    if ($topic_id) {
        $urls[] = bbp_get_topic_permalink($topic_id);
    }

    $urls[] = home_url('/community');
    $urls[] = home_url('/recent');

    $urls = array_filter(array_unique($urls));

    foreach ($urls as $url) {
        if (function_exists('breeze_purge_url')) {
            breeze_purge_url($url);
        }

        if (has_action('breeze_purge_url')) {
            do_action('breeze_purge_url', $url);
        }
    }

    if (function_exists('breeze_purge_cache')) {
        breeze_purge_cache();
    }
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
    if (empty($forum_id) || !function_exists('bbp_update_forum_last_active_time')) {
        return;
    }

    $current_forum_id = $forum_id;
    
    // Walk up the forum hierarchy
    while ($current_forum_id) {
        // Update this forum's last active time
        bbp_update_forum_last_active_time($current_forum_id);
        
        // Get the parent forum ID
        $parent_id = wp_get_post_parent_id($current_forum_id);
        
        // Stop if we've reached the top level (no parent) or if parent is the same (loop prevention)
        if ($parent_id === $current_forum_id || $parent_id === 0) {
            break;
        }
        
        $current_forum_id = $parent_id;
    }
}
