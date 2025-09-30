<?php

/**
 * Calculate total points for user across multiple point sources with caching
 *
 * Calculates comprehensive user ranking points from bbPress activity (topics/replies),
 * upvotes received, main site posts, and follower count. Implements 1-hour caching
 * to optimize performance.
 *
 * Point calculation breakdown:
 * - bbPress topics/replies: 2 points each
 * - Upvotes received: 0.5 points each
 * - Main site posts: 10 points each
 * - Followers: Currently 0 points (reserved for future)
 *
 * @param int $user_id WordPress user ID
 * @return float Total calculated points for the user
 */
function extrachill_get_user_total_points($user_id) {
    // Check if total points are cached
    $cached_total_points = get_transient('user_points_' . $user_id);
    if (false !== $cached_total_points) {
        // Update user meta just in case it was missed, but return cached value
        update_user_meta($user_id, 'extrachill_total_points', $cached_total_points);
        return $cached_total_points;
    }

    // --- Calculate points if not cached ---

    // Get topic count (cached)
    $topic_count = false; // Initialize
    $topic_count = get_transient('user_topic_count_' . $user_id);
    if (false === $topic_count) {
        $topic_count = intval(bbp_get_user_topic_count($user_id) ?? 0);
        set_transient('user_topic_count_' . $user_id, $topic_count, HOUR_IN_SECONDS); // Cache for 1 hour
    }

    // Get reply count (cached)
    $reply_count = false; // Initialize
    $reply_count = get_transient('user_reply_count_' . $user_id);
    if (false === $reply_count) {
        $reply_count = intval(bbp_get_user_reply_count($user_id) ?? 0);
        set_transient('user_reply_count_' . $user_id, $reply_count, HOUR_IN_SECONDS); // Cache for 1 hour
    }

    $bbpress_points = ($topic_count + $reply_count) * 2;

    // Get total upvotes (assuming extrachill_get_user_total_upvotes handles its own caching or is fast)
    // If extrachill_get_user_total_upvotes is slow, it should also be cached similarly.
    $total_upvotes = extrachill_get_user_total_upvotes($user_id) ?? 0;
    $upvote_points = floatval($total_upvotes) * 0.5;

    $follower_points = 0;

    // Get main site post count for points calculation
    switch_to_blog( get_blog_id_from_url( 'extrachill.com', '/' ) );
    $main_site_post_count = count_user_posts($user_id, 'post', true);
    restore_current_blog();
    $main_site_post_points = $main_site_post_count * 10;

    // Calculate total points
    $total_points = $bbpress_points + $upvote_points + $follower_points + $main_site_post_points;

    // Cache the total points in a transient for 1 hour
    set_transient('user_points_' . $user_id, $total_points, HOUR_IN_SECONDS);
    // Store the total points as user meta for leaderboard sorting / persistent storage
    update_user_meta($user_id, 'extrachill_total_points', $total_points);

    return $total_points;
}


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
    $queue = get_option('extrachill_points_recalculation_queue', array());
    $queue[$user_id] = true;
    update_option('extrachill_points_recalculation_queue', $queue);
}

// Schedule the processing (if not already scheduled)
if (!wp_next_scheduled('extrachill_daily_points_recalculation')) {
    wp_schedule_event(time(), 'hourly', 'extrachill_daily_points_recalculation');
}

add_action('extrachill_daily_points_recalculation', 'extrachill_process_points_recalculation_queue');

/**
 * Process queued user point recalculations via WP Cron
 *
 * Processes all users in the recalculation queue and clears queue after completion.
 * Triggered hourly by WP Cron event 'extrachill_daily_points_recalculation'.
 */
function extrachill_process_points_recalculation_queue() {
    $queue = get_option('extrachill_points_recalculation_queue', array());

    foreach (array_keys($queue) as $user_id) {
        extrachill_get_user_total_points($user_id);
        // Remove the user from the queue after processing
        unset($queue[$user_id]);
    }

    // Update the queue after processing all users
    update_option('extrachill_points_recalculation_queue', $queue);
}

// Hook the queueing functions to bbPress actions
add_action('bbp_new_topic', 'extrachill_queue_points_recalculation');
add_action('bbp_new_reply', 'extrachill_queue_points_recalculation');

// Handle upvotes action
add_action('custom_upvote_action', function($post_id, $post_author_id, $upvoted) {
    if ($upvoted) {
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

    if (false !== $cached_points) {
        return $cached_points; // Return cached points if available
    }

    // Retrieve the total points from user meta
    $total_points = get_user_meta($user_id, 'extrachill_total_points', true);

    // If total points is not set or empty, calculate and update it
    if (empty($total_points)) {
        $total_points = extrachill_get_user_total_points($user_id);
        update_user_meta($user_id, 'extrachill_total_points', $total_points);
    }

    return $total_points;
}

// add_action('bbp_theme_after_reply_author_details', 'extrachill_add_rank_and_points_to_reply');

// Asset enqueue moved to inc/core/assets.php for centralized management

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
    $total_points = $current_points + $points;
    update_user_meta($user_id, 'extrachill_total_points', $total_points);
}


