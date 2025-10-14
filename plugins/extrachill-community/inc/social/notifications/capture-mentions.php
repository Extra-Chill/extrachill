<?php
/**
 * Mention Notification Capture
 *
 * Detects @username mentions in bbPress topics and replies,
 * creates notifications for mentioned users.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capture mention notifications from topics and replies
 *
 * Parses content for @username patterns and creates notifications
 * for mentioned users. Handles both new topics and new replies.
 *
 * @param int   $post_id        Topic or reply post ID
 * @param int   $topic_id       Topic ID
 * @param int   $forum_id       Forum ID
 * @param array $anonymous_data Anonymous user data
 * @param int   $reply_author   Reply author ID (0 for topics)
 */
function extrachill_capture_mention_notifications($post_id, $topic_id, $forum_id, $anonymous_data, $reply_author = 0) {
    // Get content based on context (topic or reply)
    $content = ($reply_author == 0) ? bbp_get_topic_content($post_id) : bbp_get_reply_content($post_id);

    // Determine author (topic author or reply author)
    $action_author_id = ($reply_author == 0) ? get_post_field('post_author', $post_id) : $reply_author;

    // Determine correct context IDs
    $actual_topic_id_for_context = ($reply_author == 0) ? $post_id : $topic_id;
    $actual_item_id_for_context = $post_id;

    // Extract @username mentions using regex
    preg_match_all('#@([0-9a-zA-Z-_]+)#i', $content, $matches);
    $usernames = array_unique($matches[1]); // Remove duplicates

    foreach ($usernames as $username) {
        $user = get_user_by('slug', $username);

        // Validate user exists, is active, and isn't mentioning themselves
        if ($user && !bbp_is_user_inactive($user->ID) && $user->ID != $action_author_id) {
            // Send mention notification
            do_action('extrachill_notify', $user->ID, [
                'actor_id'    => $action_author_id,
                'type'        => 'mention',
                'topic_title' => get_the_title($actual_topic_id_for_context),
                'link'        => ($reply_author == 0) ? get_permalink($actual_item_id_for_context) : bbp_get_reply_url($actual_item_id_for_context),
                'post_id'     => $actual_topic_id_for_context,
                'item_id'     => $actual_item_id_for_context,
            ]);

            // Priority deduplication: If mentioned user is topic author, remove reply notification
            // Mention notifications are more specific than reply notifications
            if ($user->ID == get_post_field('post_author', $actual_topic_id_for_context)) {
                // Switch to community site for deduplication
                $community_blog_id = get_blog_id_from_url( 'community.extrachill.com', '/' );
                if ( $community_blog_id ) {
                    $current_blog_id = get_current_blog_id();
                    $switched = false;

                    if ( $current_blog_id !== $community_blog_id ) {
                        switch_to_blog( $community_blog_id );
                        $switched = true;
                    }

                    try {
                        $user_notifications = get_user_meta($user->ID, 'extrachill_notifications', true) ?: [];

                        if (is_array($user_notifications)) {
                            $current_time = current_time('timestamp');

                            // Remove reply notification from same actor/topic within last 5 seconds
                            $filtered_notifications = array_filter($user_notifications, function($notif) use ($action_author_id, $actual_topic_id_for_context, $current_time) {
                                // Keep notification if it's not a recent reply from this actor on this topic
                                if ($notif['type'] !== 'reply') return true;
                                if (!isset($notif['actor_id']) || $notif['actor_id'] != $action_author_id) return true;
                                if (!isset($notif['post_id']) || $notif['post_id'] != $actual_topic_id_for_context) return true;
                                if (!isset($notif['time']) || abs(strtotime($notif['time']) - $current_time) > 5) return true;

                                // Remove this reply notification (mention takes precedence)
                                return false;
                            });

                            // Re-index array and update
                            update_user_meta($user->ID, 'extrachill_notifications', array_values($filtered_notifications));
                        }

                    } finally {
                        if ( $switched ) {
                            restore_current_blog();
                        }
                    }
                }
            }
        }
    }
}

// Hook into bbPress actions (priority 12 - after reply notifications at priority 10)
add_action('bbp_new_reply', 'extrachill_capture_mention_notifications', 12, 5);
add_action('bbp_new_topic', 'extrachill_capture_mention_notifications', 12, 4);
