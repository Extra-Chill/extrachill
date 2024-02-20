<?php

// Function to calculate total points for a user, including main site comment points + articles
function wp_surgeon_get_user_total_points($user_id) {
    // Get the user's topic count from bbPress
    $topic_count = bbp_get_user_topic_count($user_id); // Assumed to return raw count

    // Get the user's reply count from bbPress
    $reply_count = bbp_get_user_reply_count($user_id); // Assumed to return raw count

    // Get the count of comments made on the main site
    $main_site_comment_count = get_main_site_comment_count_for_user($user_id);

    // Main site comments are worth 2 points each
    $main_site_comment_points = $main_site_comment_count * 2;

    // Calculate the total points from bbPress activities and main site comments
    $bbpress_points = $topic_count + $reply_count;
    
    // Get the total upvotes for the user's posts
    $total_upvotes = wp_surgeon_get_user_total_upvotes($user_id); // This function needs to be defined

    // Retrieve the follower count
    $followers = get_user_meta($user_id, 'extrachill_followers', true);
    $follower_count = is_array($followers) ? count($followers) : 0;

    // Convert community user ID to author ID for fetching main site post count
    $author_id = convert_community_user_id_to_author_id($user_id);
    if ($author_id !== null) {
        $main_site_post_count = fetch_main_site_post_count_for_user($author_id);
    } else {
        $main_site_post_count = 0;
    }

    // Main site posts are worth 5 points each
    $main_site_post_points = $main_site_post_count * 5;

    // Calculate total points including main site posts
    $total_points = $bbpress_points + $main_site_comment_points + $main_site_post_points + ($total_upvotes * 0.25) + ($follower_count * 2);

    // Store the total points as user meta for future retrieval
    update_user_meta($user_id, 'wp_surgeon_total_points', $total_points);

    return $total_points;
}


add_action('bbp_new_topic', 'wp_surgeon_recalculate_points_after_new_topic', 10, 1);
add_action('bbp_new_reply', 'wp_surgeon_recalculate_points_after_new_reply', 10, 1);

function wp_surgeon_recalculate_points_after_new_topic($topic_id) {
    $author_id = bbp_get_topic_author_id($topic_id);
    wp_surgeon_get_user_total_points($author_id);
}

function wp_surgeon_recalculate_points_after_new_reply($reply_id) {
    $author_id = bbp_get_reply_author_id($reply_id);
    wp_surgeon_get_user_total_points($author_id);
}

add_action('custom_upvote_action', function($post_id, $user_id, $upvoted) {
    // Optionally use $upvoted to adjust logic if needed
    wp_surgeon_get_user_total_points($user_id);
}, 10, 3);

add_action('extrachill_followed_user', function($follower_id, $followed_id) {
    // Example check before recalculating points
    if (!did_action('extrachill_followed_user_already_processed')) {
        wp_surgeon_get_user_total_points($follower_id);
        wp_surgeon_get_user_total_points($followed_id);
        do_action('extrachill_followed_user_already_processed');
    }
}, 10, 2);

add_action('extrachill_unfollowed_user', function($follower_id, $followed_id) {
    // Example check before recalculating points
    if (!did_action('extrachill_unfollowed_user_already_processed')) {
        wp_surgeon_get_user_total_points($follower_id);
        wp_surgeon_get_user_total_points($followed_id);
        do_action('extrachill_unfollowed_user_already_processed');
    }
}, 10, 2);

// Queue user points recalculation on new replies and topics
add_action('bbp_new_reply', 'wp_surgeon_queue_points_recalculation');
add_action('bbp_new_topic', 'wp_surgeon_queue_points_recalculation');

function wp_surgeon_queue_points_recalculation($post_id) {
    $user_id = bbp_is_reply($post_id) ? bbp_get_reply_author_id($post_id) : bbp_get_topic_author_id($post_id);
    // Add the user_id to a queue for later processing
    $queue = get_option('wp_surgeon_points_recalculation_queue', array());
    $queue[$user_id] = true;
    update_option('wp_surgeon_points_recalculation_queue', $queue);
}

