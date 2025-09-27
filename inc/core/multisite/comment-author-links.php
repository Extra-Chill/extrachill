<?php
/**
 * Comment Author Links Multisite Integration
 *
 * Handles proper comment author linking across the ExtraChill multisite network.
 * Links ExtraChill.com users to their author pages, community-only users to bbPress profiles.
 *
 * @package ExtraChill
 * @since 69.57
 */

/**
 * Get proper comment author link based on multisite user existence
 *
 * Determines whether to link to:
 * - ExtraChill.com author page (if user exists on main site)
 * - Community bbPress profile (if user exists only on community site)
 * - Default WordPress behavior (for non-members)
 *
 * @param WP_Comment $comment Comment object
 * @return string Properly formatted author link HTML
 */
function ec_get_comment_author_link_multisite($comment) {
    if ($comment->user_id > 0) {
        $user_exists_main = get_userdata($comment->user_id);
        if ($user_exists_main && !empty($user_exists_main->user_nicename)) {
            $author_url = get_author_posts_url($comment->user_id);
            return '<a href="' . esc_url($author_url) . '">' . get_comment_author($comment) . '</a>';
        }
    }

    if (!empty($comment->comment_author_email)) {
        switch_to_blog(2);
        $community_user = get_user_by('email', $comment->comment_author_email);
        restore_current_blog();

        if ($community_user && !empty($community_user->user_nicename)) {
            $profile_url = 'https://community.extrachill.com/u/' . $community_user->user_nicename;
            return '<a href="' . esc_url($profile_url) . '">' . get_comment_author($comment) . '</a>';
        }
    }

    return get_comment_author_link($comment);
}

/**
 * Check if comment should use multisite linking logic
 *
 * Comments after the community migration date (Feb 9, 2024) use multisite linking.
 * Earlier comments use default WordPress behavior.
 *
 * @param WP_Comment $comment Comment object
 * @return bool Whether to use multisite linking
 */
function ec_should_use_multisite_comment_links($comment) {
    $comment_date = strtotime($comment->comment_date);
    $cutoff_date = strtotime('2024-02-09 00:00:00');

    return $comment_date > $cutoff_date;
}