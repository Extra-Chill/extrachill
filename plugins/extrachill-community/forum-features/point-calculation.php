<?php

// Function to calculate total points for a user, including main site comment points + articles
function wp_surgeon_get_user_total_points($user_id) {
    // Get the user's topic count from bbPress
    $topic_count = intval(bbp_get_user_topic_count($user_id)); // Assumed to return raw count

    // Get the user's reply count from bbPress
    $reply_count = intval(bbp_get_user_reply_count($user_id)); // Assumed to return raw count
    
    // Calculate points for topics and replies (2 points each)
    $bbpress_points = ($topic_count + $reply_count) * 2;

    // Get the total upvotes for the user's posts
    $total_upvotes = wp_surgeon_get_user_total_upvotes($user_id); // This function needs to be defined
    $upvote_points = $total_upvotes * 0.5;

    // Retrieve the follower count
    $followers = get_user_meta($user_id, 'extrachill_followers', true);
    $follower_count = is_array($followers) ? count($followers) : 0;
    $follower_points = $follower_count * 3;

    // Convert community user ID to author ID for fetching main site post count
    $author_id = convert_community_user_id_to_author_id($user_id);
    $main_site_post_count = $author_id !== null ? fetch_main_site_post_count_for_user($author_id) : 0;

    // Main site posts are worth 10 points each
    $main_site_post_points = $main_site_post_count * 10;

    // Calculate total points
    $total_points = $bbpress_points + $upvote_points + $follower_points + $main_site_post_points;

    // Store the total points as user meta for future retrieval
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
add_action('custom_upvote_action', function($post_id, $user_id, $upvoted) {
    wp_surgeon_queue_points_recalculation($post_id);
}, 10, 3);

// Handle follow and unfollow actions
add_action('extrachill_followed_user', function($follower_id, $followed_id) {
    wp_surgeon_queue_points_recalculation($follower_id);
    wp_surgeon_queue_points_recalculation($followed_id);
}, 10, 2);

add_action('extrachill_unfollowed_user', function($follower_id, $followed_id) {
    wp_surgeon_queue_points_recalculation($follower_id);
    wp_surgeon_queue_points_recalculation($followed_id);
}, 10, 2);

// Display user points
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
