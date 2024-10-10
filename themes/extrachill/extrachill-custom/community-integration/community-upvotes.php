<?php
// Ensure WordPress functions are accessible, typically via a plugin or theme functions file.
function extrachill_handle_upvote_action() {
    // Verify session token for security and user authentication
    $session_token = $_POST['session_token'] ?? '';
    if (!extrachill_verify_session_token($session_token)) {
        wp_send_json_error(['message' => 'Authentication required']);
        wp_die();
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $user_id = get_user_id_from_session_token($session_token); // Implement this based on your authentication system

    if (!$post_id || !$user_id) {
        wp_send_json_error(['message' => 'Invalid request']);
        wp_die();
    }

    // Upvote logic similar to community.extrachill.com
    $upvoted_posts = get_user_meta($user_id, 'extrachill_upvoted_posts', true);
    if (!is_array($upvoted_posts)) {
        $upvoted_posts = [];
    }

    if (in_array($post_id, $upvoted_posts)) {
        // If already upvoted, remove the upvote
        $upvoted_posts = array_diff($upvoted_posts, [$post_id]);
        update_user_meta($user_id, 'extrachill_upvoted_posts', $upvoted_posts);

        $upvote_count = max(get_post_meta($post_id, 'extrachill_upvote_count', true) - 1, 0);
        update_post_meta($post_id, 'extrachill_upvote_count', $upvote_count);

        wp_send_json_success(['message' => 'Upvote removed', 'new_count' => $upvote_count, 'upvoted' => false]);
    } else {
        // If not yet upvoted, add the upvote
        $upvoted_posts[] = $post_id;
        update_user_meta($user_id, 'extrachill_upvoted_posts', $upvoted_posts);

        $upvote_count = get_post_meta($post_id, 'extrachill_upvote_count', true);
        $upvote_count = empty($upvote_count) ? 1 : intval($upvote_count) + 1;
        update_post_meta($post_id, 'extrachill_upvote_count', $upvote_count);

        wp_send_json_success(['message' => 'Upvote added', 'new_count' => $upvote_count, 'upvoted' => true]);
    }

    wp_die();
}

// Hook for authenticated users; adjust if necessary for your setup
add_action('wp_ajax_extrachill_handle_upvote', 'extrachill_handle_upvote_action');
// Hook for non-authenticated users if needed; consider security implications
// add_action('wp_ajax_nopriv_extrachill_handle_upvote', 'extrachill_handle_upvote_action');

function extrachill_verify_session_token($token) {
    // Placeholder for session token verification logic
    // Return true if valid, false otherwise
    return true; // Implement actual validation based on your session management
}

function get_user_id_from_session_token($token) {
    // Placeholder for extracting user ID from session token
    // Return user ID if valid, false otherwise
    return get_current_user_id(); // Implement actual extraction logic based on your session management
}

// Remember to properly enqueue any necessary scripts and localize script variables for AJAX URLs and nonces if needed.
