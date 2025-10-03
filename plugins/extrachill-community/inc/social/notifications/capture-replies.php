<?php
/**
 * Reply Notification Capture
 *
 * Notifies topic authors when someone replies to their topic.
 * Includes duplicate prevention to avoid conflicts with mention notifications.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capture reply notifications
 *
 * Notifies topic author when someone replies to their topic.
 * Skips notification if a mention notification already exists for the same reply.
 *
 * @param int   $reply_id       Reply post ID
 * @param int   $topic_id       Topic post ID
 * @param int   $forum_id       Forum ID
 * @param array $anonymous_data Anonymous user data
 * @param int   $reply_author   Reply author user ID
 */
function extrachill_capture_reply_notifications($reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author) {
    // Prevent self-notification (author replying to own topic)
    if ($reply_author == get_post_field('post_author', $topic_id)) {
        return;
    }

    // Get topic author and topic data
    $topic_author = get_post_field('post_author', $topic_id);
    $topic_title = get_the_title($topic_id);
    $reply_link = bbp_get_reply_url($reply_id);

    // Send reply notification (mention handler will deduplicate if needed)
    do_action('extrachill_notify', $topic_author, [
        'actor_id'    => $reply_author,
        'type'        => 'reply',
        'topic_title' => $topic_title,
        'link'        => $reply_link,
        'post_id'     => $topic_id,
    ]);
}

// Hook into bbPress actions (priority 10 - before mentions at priority 12)
add_action('bbp_new_reply', 'extrachill_capture_reply_notifications', 10, 5);
