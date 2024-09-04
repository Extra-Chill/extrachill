<?php
function custom_pinned_replies_in_forum($args) {
    $forum_id = bbp_get_forum_id();
    if ($forum_id == 1494) {
        $args['meta_query'] = array(
            'relation' => 'OR',
            array(
                'key' => 'main_site_post_id',
                'compare' => 'EXISTS'
            ),
            array(
                'key' => 'main_site_post_id',
                'compare' => 'NOT EXISTS'
            )
        );
        $args['orderby'] = array(
            'meta_value_num' => 'DESC',
            'date' => 'ASC'
        );
        // Mark this query for custom ordering
        $args['bbp_custom_orderby'] = true;
    }
    return $args;
}
add_filter('bbp_after_has_replies_parse_args', 'custom_pinned_replies_in_forum');


add_filter('bbp_after_has_replies_parse_args', function ($args) {
    $forum_id = bbp_get_forum_id();
    if (1494 === $forum_id) {
        $args['orderby'] = 'date';
        $args['order'] = 'ASC';
        $args['meta_query'] = [
            'relation' => 'OR',
            [
                'key'     => 'main_site_post_id',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'main_site_post_id',
                'compare' => 'NOT EXISTS',
            ],
        ];
        $args['bbp_custom_orderby'] = true;
    }

    return $args;
});

add_filter('posts_orderby', function ($orderby, $query) {
    if (isset($query->query_vars['bbp_custom_orderby'])) {
        global $wpdb;
        // Custom order: replies with main_site_post_id first, then others by date.
        $orderby = "mt1.meta_value DESC, {$wpdb->posts}.post_date ASC";
    }

    return $orderby;
}, 10, 2);


function custom_disable_topic_form_for_forum_1494($can_access) {
    // Check if we're within the single forum context and get the current forum ID
    if (bbp_is_single_forum()) {
        $current_forum_id = bbp_get_forum_id();

        // If the current forum is 1494, disable topic form access
        if ($current_forum_id == 1494) {
            $can_access = false;
        }
    }

    return $can_access;
}
add_filter('bbp_current_user_can_access_create_topic_form', 'custom_disable_topic_form_for_forum_1494');

function restrict_replies_for_guests_and_show_custom_message_with_dynamic_url() {
    if ( bbp_is_single_topic() ) {
        $current_forum_id = bbp_get_forum_id(bbp_get_topic_forum_id());

        if ($current_forum_id == 1494 && !is_user_logged_in()) {
            $topic_id = bbp_get_topic_id();

            // Directly access the 'extrachill_post_url' post meta
            $post_url = get_post_meta($topic_id, 'extrachill_post_url', true);
            if (empty($post_url)) {
                $post_url = 'https://extrachill.com'; // Default URL if no specific post URL is found
            }

            echo '<div class="1494-not-logged-in">
                    <p>Log in to view. Or, <a href="' . esc_url($post_url) . '">click here</a> to read the blog version of this post (with ads).</p>
                  </div>';
            
            // Hide replies and pagination
            echo '<style>.bbp-replies, .bbp-pagination { display: none !important; }</style>';
        }
    }
}
add_action('bbp_template_before_replies_loop', 'restrict_replies_for_guests_and_show_custom_message_with_dynamic_url');

function custom_forum_topics_query($args) {
    // Ensure we are in forum 1494
    $forum_id = bbp_get_forum_id();
    if ($forum_id == 1494) {
        // Set the posts per page to 10
        $args['posts_per_page'] = 10;
    }
    return $args;
}
add_filter('bbp_after_has_topics_parse_args', 'custom_forum_topics_query');


function handle_forum_1494_upvote_action() {
    check_ajax_referer('upvote_nonce', 'nonce');
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0; // Local post ID
    $main_site_post_id = isset($_POST['main_site_post_id']) ? sanitize_text_field($_POST['main_site_post_id']) : '';
    $upvote_action = isset($_POST['upvote_action']) ? $_POST['upvote_action'] : ''; // Action received from AJAX request
    $user_id = get_current_user_id();

    // Basic validation
    if (!$post_id || !$user_id || empty($main_site_post_id) || !in_array($upvote_action, ['upvote', 'remove_upvote'])) {
        wp_send_json_error(['message' => 'Invalid request']);
        return;
    }

    // Fetch current upvoted posts meta
    $upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
    if (!is_array($upvoted_posts)) {
        $upvoted_posts = []; // Initialize as array if not already
    }

    // Modify upvoted_posts based on action
    if ($upvote_action === 'upvote') {
        if (!in_array($post_id, $upvoted_posts)) {
            $upvoted_posts[] = $post_id; // Add if not present
        }
    } elseif ($upvote_action === 'remove_upvote') {
        if (($key = array_search($post_id, $upvoted_posts)) !== false) {
            unset($upvoted_posts[$key]); // Remove if present
            $upvoted_posts = array_values($upvoted_posts); // Reindex array
        }
    }

    // Update the meta
    update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

    // Make request to external API for syncing upvote action
    $response = wp_remote_post('https://extrachill.com/wp-json/extrachill/v1/handle_external_upvote', [
        'method' => 'POST',
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body' => json_encode([
            'post_id' => $main_site_post_id,
            'action' => $upvote_action,
            'community_user_id' => $user_id,
        ]),
        'data_format' => 'body',
    ]);

    // Handle response from external API
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Failed to sync upvote with external site: ' . $response->get_error_message()]);
        return;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['success']) || !$body['success']) {
        wp_send_json_error(['message' => 'Failed to sync upvote with external site']);
        return;
    }

    wp_send_json_success(['message' => 'Upvote action successfully processed']);
    // Invalidate or update cache here
$cache_key = 'upvote_counts_' . $post_id; // Ensure this matches the cache key format used in fetch_upvote_counts_from_extrachill
delete_transient($cache_key); // Invalidate cache

}
add_action('wp_ajax_handle_forum_1494_upvote', 'handle_forum_1494_upvote_action');
add_action('wp_ajax_nopriv_handle_forum_1494_upvote', 'handle_forum_1494_upvote_action');


function extrachill_enqueue_upvote_1494_script() {
    if (function_exists('bbp_get_forum_id')) {
        $current_forum_id = bbp_get_forum_id();

        if ($current_forum_id == 1494) {
            $stylesheet_dir_uri = get_stylesheet_directory_uri();
            $script_version = filemtime(get_stylesheet_directory() . '/js/upvote-1494.js');

            wp_enqueue_script('extrachill-upvote-1494', $stylesheet_dir_uri . '/js/upvote-1494.js', array('jquery'), $script_version, true);

            // Use a unique name for localization to avoid conflicts
            wp_localize_script('extrachill-upvote-1494', 'extrachillUpvote1494', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('upvote_nonce'),
                'is_user_logged_in' => is_user_logged_in() ? true : false, // Directly pass boolean value
                'user_id' => get_current_user_id()
            ));
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_upvote_1494_script');




