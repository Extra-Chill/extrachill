<?php
/**
 * Blog Comments Integration for ExtraChill Community
 * 
 * Provides cross-domain comment display and management functionality
 * between community.extrachill.com and extrachill.com
 */

/**
 * Display main site comments for a specific user
 * 
 * Fetches and displays comments from extrachill.com API for a given user.
 * Includes comprehensive error handling and user-friendly fallbacks.
 * 
 * @param int $community_user_id User ID to fetch comments for
 * @return string HTML markup for comments display or error message
 */
function display_main_site_comments_for_user($community_user_id) {
    // Validate user ID
    if (empty($community_user_id) || !is_numeric($community_user_id)) {
        return '<div class="bbpress-comments-error">Invalid user ID provided.</div>';
    }

    // Fetch user data based on the community_user_id
    $user_info = get_userdata($community_user_id);
    // Safely retrieve the user nicename, or use a placeholder if not found
    $user_nicename = $user_info ? $user_info->user_nicename : 'Unknown User';

    $response = wp_remote_get("https://extrachill.com/wp-json/extrachill/v1/user-comments/{$community_user_id}", array(
        'timeout' => 10,
        'sslverify' => true
    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("Failed to fetch comments for user {$community_user_id}: {$error_message}");
        return '<div class="bbpress-comments-error">Unable to load comments at this time. Please try again later.</div>';
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log("API returned non-200 status for user {$community_user_id}: {$response_code}");
        return '<div class="bbpress-comments-error">Comments service temporarily unavailable. Please try again later.</div>';
    }

    $body = wp_remote_retrieve_body($response);
    $comments = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error for user {$community_user_id}: " . json_last_error_msg());
        return '<div class="bbpress-comments-error">Unable to process comments data.</div>';
    }

    if (empty($comments)) {
        return '<div class="bbpress-comments-list"><h3>Comments Feed for <span class="comments-feed-user">' . esc_html($user_nicename) . '</span></h3><p>No comments found for this user.</p></div>';
    }

    // Start building the output with the user's nicename in the title
    $output = "<div class=\"bbpress-comments-list\">";
    $output .= "<h3>Comments Feed for <span class='comments-feed-user'>{$user_nicename}</span></h3>"; // Add the heading at the top

    foreach ($comments as $comment) {
        // Construct the direct comment permalink
        $comment_permalink = esc_url($comment['post_permalink'] . '#comment-' . $comment['comment_ID']);
        
        $output .= sprintf(
            '<div class="bbpress-comment">
                <div class="comment-title"><b>Commented on: <a href="%s">%s</a></b></div>
                <div class="comment-meta">%s</div>
                <div class="comment-content">%s</div>
                <div class="comment-permalink"><a href="%s">Reply</a></div>
            </div>',
            esc_url($comment['post_permalink']),
            esc_html($comment['post_title']),
            esc_html(date('F j, Y, g:i a', strtotime($comment['comment_date_gmt']))),
            esc_html($comment['comment_content']),
            $comment_permalink // Use the constructed direct comment permalink
        );
    }
    $output .= '</div>';

    return $output;
}




/**
 * Display main site comment count for user profile
 * 
 * Fetches comment count from extrachill.com API with caching and error handling.
 * Used in user profiles to show comment activity with link to full comment feed.
 * 
 * @param int|null $user_id User ID (defaults to current or displayed user)
 * @return string HTML markup for comment count display
 */
function display_main_site_comment_count_for_user($user_id = null) {
    // Use current user ID if none is passed
    $user_id = $user_id ?: get_current_user_id();

    // Check if comment count is already cached in a transient
    $cached_comment_count = get_transient('main_site_comment_count_' . $user_id);
    if (false !== $cached_comment_count) {
        return $cached_comment_count; // Return cached comment count if available
    }

    if (empty($user_id)) {
        // Fallback to the BBPress displayed user ID if not provided
        $user_id = bbp_get_displayed_user_id();
    }
    
    // Final validation
    if (empty($user_id) || !is_numeric($user_id)) {
        return '<b>Main Site Comments:</b> Unable to load';
    }
    
    $response = wp_remote_get("https://extrachill.com/wp-json/extrachill/v1/user-comments-count/{$user_id}", array(
        'timeout' => 10,
        'sslverify' => true
    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("Failed to fetch comment count for user {$user_id}: {$error_message}");
        // Cache the failure for 1 hour to avoid repeated failed requests
        $result = '<b>Main Site Comments:</b> Unavailable';
        set_transient('main_site_comment_count_' . $user_id, $result, 3600);
        return $result;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log("Comment count API returned non-200 status for user {$user_id}: {$response_code}");
        $result = '<b>Main Site Comments:</b> Unavailable';
        set_transient('main_site_comment_count_' . $user_id, $result, 3600);
        return $result;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error for comment count user {$user_id}: " . json_last_error_msg());
        $result = '<b>Main Site Comments:</b> Unavailable';
        set_transient('main_site_comment_count_' . $user_id, $result, 3600);
        return $result;
    }

    $comment_count = $data['comment_count'] ?? 0;

    if ($comment_count > 0) {
        // Adjust the URL to where you list all comments by this user on the main site
        $comments_url = "https://community.extrachill.com/blog-comments?user_id={$user_id}";
        $result = "<b>Main Site Comments:</b> $comment_count <a href='" . esc_url($comments_url) . "'>(View All)</a>";
    } else {
        $result = "<b>Main Site Comments:</b> $comment_count";
    }
    
    // Cache the result for 7 days to avoid repeated API calls
    set_transient('main_site_comment_count_' . $user_id, $result, 604800);

    return $result;
}




/**
 * Register custom query variable for comment feed URLs
 * 
 * Enables user_id parameter in URLs like /blog-comments?user_id=123
 * for routing to specific user comment feeds.
 * 
 * @param array $vars Existing query variables
 * @return array Modified query variables array
 */
function extrachill_add_query_vars_filter($vars){
  $vars[] = "user_id"; // Name of the query var to register
  return $vars;
}
add_filter('query_vars', 'extrachill_add_query_vars_filter');
