<?php
function handle_upvote_action() {
    // Check for nonce for security
    check_ajax_referer('upvote_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : ''; // 'topic' or 'reply'
    $user_id = get_current_user_id();

    if ($post_id && $user_id && ($type === 'topic' || $type === 'reply')) {
        $upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
        if (!is_array($upvoted_posts)) {
            $upvoted_posts = [];
        }

        if (in_array($post_id, $upvoted_posts)) {
            // User has already upvoted, remove the upvote
            $upvoted_posts = array_diff($upvoted_posts, [$post_id]);
            update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

            // Decrement the upvote count
            $upvote_count = max(get_post_meta($post_id, 'upvote_count', true) - 1, 0);
            update_post_meta($post_id, 'upvote_count', $upvote_count);

            wp_send_json_success(['message' => 'Upvote removed', 'new_count' => $upvote_count, 'upvoted' => false]);
            // In your upvote handling script, after successfully processing an upvote/downvote:
do_action('custom_upvote_action', $post_id, $user_id);
        } else {
            // Add the upvote
            $upvoted_posts[] = $post_id;
            update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

            // Increment the upvote count
            $upvote_count = get_post_meta($post_id, 'upvote_count', true);
            $upvote_count = empty($upvote_count) ? 1 : intval($upvote_count) + 1;
            update_post_meta($post_id, 'upvote_count', $upvote_count);

            wp_send_json_success(['message' => 'Upvote recorded', 'new_count' => $upvote_count, 'upvoted' => true]);
            // In your upvote handling script, after successfully processing an upvote/downvote:
do_action('custom_upvote_action', $post_id, $user_id);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid request']);
    }

    wp_die();
}
add_action('wp_ajax_handle_upvote', 'handle_upvote_action');
add_action('wp_ajax_nopriv_handle_upvote', 'handle_upvote_action'); // If you want to allow non-logged in users to view upvote counts

function get_upvote_count($post_id) {
    $count = get_post_meta($post_id, 'upvote_count', true);
    return is_numeric($count) ? intval($count) : 0;
}

function extrachill_get_upvoted_posts($post_type, $user_id = null) {
    $current_user_id = get_current_user_id();
    $upvoted = $user_id ? [$user_id] : get_user_meta($current_user_id, 'upvoted_posts', true);

    if (empty($upvoted) || !is_array($upvoted)) {
        return new WP_Query(); // Return an empty WP_Query object if no upvoted posts
    }

    $paged = get_query_var('paged') ? get_query_var('paged') : 1;
    $private_forum_ids = extrachill_get_private_forum_ids();
    $is_user_extrachill_team = is_user_logged_in() && get_user_meta($current_user_id, 'extrachill_team', true) == '1';

    $args = array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'post__in' => $upvoted,
        'posts_per_page' => get_option('posts_per_page'),
        'paged' => $paged,
    );

    if (!$is_user_extrachill_team) {
        $args['post_parent__not_in'] = $private_forum_ids;
    }

    $posts_query = new WP_Query($args);
    return $posts_query;
}


function wp_surgeon_get_user_total_upvotes($user_id) {
    $args = [
        'author' => $user_id,
        'post_type' => ['post', 'reply', 'topic'], // Include any post types that can receive upvotes
        'posts_per_page' => -1, // Retrieve all posts
        'fields' => 'ids', // Retrieve only post IDs for performance
    ];

    $user_posts = get_posts($args);
    $total_upvotes = 0;

    foreach ($user_posts as $post_id) {
        $upvotes = get_post_meta($post_id, 'upvote_count', true);
        $total_upvotes += (int) $upvotes;
    }

    return $total_upvotes;
}
function wp_surgeon_sort_topics_by_upvotes($args) {
    // Check if sorting by upvotes is selected
    if (isset($_GET['sort']) && $_GET['sort'] == 'upvotes') {
        $args['meta_key'] = 'upvote_count'; // Assuming 'upvote_count' is stored as post meta
        $args['orderby'] = 'meta_value_num';
        $args['order'] = 'DESC';
    }

    return $args;
}
add_filter('bbp_before_has_topics_parse_args', 'wp_surgeon_sort_topics_by_upvotes');

function wp_surgeon_adjust_topics_query($args) {
    // Sorting by upvotes
    if (isset($_GET['sort']) && $_GET['sort'] == 'upvotes') {
        $args['meta_key'] = 'upvote_count';
        $args['orderby'] = 'meta_value_num';
        $args['order'] = 'DESC';
    }

    // Limiting posts by time range
    if (isset($_GET['time_range']) && in_array($_GET['time_range'], ['7', '30', '90'])) {
        $days = (int) $_GET['time_range'];
        $args['date_query'] = array(
            array(
                'after' => "{$days} days ago",
            ),
        );
    }

    return $args;
}
add_filter('bbp_before_has_topics_parse_args', 'wp_surgeon_adjust_topics_query');

function enqueue_sorting_script() {
    wp_enqueue_script('sorting', get_stylesheet_directory_uri() . '/js/sorting.js', ['jquery'], null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_sorting_script');


