<?php
/**
 * Comment Auto-Approval for Logged-In Users
 *
 * Automatically approves comments from logged-in users, bypassing moderation.
 * Non-logged-in users follow standard WordPress comment moderation settings.
 *
 * @package ExtraChill\Users
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-approve comments from logged-in users
 *
 * @param int|string|WP_Error $approved Current approval status
 * @param array $commentdata Comment data array
 * @return int|string|WP_Error Modified approval status
 */
function ec_auto_approve_logged_in_comments($approved, $commentdata) {
    if (is_user_logged_in()) {
        return 1;
    }

    return $approved;
}
add_filter('pre_comment_approved', 'ec_auto_approve_logged_in_comments', 10, 2);
