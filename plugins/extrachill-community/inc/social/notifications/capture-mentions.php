<?php
/**
 * Mention Notification Capture
 *
 * Detects @username mentions in bbPress topics and replies,
 * creates notifications for mentioned users.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
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
	$content = ( 0 === $reply_author ) ? bbp_get_topic_content($post_id) : bbp_get_reply_content($post_id);

	// Determine author (topic author or reply author)
	$action_author_id = ( 0 === $reply_author ) ? get_post_field('post_author', $post_id) : $reply_author;

	// Determine correct context IDs
	$actual_topic_id_for_context = ( 0 === $reply_author ) ? $post_id : $topic_id;
	$actual_item_id_for_context  = $post_id;

	// Extract @username mentions using regex
	preg_match_all('#@([0-9a-zA-Z-_]+)#i', $content, $matches);
	$usernames = array_unique($matches[1]); // Remove duplicates

	foreach ( $usernames as $username ) {
		$user = get_user_by('slug', $username);

		// Validate user exists, is active, and isn't mentioning themselves
		if ( $user && ! bbp_is_user_inactive($user->ID) && $user->ID !== $action_author_id ) {
			// Send mention notification
			do_action('extrachill_notify', $user->ID, array(
				'actor_id'    => $action_author_id,
				'type'        => 'mention',
				'topic_title' => get_the_title($actual_topic_id_for_context),
				'link'        => ( 0 === $reply_author ) ? get_permalink($actual_item_id_for_context) : bbp_get_reply_url($actual_item_id_for_context),
				'post_id'     => $actual_topic_id_for_context,
				'item_id'     => $actual_item_id_for_context,
			));

			// Priority deduplication: if the mentioned user is the topic author,
			// remove the (more generic) reply notification the reply-capture
			// handler just fired for the same actor/topic. Mention notifications
			// are more specific. This now targets the network substrate table.
			if ( get_post_field('post_author', $actual_topic_id_for_context) === $user->ID ) {
				extrachill_community_dedupe_reply_notification(
					(int) $user->ID,
					(int) $action_author_id,
					(int) $actual_topic_id_for_context
				);
			}
		}
	}
}

/**
 * Remove a just-fired reply notification superseded by a mention.
 *
 * When a user is both the topic author and is mentioned in a reply, the
 * reply-capture handler (priority 10) already enqueued a 'reply' notification
 * for the same actor/topic moments before this 'mention' notification. Mentions
 * are more specific, so delete the recent matching reply row from the network
 * substrate table. The table is keyed by base_prefix, so no switch_to_blog is
 * needed.
 *
 * @param int $user_id   Recipient (topic author / mentioned user).
 * @param int $actor_id  User who fired both notifications.
 * @param int $topic_id  Topic the reply notification referenced (item_id).
 * @return void
 */
function extrachill_community_dedupe_reply_notification( $user_id, $actor_id, $topic_id ) {
	global $wpdb;

	if ( ! function_exists( 'extrachill_users_notifications_table_name' ) ) {
		return;
	}

	$table = extrachill_users_notifications_table_name();

	// Match the reply row from this actor on this topic created within the last
	// few seconds (both notifications fire within the same request).
	$threshold = gmdate( 'Y-m-d H:i:s', time() - 5 );

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE user_id = %d AND actor_id = %d AND type = %s AND item_id = %d AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted substrate helper.
			$user_id,
			$actor_id,
			'reply',
			$topic_id,
			$threshold
		)
	);
}

// Hook into bbPress actions (priority 12 - after reply notifications at priority 10)
add_action('bbp_new_reply', 'extrachill_capture_mention_notifications', 12, 5);
add_action('bbp_new_topic', 'extrachill_capture_mention_notifications', 12, 4);
