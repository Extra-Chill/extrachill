<?php 

// comment submission logic on extrachill.com plugin

// Updated REST API endpoint registration to include a reply_to parameter
add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/community-comment', array(
        'methods' => 'POST',
        'callback' => 'insert_community_comment_with_meta',
        'permission_callback' => '__return_true', // Adjust the permission callback as necessary
        'args' => array(
            'reply_to' => array(
                'required' => false, // Not required, so top-level comments don't need this
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
});

function insert_community_comment_with_meta(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;
    $comment_content = isset($params['comment']) ? sanitize_text_field($params['comment']) : '';
    $community_user_id = isset($params['community_user_id']) ? sanitize_text_field($params['community_user_id']) : '';
    $user_nicename = isset($params['author']) ? sanitize_text_field($params['author']) : '';
    $user_email = isset($params['email']) ? sanitize_email($params['email']) : '';
    $comment_parent = isset($params['comment_parent']) ? intval($params['comment_parent']) : 0; // Directly use 'comment_parent'

    // Construct the comment data array including the comment_parent field
    $comment_data = [
        'comment_post_ID' => $post_id,
        'comment_content' => $comment_content,
        'comment_author' => $user_nicename,
        'comment_author_email' => $user_email,
        'comment_approved' => 1, // Auto-approve this comment.
        'comment_parent' => $comment_parent, // Set the parent of this comment
    ];

    // Insert the comment.
    $comment_id = wp_insert_comment($comment_data);

    if ($comment_id) {
        // Add custom meta data for the comment.
        add_comment_meta($comment_id, 'community_user_id', $community_user_id);
        add_comment_meta($comment_id, 'user_nicename', $user_nicename);
        add_comment_meta($comment_id, 'user_email', $user_email);

        return new WP_REST_Response(['message' => 'Comment submitted successfully', 'comment_id' => $comment_id], 200);
    } else {
        return new WP_Error('comment_submission_error', 'Failed to submit comment', ['status' => 500]);
    }
}


add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/user-comments/(?P<community_user_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'fetch_user_comments_by_community_id',
        'args' => array(
            'community_user_id' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
        'permission_callback' => '__return_true',
    ));
});

function fetch_user_comments_by_community_id($request) {
    $community_user_id = $request['community_user_id'];
    global $wpdb;

    $comments_query = new WP_Comment_Query;
    $comments = $comments_query->query(array(
        'meta_key' => 'community_user_id',
        'meta_value' => $community_user_id,
        'order' => 'DESC', // Newest comments first
    ));

    $comments_data = array_map(function($comment) {
        // Fetch the post title
        $post_title = get_the_title($comment->comment_post_ID);
        // Generate the permalink
        $post_permalink = get_permalink($comment->comment_post_ID);
        
        return array(
            'comment_ID' => $comment->comment_ID,
            'comment_post_ID' => $comment->comment_post_ID,
            'post_title' => $post_title,
            'post_permalink' => $post_permalink,
            'comment_content' => $comment->comment_content,
            'comment_date_gmt' => $comment->comment_date_gmt,
        );
    }, $comments);

    if (empty($comments_data)) {
        return new WP_Error('no_comments', 'No comments found for this user', array('status' => 404));
    }

    return new WP_REST_Response($comments_data, 200);
}



function get_comment_count_by_community_user_id($community_user_id) {
    global $wpdb;

    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) 
        FROM $wpdb->comments 
        JOIN $wpdb->commentmeta ON $wpdb->comments.comment_ID = $wpdb->commentmeta.comment_id 
        WHERE meta_key = 'community_user_id' AND meta_value = %s", 
        $community_user_id
    ));

    return intval($count);
}

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/user-comments-count/(?P<community_user_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'fetch_user_comment_count',
        'args' => array(
            'community_user_id' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
        'permission_callback' => '__return_true',
    ));
});

function fetch_user_comment_count($request) {
    global $wpdb;
    $community_user_id = $request['community_user_id'];

    // Query to count comments excluding 'trash' or 'spam'
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) 
        FROM $wpdb->comments 
        JOIN $wpdb->commentmeta ON $wpdb->comments.comment_ID = $wpdb->commentmeta.comment_id 
        WHERE meta_key = 'community_user_id' AND meta_value = %s 
        AND comment_approved IN ('1', '0')", // '1' for approved, '0' for pending. Adjust as needed.
        $community_user_id
    ));

    return new WP_REST_Response(['comment_count' => intval($count)], 200);
}


add_filter('get_comment_author_link', 'custom_comment_author_link_with_preg_replace');

function custom_comment_author_link_with_preg_replace($author_link) {
    global $comment;
    
    // Ensure the global $comment object is available and contains necessary properties
    if (!empty($comment) && isset($comment->comment_date)) {
        // Convert the comment date to a Unix timestamp for comparison
        $comment_date = strtotime($comment->comment_date);
        // Specify the cutoff Unix timestamp (2/9/24)
        $cutoff_date = strtotime('2024-02-09 00:00:00');

        // Only apply changes to comments made after the cutoff date
        if ($comment_date > $cutoff_date) {
            // Pattern to match the existing comment author link HTML structure
            $pattern = '/<div class="comment-author-link">(.*?)<\/div>/';

            // Replacement pattern includes the anchor tag around the username
            $replacement = '<div class="comment-author-link"><a href="https://community.extrachill.com/u/$1">$1</a></div>';

            // Perform the replacement
            $author_link = preg_replace($pattern, $replacement, $author_link);
        }
    }

    return $author_link;
}


