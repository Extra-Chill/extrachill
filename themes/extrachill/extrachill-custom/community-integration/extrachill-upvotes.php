<?php

// this code is used to add upvoting functionality to the site with AJAX via custom REST API endpoints
function handle_upvote_action() {
    check_ajax_referer('upvote_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $community_user_id = isset($_POST['community_user_id']) ? sanitize_text_field($_POST['community_user_id']) : '';

    if (!$post_id || empty($community_user_id)) {
        wp_send_json_error(['message' => 'Invalid request']);
        wp_die();
    }

    // Main site upvote logic
    $main_site_upvotes = get_post_meta($post_id, 'main_site_upvotes', true) ?: [];
    $upvoted = in_array($community_user_id, $main_site_upvotes);

    if (!$upvoted) {
        // Add upvote from main site
        $main_site_upvotes[] = $community_user_id;
    } else {
        // Remove upvote if already exists
        $main_site_upvotes = array_diff($main_site_upvotes, [$community_user_id]);
    }

    update_post_meta($post_id, 'main_site_upvotes', $main_site_upvotes);
    update_combined_upvote_count($post_id); // Recalculates and updates 'upvote_count'

    // Fetch updated count for response
    $combined_upvote_count = get_upvote_count($post_id);

    wp_send_json_success([
        'message' => !$upvoted ? 'Upvote added.' : 'Upvote removed.',
        'new_count' => $combined_upvote_count,
        'upvoted' => !$upvoted
    ]);
    wp_die();
}



add_action('wp_ajax_handle_upvote', 'handle_upvote_action');
add_action('wp_ajax_nopriv_handle_upvote', 'handle_upvote_action');


function get_upvote_count($post_id) {
    $count = get_post_meta($post_id, 'upvote_count', true);
    // Add 1 to the actual count for display purposes
    return is_numeric($count) ? intval($count) + 1 : 1;
}



// This function has been simplified to fit a universal WordPress context
function get_upvoted_posts_by_user($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    $upvoted = get_user_meta($user_id, 'upvoted_posts', true);

    if (empty($upvoted) || !is_array($upvoted)) {
        return [];
    }

    return $upvoted;
}

// Function to retrieve total upvotes by a user (simplified)
function get_user_total_upvotes($user_id) {
    $upvoted_posts = get_upvoted_posts_by_user($user_id);
    $total_upvotes = 0;

    foreach ($upvoted_posts as $post_id) {
        $upvotes = get_post_meta($post_id, 'upvote_count', true);
        $total_upvotes += (int) $upvotes;
    }

    return $total_upvotes;
}

// Enqueue necessary JavaScript for upvoting functionality
function enqueue_upvote_script() {
    // Use get_template_directory_uri() for parent theme or get_stylesheet_directory_uri() for child theme
    $script_path = get_template_directory() . '/js/extrachill-upvotes.js';
    $script_url = get_template_directory_uri() . '/js/extrachill-upvotes.js';

    // Enqueue the script with the file's last modified time for versioning
    wp_enqueue_script('upvote-script', $script_url, [], filemtime($script_path), true);
    
    // Localize the script with AJAX URL and nonce for secure requests
    wp_localize_script('upvote-script', 'upvoteParams', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('upvote_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_upvote_script');




function has_user_upvoted($post_id, $community_user_id) {
    $main_site_upvotes = get_post_meta($post_id, 'main_site_upvotes', true) ?: [];
    $community_upvotes = get_post_meta($post_id, 'community_site_upvotes', true) ?: [];
    
    // Check for user ID in both upvote arrays
    $upvoted_main_site = in_array($community_user_id, $main_site_upvotes);
    $upvoted_community_site = in_array($community_user_id, $community_upvotes);

    return $upvoted_main_site || $upvoted_community_site;
}




add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/upvotes/(?P<community_user_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_upvotes_by_community_user',
        'permission_callback' => '__return_true', // Adjust the permission callback as necessary
        'args' => array(
            'community_user_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
});
function get_upvotes_by_community_user($request) {
    $community_user_id = $request['community_user_id'];
    $upvoted_posts_data = [];

    // Adjust the query to look for posts with a specific meta_key that indicates upvotes by the community user
    $args = array(
        'meta_query' => array(
            array(
                'key' => 'main_site_upvotes', // Adjusted meta key to reflect main site upvotes
                'value' => '"' . $community_user_id . '"', // Ensure the value is correctly formatted for comparison
                'compare' => 'LIKE', // Use LIKE to search within serialized arrays if necessary
            ),
        ),
        'posts_per_page' => -1,
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            global $post; // Ensure global post data is accessible

            // Gather necessary post data
            $upvoted_posts_data[] = array(
                'post_id' => $post_id,
                'post_title' => get_the_title(),
                'author_display_name' => get_the_author_meta('display_name', $post->post_author),
                'author_id' => $post->post_author,
                'slug' => $post->post_name,
                'post_date' => $post->post_date,
                // Include any additional data as necessary
            );
        }
        wp_reset_postdata(); // Reset post data after custom query
    }

    return new WP_REST_Response($upvoted_posts_data, 200);
}



function extrachill_sort_posts($query) {
    // Only modify the main query on the front-end and only for archive pages
    if (!is_admin() && $query->is_main_query() && is_archive()) {
        // Check for the 'sort' parameter in the URL
        $sort = isset($_GET['sort']) ? $_GET['sort'] : '';

        switch ($sort) {
            case 'upvotes':
                // Sort posts by upvote count
                $query->set('meta_key', 'upvote_count');
                $query->set('orderby', 'meta_value_num');
                $query->set('order', 'DESC');
                break;
            case 'oldest':
                // Sort posts by date ascending
                $query->set('orderby', 'date');
                $query->set('order', 'ASC');
                break;
            case 'recent':
            default:
                // Default sorting: by recent (date descending). No need to modify the query.
                break;
        }
    }
}
add_action('pre_get_posts', 'extrachill_sort_posts');

function check_upvotes_bulk_handler() {
    // Verify nonce for security
    check_ajax_referer('upvote_nonce', 'nonce');

    $community_user_id = isset($_POST['community_user_id']) ? sanitize_text_field($_POST['community_user_id']) : '';
    $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? $_POST['post_ids'] : [];

    // Sanitize and validate post_ids to ensure they are integers
    $post_ids = array_filter($post_ids, function($pid) { return filter_var($pid, FILTER_VALIDATE_INT); });

    if (empty($community_user_id) || empty($post_ids)) {
        wp_send_json_error(['message' => 'Invalid request'], 400);
        wp_die();
    }

    $upvote_statuses = [];
    foreach ($post_ids as $post_id) {
        // Ensure $post_id is treated as integer
        $post_id = intval($post_id);

        $main_site_upvotes = get_post_meta($post_id, 'main_site_upvotes', true) ?: [];
        $has_upvoted = in_array($community_user_id, $main_site_upvotes);
        $upvote_statuses[$post_id] = $has_upvoted;
    }

    wp_send_json_success($upvote_statuses);
    wp_die();
}



add_action('wp_ajax_check_upvotes_bulk', 'check_upvotes_bulk_handler');
add_action('wp_ajax_nopriv_check_upvotes_bulk', 'check_upvotes_bulk_handler'); // If you want to allow not logged-in users to see upvote statuses.


add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/upvote-counts', array(
        'methods' => 'GET',
        'callback' => 'extrachill_get_upvote_counts',
        'permission_callback' => '__return_true',
        'args' => array(
            'post_ids' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return preg_match('/^(\d+,?)+$/', $param);
                }
            ), 
        ),
    ));
});

