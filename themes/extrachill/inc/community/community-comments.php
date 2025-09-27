<?php
/**
 * Community Comments Integration
 *
 * Handles comment submission, retrieval, and author link functionality
 * for community users via REST API endpoints.
 *
 * @package ExtraChill
 * @since 69.57
 */
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

/**
 * Insert community comment with metadata via REST API
 * @param WP_REST_Request $request REST request with comment data
 * @return WP_REST_Response|WP_Error Success response or error
 */
function insert_community_comment_with_meta(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;
    $comment_content = isset($params['comment']) ? sanitize_text_field($params['comment']) : '';
    $community_user_id = isset($params['community_user_id']) ? sanitize_text_field($params['community_user_id']) : '';
    $user_nicename = isset($params['author']) ? sanitize_text_field($params['author']) : '';
    $user_email = isset($params['email']) ? sanitize_email($params['email']) : '';
    $comment_parent = isset($params['comment_parent']) ? intval($params['comment_parent']) : 0;

    $comment_data = [
        'comment_post_ID' => $post_id,
        'comment_content' => $comment_content,
        'comment_author' => $user_nicename,
        'comment_author_email' => $user_email,
        'comment_approved' => 1,
        'comment_parent' => $comment_parent,
    ];

    $comment_id = wp_insert_comment($comment_data);

    if ($comment_id) {
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

/**
 * Fetch user comments by community ID via REST API
 * @param WP_REST_Request $request REST request with community_user_id
 * @return WP_REST_Response|WP_Error Array of user comments or error
 */
function fetch_user_comments_by_community_id($request) {
    $community_user_id = $request['community_user_id'];

    $comments_query = new WP_Comment_Query;
    $comments = $comments_query->query(array(
        'meta_key' => 'community_user_id',
        'meta_value' => $community_user_id,
        'order' => 'DESC',
    ));

    $comments_data = array_map(function($comment) {
        $post_title = get_the_title($comment->comment_post_ID);
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



/**
 * Get comment count for community user
 * @param string $community_user_id Community user identifier
 * @return int Number of comments
 */
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

/**
 * Transform comment author links to community profile links
 * Links usernames to community.extrachill.com profiles for comments after cutoff date
 * @param string $author_link Original author link HTML
 * @return string Modified author link with community profile URL
 */
function custom_comment_author_link_with_preg_replace($author_link) {
    global $comment;

    if (!empty($comment) && isset($comment->comment_date)) {
        $comment_date = strtotime($comment->comment_date);
        $cutoff_date = strtotime('2024-02-09 00:00:00');

        if ($comment_date > $cutoff_date) {
            $pattern = '/<div class="comment-author-link">(.*?)<\/div>/';
            $replacement = '<div class="comment-author-link"><a href="https://community.extrachill.com/u/$1">$1</a></div>';
            $author_link = preg_replace($pattern, $replacement, $author_link);
        }
    }

    return $author_link;
}


