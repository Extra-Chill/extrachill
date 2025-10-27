<?php
/**
 * Main Site Comments Integration
 *
 * Cross-domain comment display using WordPress multisite functions.
 * Uses direct blog ID numbers for maximum performance.
 *
 * @package ExtraChillCommunity
 * @subpackage Content
 */

/**
 * Display main site comments for a specific user
 *
 * @since 1.0.0
 * @param int $community_user_id User ID
 * @return string HTML markup or error message
 */
if (!function_exists('display_main_site_comments_for_user')) {
    function display_main_site_comments_for_user($community_user_id) {
        if (empty($community_user_id) || !is_numeric($community_user_id)) {
            return '<div class="bbpress-comments-error">Invalid user ID provided.</div>';
        }

        $user_info = get_userdata($community_user_id);
        $user_nicename = $user_info ? $user_info->user_nicename : 'Unknown User';

        switch_to_blog( 1 );

        $user_comments = get_comments(array(
            'user_id' => $community_user_id,
            'status' => 'approve',
            'order' => 'DESC',
            'orderby' => 'comment_date_gmt'
        ));

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
                        'comment_content' => $comment->comment_content,
                        'author_id' => $comment->user_id,
                        'author_name' => get_the_author_meta('display_name', $comment->user_id),
                        'author_avatar' => get_avatar_url($comment->user_id, array('size' => 80)),
                        'upvote_count' => 0
                    );
                }
            }
        }

        restore_current_blog();

        if (empty($comments)) {
            return '<div class="bbpress-comments-list"><h3>Comments Feed for <span class="comments-feed-user">' . esc_html($user_nicename) . '</span></h3><p>No comments found for this user.</p></div>';
        }

        ob_start();
        ?>
        <div class="bbpress-comments-list">
            <h3>Comments Feed for <span class="comments-feed-user"><?php echo esc_html($user_nicename); ?></span></h3>
            <?php
            foreach ($comments as $comment) {
                set_query_var('comment_data', $comment);
                set_query_var('is_main_site_comment', true);

                locate_template('bbpress/loop-single-blog-comment-card.php', true, false);
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('get_user_main_site_comment_count')) {
    function get_user_main_site_comment_count($user_id) {
        if (empty($user_id) || !is_numeric($user_id)) {
            return 0;
        }

        switch_to_blog( 1 );
        $count = get_comments(array(
            'user_id' => $user_id,
            'count' => true,
            'status' => 'approve'
        ));
        restore_current_blog();

        return intval($count);
    }
}

if (!function_exists('extrachill_add_query_vars_filter')) {
    function extrachill_add_query_vars_filter($vars){
        $vars[] = "user_id";
        return $vars;
    }
}
add_filter('query_vars', 'extrachill_add_query_vars_filter');