function extrachill_get_upvote_counts($request) {
    $post_ids_param = $request->get_param('post_ids');
    $post_ids = explode(',', $post_ids_param);
    $upvote_counts = [];

    foreach ($post_ids as $post_id) {
        $count = get_post_meta($post_id, 'upvote_count', true);                
        $upvote_counts[$post_id] = [
            'count' => is_numeric($count) ? intval($count) : 0,
        ];
    }

    return new WP_REST_Response($upvote_counts, 200);
}



add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/handle_external_upvote', array(
        'methods' => 'POST',
        'callback' => 'handle_external_upvote_action',
        'permission_callback' => '__return_true', // Adjust the permission callback as necessary
        'args' => array(
            'post_id' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
            'community_user_id' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return preg_match('/^[a-zA-Z0-9_]+$/', $param); // Adjust validation as needed
                }
            ),
            'action' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return in_array($param, ['upvote', 'remove_upvote']);
                }
            ),
        ),
    ));
});

function handle_external_upvote_action(WP_REST_Request $request) {
    $post_id = $request['post_id'];
    $community_user_id = $request['community_user_id'];
    $action = $request['action'];

    $community_upvotes_meta_key = 'community_site_upvotes';
    $community_upvotes = get_post_meta($post_id, $community_upvotes_meta_key, true) ?: [];
    $upvoted = in_array($community_user_id, $community_upvotes);
    $message = '';
    $success = true;

    if ($action === 'upvote' && !$upvoted) {
        $community_upvotes[] = $community_user_id;
        $message = 'Upvote successfully added';
    } elseif ($action === 'remove_upvote' && $upvoted) {
        $community_upvotes = array_diff($community_upvotes, [$community_user_id]);
        $message = 'Upvote successfully removed';
    }

    update_post_meta($post_id, $community_upvotes_meta_key, array_values($community_upvotes)); // Ensure array values are reset
    update_combined_upvote_count($post_id); // Recalculate combined upvote count

    $combined_upvote_count = get_post_meta($post_id, 'upvote_count', true); // Fetch updated count

    return new WP_REST_Response([
        'success' => $success,
        'data' => [
            'new_count' => $combined_upvote_count,
            'message' => $message,
            'upvoted' => in_array($community_user_id, get_post_meta($post_id, $community_upvotes_meta_key, true) ?: [])
        ]
    ], 200);
}





function update_combined_upvote_count($post_id) {
    // Fetch both sets of upvotes
    $main_site_upvotes = get_post_meta($post_id, 'main_site_upvotes', true) ?: [];
    $community_upvotes = get_post_meta($post_id, 'community_site_upvotes', true) ?: [];

    // Ensure both are arrays
    if (!is_array($main_site_upvotes)) {
        $main_site_upvotes = [];
    }
    if (!is_array($community_upvotes)) {
        $community_upvotes = [];
    }

    // The combined count is the sum of counts from both sources
    $combined_upvote_count = count($main_site_upvotes) + count($community_upvotes);

    // Update the combined upvote count meta
    update_post_meta($post_id, 'upvote_count', $combined_upvote_count);
}








