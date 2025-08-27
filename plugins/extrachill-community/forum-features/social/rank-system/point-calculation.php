<?php

// Function to calculate total points for a user, including main site comment points + articles
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
    update_user_meta($user_id, 'extrachill_total_points', $total_points);

    return $total_points;
}


// Queue user points recalculation on new replies and topics
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

// Process the queue, ideally triggered by a WP Cron event
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




// Display user points
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

// Enqueue admin scripts for AJAX functionality
function extrachill_admin_enqueue_scripts($hook) {
    if ('user-edit.php' !== $hook && 'profile.php' !== $hook) {
        return;
    }
    wp_enqueue_script('extrachill_admin_js', get_stylesheet_directory_uri() . '/forum-features/social/rank-system/js/extrachill_admin.js', array('jquery'), null, true);
    wp_localize_script('extrachill_admin_js', 'extraChillAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_recalculate_points_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'extrachill_admin_enqueue_scripts');

// AJAX handler for recalculating points
function extrachill_recalculate_points() {
    check_ajax_referer('extrachill_recalculate_points_nonce', 'nonce');
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$user_id) {
        wp_send_json_error('Invalid user ID');
    }

    // Recalculate points
    $total_points = extrachill_get_user_total_points($user_id);
    
    wp_send_json_success(array('total_points' => $total_points));
}
add_action('wp_ajax_extrachill_recalculate_points', 'extrachill_recalculate_points');

// Add the recalculate points button to the user profile page
function extrachill_add_recalculate_points_button($user) {
    if (!is_admin()) {
        return;
    }
    ?>
    <h3><?php _e("Recalculate Points", "extra-chill-community"); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="recalculate_points"><?php _e("Recalculate Points", "extra-chill-community"); ?></label></th>
            <td>
                <button type="button" class="button" id="extrachill_recalculate_points" data-user-id="<?php echo $user->ID; ?>">
                    <?php _e("Recalculate Points", "extra-chill-community"); ?>
                </button>
                <span id="extrachill_recalculate_points_result"></span>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'extrachill_add_recalculate_points_button');
add_action('edit_user_profile', 'extrachill_add_recalculate_points_button');

function extrachill_increment_user_points($user_id, $points) {
    // Retrieve current points and ensure it's treated as a float
    $current_points = floatval(get_user_meta($user_id, 'extrachill_total_points', true) ?? 0);
    $total_points = $current_points + $points;
    update_user_meta($user_id, 'extrachill_total_points', $total_points);
}


