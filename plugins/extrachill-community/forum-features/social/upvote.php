<?php
/**
 * Upvote System
 * 
 * AJAX-based upvoting functionality for forum topics and replies.
 * Manages vote state, counts, and triggers point calculation hooks.
 * 
 * @package ExtraChillCommunity
 */

function handle_upvote_action() {
    error_log('Upvote AJAX called with data: ' . print_r($_POST, true));
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    $user_id = get_current_user_id();
    
    if (!$post_id) {
        error_log('Upvote error: No post ID provided');
        wp_send_json_error(['message' => 'No post ID provided']);
        return;
    }
    
    if (!$user_id) {
        error_log('Upvote error: User not logged in');
        wp_send_json_error(['message' => 'User not logged in']);
        return;
    }
    
    if (!in_array($type, ['topic', 'reply'])) {
        error_log('Upvote error: Invalid post type: ' . $type);
        wp_send_json_error(['message' => 'Invalid post type']);
        return;
    }

    error_log('Upvote nonce verification - User ID: ' . $user_id . ', Nonce: ' . sanitize_text_field($_POST['nonce']));
    if (!check_ajax_referer('upvote_nonce', 'nonce', false)) {
        error_log('Upvote error: Nonce verification failed');
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    if ($post_id && $user_id && ($type === 'topic' || $type === 'reply')) {
        $upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
        if (!is_array($upvoted_posts)) {
            $upvoted_posts = [];
        }

        $post_author_id = get_post_field('post_author', $post_id);

        if (in_array($post_id, $upvoted_posts)) {
            $upvoted_posts = array_diff($upvoted_posts, [$post_id]);
            update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

            $upvote_count = max(get_post_meta($post_id, 'upvote_count', true) - 1, 0);
            update_post_meta($post_id, 'upvote_count', $upvote_count);

            $upvoted = false;
            do_action('custom_upvote_action', $post_id, $post_author_id, $upvoted);

            wp_send_json_success(['message' => 'Upvote removed', 'new_count' => $upvote_count, 'upvoted' => false]);
        } else {
            $upvoted_posts[] = $post_id;
            update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

            $upvote_count = get_post_meta($post_id, 'upvote_count', true);
            $upvote_count = empty($upvote_count) ? 1 : intval($upvote_count) + 1;
            update_post_meta($post_id, 'upvote_count', $upvote_count);

            $upvoted = true;
            do_action('custom_upvote_action', $post_id, $post_author_id, $upvoted);

            wp_send_json_success(['message' => 'Upvote recorded', 'new_count' => $upvote_count, 'upvoted' => true]);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid request']);
    }

    wp_die();
}


add_action('wp_ajax_handle_upvote', 'handle_upvote_action');
add_action('wp_ajax_nopriv_handle_upvote', 'handle_upvote_action');

function get_upvote_count($post_id) {
    $count = get_post_meta($post_id, 'upvote_count', true);
    return is_numeric($count) ? intval($count) : 0;
}

function extrachill_get_upvoted_posts($post_type, $user_id = null) {
    $current_user_id = get_current_user_id();
    $upvoted = $user_id ? [$user_id] : get_user_meta($current_user_id, 'upvoted_posts', true);

    if (empty($upvoted) || !is_array($upvoted)) {
        return new WP_Query();
    }
    $paged = max( 1, get_query_var('paged'), get_query_var('page') );

    $args = array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'post__in' => $upvoted,
        'posts_per_page' => get_option('posts_per_page'),
        'paged' => $paged,
    );

    $posts_query = new WP_Query($args);
    return $posts_query;
}


function extrachill_get_user_total_upvotes($user_id) {
    $args = array(
        'author'         => $user_id,
        'post_type'      => array('post', 'reply', 'topic'),
        'posts_per_page' => -1,
        'fields'         => 'ids'
    );

    $user_posts_query = new WP_Query( $args );
    $user_posts_ids = $user_posts_query->posts; // Get array of post IDs

    $total_upvotes = 0;
    if ( is_array( $user_posts_ids ) && !empty( $user_posts_ids ) ) {
        foreach ( $user_posts_ids as $post_id ) {
            $upvote = get_post_meta( $post_id, 'upvote_count', true );
            $total_upvotes += intval( $upvote );
        }
    }
    return $total_upvotes;
}

