<?php

function display_main_site_comments_for_user($community_user_id) {
    // Fetch user data based on the community_user_id
    $user_info = get_userdata($community_user_id);
    // Safely retrieve the user nicename, or use a placeholder if not found
    $user_nicename = $user_info ? $user_info->user_nicename : 'Unknown User';

    $response = wp_remote_get("https://extrachill.com/wp-json/extrachill/v1/user-comments/{$community_user_id}");

    if (is_wp_error($response)) {
        return 'Could not fetch comments.';
    }

    $comments = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($comments)) {
        return "No comments found for user.";
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



function display_main_site_comment_count_for_user($user_id = null) {
    if (empty($user_id)) {
        // Fallback to the BBPress displayed user ID if not provided
        $user_id = bbp_get_displayed_user_id();
    }
    
    $response = wp_remote_get("https://extrachill.com/wp-json/extrachill/v1/user-comments-count/{$user_id}");

    if (is_wp_error($response)) {
        return 'Could not fetch comment count.';
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $comment_count = $data['comment_count'] ?? 0;

    if ($comment_count > 0) {
        // Adjust the URL to where you list all comments by this user on the main site
        $comments_url = "https://community.extrachill.com/blog-comments?user_id={$user_id}";
        return "<b>Main Site Comments:</b> $comment_count <a href='{$comments_url}'>(View All)</a>";
    } else {
        return "<b>Main Site Comments</b>: $comment_count";
    }
}



function extrachill_add_query_vars_filter($vars){
  $vars[] = "user_id"; // Name of the query var to register
  return $vars;
}
add_filter('query_vars', 'extrachill_add_query_vars_filter');
