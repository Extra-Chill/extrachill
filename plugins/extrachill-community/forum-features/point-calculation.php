<?php

// Function to calculate total points for a user, including main site comment points + articles
function wp_surgeon_get_user_total_points($user_id) {
    // Check if total points are cached
    $cached_total_points = get_transient('user_points_' . $user_id);
    if (false !== $cached_total_points) {
        // Update user meta just in case it was missed, but return cached value
        update_user_meta($user_id, 'wp_surgeon_total_points', $cached_total_points);
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

    // Get total upvotes (assuming wp_surgeon_get_user_total_upvotes handles its own caching or is fast)
    // If wp_surgeon_get_user_total_upvotes is slow, it should also be cached similarly.
    $total_upvotes = wp_surgeon_get_user_total_upvotes($user_id) ?? 0;
    $upvote_points = floatval($total_upvotes) * 0.5;

    // Get follower count (user meta is generally fast, but could be cached if needed)
    // -- REMOVED FOLLOWER POINTS --
    /*
    $followers = get_user_meta($user_id, 'extrachill_followers', true);
    $follower_count = is_array($followers) ? count($followers) : 0;
    $follower_points = $follower_count * 3;
    */
    $follower_points = 0; // Ensure variable exists, set to 0

    // Get main site post count (cached)
    $author_id = false; // Initialize
    $author_id = get_transient('user_author_id_' . $user_id);
    if (false === $author_id) {
        $author_id = convert_community_user_id_to_author_id($user_id);
        // Cache even if null to avoid repeated checks
        set_transient('user_author_id_' . $user_id, $author_id, HOUR_IN_SECONDS); // Cache for 1 hour
    }

    $main_site_post_count = 0; // Initialize default value
    if ($author_id !== null) {
        $main_site_post_count = false; // Initialize before transient check
        $main_site_post_count = get_transient('user_main_site_post_count_' . $user_id);
        if (false === $main_site_post_count) {
            $main_site_post_count = intval(fetch_main_site_post_count_for_user($author_id) ?? 0);
            set_transient('user_main_site_post_count_' . $user_id, $main_site_post_count, HOUR_IN_SECONDS); // Cache for 1 hour
        }
    }
    $main_site_post_points = $main_site_post_count * 10;

    // Calculate total points
    $total_points = $bbpress_points + $upvote_points + $follower_points + $main_site_post_points;

    // Cache the total points in a transient for 1 hour
    set_transient('user_points_' . $user_id, $total_points, HOUR_IN_SECONDS);
    // Store the total points as user meta for leaderboard sorting / persistent storage
    update_user_meta($user_id, 'wp_surgeon_total_points', $total_points);

    return $total_points;
}


// Queue user points recalculation on new replies and topics
function wp_surgeon_queue_points_recalculation($post_id) {
    $user_id = bbp_is_reply($post_id) ? bbp_get_reply_author_id($post_id) : bbp_get_topic_author_id($post_id);
    // Add the user_id to a queue for later processing
    $queue = get_option('wp_surgeon_points_recalculation_queue', array());
    $queue[$user_id] = true;
    update_option('wp_surgeon_points_recalculation_queue', $queue);
}

// Schedule the processing (if not already scheduled)
if (!wp_next_scheduled('wp_surgeon_daily_points_recalculation')) {
    wp_schedule_event(time(), 'hourly', 'wp_surgeon_daily_points_recalculation');
}

add_action('wp_surgeon_daily_points_recalculation', 'wp_surgeon_process_points_recalculation_queue');

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

// Hook the queueing functions to bbPress actions
add_action('bbp_new_topic', 'wp_surgeon_queue_points_recalculation');
add_action('bbp_new_reply', 'wp_surgeon_queue_points_recalculation');

// Handle upvotes action
add_action('custom_upvote_action', function($post_id, $post_author_id, $upvoted) {
    if ($upvoted) {
        // Upvote added, increment points
        wp_surgeon_increment_user_points($post_author_id, 0.5);
    } else {
        // Upvote removed, decrement points
        wp_surgeon_increment_user_points($post_author_id, -0.5);
    }
}, 10, 3);



// Handle follow and unfollow actions
// -- REMOVED FOLLOW/UNFOLLOW HOOKS --
/*
add_action('extrachill_followed_user', function($follower_id, $followed_id) {
    wp_surgeon_get_user_total_points($follower_id);
    wp_surgeon_get_user_total_points($followed_id);
}, 10, 2);

add_action('extrachill_unfollowed_user', function($follower_id, $followed_id) {
    wp_surgeon_get_user_total_points($follower_id);
    wp_surgeon_get_user_total_points($followed_id);
}, 10, 2);
*/

// Display user points
function wp_surgeon_display_user_points($user_id) {
    // Check if user points are already cached in a transient
    $cached_points = get_transient('user_points_' . $user_id);

    if (false !== $cached_points) {
        return $cached_points; // Return cached points if available
    }

    // Retrieve the total points from user meta
    $total_points = get_user_meta($user_id, 'wp_surgeon_total_points', true);

    // If total points is not set or empty, calculate and update it
    if (empty($total_points)) {
        $total_points = wp_surgeon_get_user_total_points($user_id);
        update_user_meta($user_id, 'wp_surgeon_total_points', $total_points);
    }

    return $total_points;
}

// add_action('bbp_theme_after_reply_author_details', 'wp_surgeon_add_rank_and_points_to_reply');

// Enqueue admin scripts for AJAX functionality
function wp_surgeon_admin_enqueue_scripts($hook) {
    if ('user-edit.php' !== $hook && 'profile.php' !== $hook) {
        return;
    }
    wp_enqueue_script('wp_surgeon_admin_js', get_stylesheet_directory_uri() . '/js/wp_surgeon_admin.js', array('jquery'), null, true);
    wp_localize_script('wp_surgeon_admin_js', 'wpSurgeonAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp_surgeon_recalculate_points_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'wp_surgeon_admin_enqueue_scripts');

// AJAX handler for recalculating points
function wp_surgeon_recalculate_points() {
    check_ajax_referer('wp_surgeon_recalculate_points_nonce', 'nonce');
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$user_id) {
        wp_send_json_error('Invalid user ID');
    }

    // Recalculate points
    $total_points = wp_surgeon_get_user_total_points($user_id);
    
    wp_send_json_success(array('total_points' => $total_points));
}
add_action('wp_ajax_wp_surgeon_recalculate_points', 'wp_surgeon_recalculate_points');

// Add the recalculate points button to the user profile page
function wp_surgeon_add_recalculate_points_button($user) {
    if (!is_admin()) {
        return;
    }
    ?>
    <h3><?php _e("Recalculate Points", "wp_surgeon"); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="recalculate_points"><?php _e("Recalculate Points", "wp_surgeon"); ?></label></th>
            <td>
                <button type="button" class="button" id="wp_surgeon_recalculate_points" data-user-id="<?php echo $user->ID; ?>">
                    <?php _e("Recalculate Points", "wp_surgeon"); ?>
                </button>
                <span id="wp_surgeon_recalculate_points_result"></span>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'wp_surgeon_add_recalculate_points_button');
add_action('edit_user_profile', 'wp_surgeon_add_recalculate_points_button');

function wp_surgeon_increment_user_points($user_id, $points) {
    // Retrieve current points and ensure it's treated as a float
    $current_points = floatval(get_user_meta($user_id, 'wp_surgeon_total_points', true) ?? 0);
    $total_points = $current_points + $points;
    update_user_meta($user_id, 'wp_surgeon_total_points', $total_points);
}


