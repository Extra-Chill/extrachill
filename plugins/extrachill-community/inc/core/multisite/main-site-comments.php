<?php
/**
 * Main Site Comments Integration
 *
 * Provides cross-domain comment display and management functionality
 * between community.extrachill.com and extrachill.com using WordPress multisite.
 *
 * @package ExtraChillCommunity
 * @subpackage Core\Multisite
 */

/**
 * Display main site comments for a specific user
 *
 * Fetches and displays comments from extrachill.com using multisite queries.
 * Uses switch_to_blog() to access main site data directly from the database.
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

    // Switch to main site (extrachill.com) to fetch comments directly
    $main_site_id = 1;
    switch_to_blog($main_site_id);

    // Fetch comments for this user from main site
    $user_comments = get_comments(array(
        'user_id' => $community_user_id,
        'status' => 'approve',
        'order' => 'DESC',
        'orderby' => 'comment_date_gmt'
    ));

    // Build comments array in same format as REST API
    $comments = array();
    if (!empty($user_comments)) {
        foreach ($user_comments as $comment) {
            $post = get_post($comment->comment_post_ID);
            if ($post) {
                $comments[] = array(
                    'comment_ID' => $comment->comment_ID,
                    'post_permalink' => get_permalink($post->ID),
                    'post_title' => $post->post_title,
                    'comment_date_gmt' => $comment->comment_date_gmt,
                    'comment_content' => $comment->comment_content
                );
            }
        }
    }

    restore_current_blog();

    if (empty($comments)) {
        return '<div class="bbpress-comments-list"><h3>Comments Feed for <span class="comments-feed-user">' . esc_html($user_nicename) . '</span></h3><p>No comments found for this user.</p></div>';
    }

    // Start building the output with the user's nicename in the title
    $output = "<div class=\"bbpress-comments-list\">";
    $output .= "<h3>Comments Feed for <span class='comments-feed-user'>{$user_nicename}</span></h3>";

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
            $comment_permalink
        );
    }
    $output .= '</div>';

    return $output;
}

/**
 * Get user's comment count from main site
 *
 * @param int $user_id User ID to check
 * @return int Comment count from main site
 */
function get_user_main_site_comment_count($user_id) {
    if (empty($user_id) || !is_numeric($user_id)) {
        return 0;
    }

    // Switch to main site (blog 1) to count comments
    switch_to_blog(1);
    $count = get_comments(array(
        'user_id' => $user_id,
        'count' => true,
        'status' => 'approve'
    ));
    restore_current_blog();

    return intval($count);
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
    $vars[] = "user_id";
    return $vars;
}
add_filter('query_vars', 'extrachill_add_query_vars_filter');