// Process the queue, ideally triggered by a WP Cron event
function wp_surgeon_process_points_recalculation_queue() {
    $queue = get_option('wp_surgeon_points_recalculation_queue', array());

    foreach (array_keys($queue) as $user_id) {
        wp_surgeon_get_user_total_points($user_id);
        // Remove the user from the queue after processing
        unset($queue[$user_id]);
    }

    // Update the queue after processing all users
    update_option('wp_surgeon_points_recalculation_queue', $queue);
}


// Schedule the processing (if not already scheduled)
if (!wp_next_scheduled('wp_surgeon_daily_points_recalculation')) {
    wp_schedule_event(time(), 'daily', 'wp_surgeon_daily_points_recalculation');
}

add_action('wp_surgeon_daily_points_recalculation', 'wp_surgeon_process_points_recalculation_queue');


function wp_surgeon_display_user_points($user_id) {
    // Retrieve the total points from user meta
    $total_points = get_user_meta($user_id, 'wp_surgeon_total_points', true);

    // If total points is not set or empty, calculate and update it
    if (empty($total_points)) {
        $total_points = wp_surgeon_get_user_total_points($user_id);
        update_user_meta($user_id, 'wp_surgeon_total_points', $total_points);
    }

    return $total_points;
}

add_action('bbp_theme_after_reply_author_details', 'wp_surgeon_add_rank_and_points_to_reply');

function get_main_site_comment_count_for_user($user_id) {
    // Try to get the cached comment count
    $cached_count = get_transient('main_site_comment_count_' . $user_id);
    if ($cached_count !== false) {
        // Cache hit, return the cached count
        return $cached_count;
    }

    // If no cached value, fetch the count from the external source
    $response = wp_remote_get("https://extrachill.com/wp-json/extrachill/v1/user-comments-count/{$user_id}");
    if (is_wp_error($response)) {
        // Default to 0 if unable to fetch
        $comment_count = 0;
    } else {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $comment_count = $data['comment_count'] ?? 0;
    }

    // Cache the count for 24 hours
    set_transient('main_site_comment_count_' . $user_id, $comment_count, DAY_IN_SECONDS);

    return $comment_count;
}


function wp_surgeon_determine_rank_by_points($points) {
    if ($points >= 516246) return 'Frozen Deep Space';
    if ($points >= 344164) return 'Upper Atmosphere';
    if ($points >= 229442) return 'Ice Age';
    if ($points >= 152961) return 'Antarctica';
    if ($points >= 101974) return 'Glacier';
    if ($points >= 67983) return 'Blizzard';
    if ($points >= 45322) return 'Ski Resort';
    if ($points >= 30214) return 'Snowstorm';
    if ($points >= 20143) return 'Flurry';
    if ($points >= 13428) return 'Ice Rink';
    if ($points >= 8952) return 'Frozen Foods Isle';
    if ($points >= 5968) return 'Walk-In Freezer';
    if ($points >= 3978) return 'Ice Machine';
    if ($points >= 2652) return 'Freezer';
    if ($points >= 1768) return 'Fridge';
    if ($points >= 1178) return 'Cooler';
    if ($points >= 785) return 'Ice Maker';
    if ($points >= 523) return 'Bag of Ice';
    if ($points >= 349) return 'Ice Tray';
    if ($points >= 232) return 'Ice Cube';
    if ($points >= 155) return 'Overnight Freeze';
    if ($points >= 103) return 'First Frost';
    if ($points >= 69) return 'Crisp Air';
    if ($points >= 35) return 'Puddle';
    if ($points >= 15) return 'Droplet';
    return 'Dew';
}

function wp_surgeon_display_user_rank($user_id) {
    // Fetch the stored total points from user meta
    $total_points = get_user_meta($user_id, 'wp_surgeon_total_points', true);
    
    // Determine the user's rank based on the total points
    $rank = wp_surgeon_determine_rank_by_points($total_points);
    
    // Return the calculated rank
    return $rank;
}

function wp_surgeon_add_rank_and_points_to_reply() {
    $reply_author_id = bbp_get_reply_author_id();

    // Display Rank using the new function
    echo '<div class="rankpoints"><div class="reply-author-rank">';
    echo '<span>Rank:</span> ' . wp_surgeon_display_user_rank($reply_author_id);
    echo '</div>';

    // Display Points using the existing function for points
    echo '<div class="reply-author-points">';
    echo '<span>Points:</span> ' . wp_surgeon_display_user_points($reply_author_id);
    echo '</div></div>';
}


