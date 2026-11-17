<?php
/**
 * Comment notifications: replies to your comment, and comments on your shows.
 *
 * Comments on the network's discussion-hosting sites (main articles and
 * events, resolved through ec_users_get_comment_activity_blog_ids()) are
 * network activity (#1634). When a comment becomes approved, this producer
 * notifies, through the notification substrate (ec_users_notify_with_receipts()):
 *
 *   a. `comment_reply`  — the author of the parent comment, when the approved
 *                         comment replies to another member's comment.
 *   b. `event_comment`  — every member who marked the event as going (concert
 *                         tracking), when the approved comment is on an event.
 *                         The commenter and the reply recipient from (a) are
 *                         excluded, so nobody gets two notifications for one
 *                         comment.
 *
 * It fires on the approval edge only: a comment inserted already approved
 * (`comment_post`) or a held comment moving to approved
 * (`transition_comment_status`). Both producers key their receipts on the
 * comment and its blog (`comment:<id>:blog:<id>`), so a replayed hook, a
 * re-approval, or a retried async job converges on one row per recipient.
 *
 * Opt-outs: recipients who blocked the commenter (community member blocks,
 * network user meta) are skipped, the commenter is never notified about their
 * own comment, and guest comments notify nobody (the substrate requires a real
 * actor). Email and push opt-outs are enforced by those channels.
 *
 * Event fan-out can address many members, so delivery runs as a unique Action
 * Scheduler async action when Action Scheduler is available, and inline
 * otherwise.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/** Producer id for replies to a member's comment. */
const EC_USERS_COMMENT_REPLY_PRODUCER = 'extrachill-users/comment-replies';

/** Producer id for comments on an event a member marked as going. */
const EC_USERS_EVENT_COMMENT_PRODUCER = 'extrachill-users/event-comments';

/** Notification type for a reply to the recipient's comment. */
const EC_USERS_COMMENT_REPLY_TYPE = 'comment_reply';

/** Notification type for a comment on an event the recipient is going to. */
const EC_USERS_EVENT_COMMENT_TYPE = 'event_comment';

/** Event post type whose comments notify the members going. */
const EC_USERS_COMMENT_NOTIFY_EVENT_POST_TYPE = 'data_machine_events';

/** Action Scheduler hook that delivers one comment's notifications. */
const EC_USERS_COMMENT_NOTIFY_ACTION = 'ec_users_comment_notify_deliver';

/** Network user meta holding a member's community block list. */
const EC_USERS_COMMENT_NOTIFY_MEMBER_BLOCKS_META = 'ec_member_blocked_user_ids';

/**
 * Build the per-comment idempotency key shared by both producers.
 *
 * @param int $comment_id Comment ID.
 * @param int $blog_id    Blog the comment lives on.
 * @return string
 */
function ec_users_comment_notify_idempotency_key( int $comment_id, int $blog_id ): string {
	return sprintf( 'comment:%d:blog:%d', $comment_id, $blog_id );
}

/**
 * Notify when a new comment is inserted already approved.
 *
 * @param int        $comment_id Comment ID.
 * @param int|string $approved   1 when approved.
 */
function ec_users_comment_notify_on_post( $comment_id, $approved ): void {
	if ( 1 === (int) $approved ) {
		ec_users_comment_notify_dispatch( (int) $comment_id );
	}
}
add_action( 'comment_post', 'ec_users_comment_notify_on_post', 20, 2 );

/**
 * Notify when a held comment becomes approved.
 *
 * @param int|string $new_status New status.
 * @param int|string $old_status Old status.
 * @param WP_Comment $comment    Comment object.
 */
function ec_users_comment_notify_on_transition( $new_status, $old_status, $comment ): void {
	if ( 'approved' !== $new_status || 'approved' === $old_status ) {
		return;
	}

	if ( $comment instanceof WP_Comment ) {
		ec_users_comment_notify_dispatch( (int) $comment->comment_ID );
	}
}
add_action( 'transition_comment_status', 'ec_users_comment_notify_on_transition', 20, 3 );

/**
 * Hand one approved comment to delivery, async when Action Scheduler exists.
 *
 * @param int $comment_id Comment ID on the current blog.
 */
function ec_users_comment_notify_dispatch( int $comment_id ): void {
	$blog_id = (int) get_current_blog_id();
	if ( $comment_id <= 0 || ! function_exists( 'ec_users_is_comment_activity_blog' ) || ! ec_users_is_comment_activity_blog( $blog_id ) ) {
		return;
	}

	/**
	 * Filter whether comment notifications are delivered by an async action.
	 *
	 * @param bool $async      Default true when Action Scheduler is available.
	 * @param int  $comment_id Comment ID.
	 * @param int  $blog_id    Blog ID.
	 */
	$async = (bool) apply_filters( 'ec_users_comment_notify_async', function_exists( 'as_enqueue_async_action' ), $comment_id, $blog_id );

	if ( $async ) {
		try {
			as_enqueue_async_action( EC_USERS_COMMENT_NOTIFY_ACTION, array( $blog_id, $comment_id ), 'extrachill-users-notifications', true );
			return;
		} catch ( \Throwable $exception ) {
			error_log( sprintf( 'ec_users_comment_notify: could not queue comment %1$d on blog %2$d, delivering inline: %3$s', $comment_id, $blog_id, $exception->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
		}
	}

	ec_users_comment_notify_deliver( $blog_id, $comment_id );
}

/**
 * Deliver one approved comment's notifications.
 *
 * Re-validates everything at delivery time (the comment may have been
 * unapproved, or its post unpublished, before an async job ran).
 *
 * @param int $blog_id    Blog the comment lives on.
 * @param int $comment_id Comment ID.
 * @return array{reply:int[], event:int[]} Recipient IDs addressed by each producer.
 */
function ec_users_comment_notify_deliver( $blog_id, $comment_id ): array {
	$blog_id    = (int) $blog_id;
	$comment_id = (int) $comment_id;
	$addressed  = array(
		'reply' => array(),
		'event' => array(),
	);

	if ( $blog_id <= 0 || $comment_id <= 0 || ! function_exists( 'ec_users_notify_with_receipts' ) ) {
		return $addressed;
	}

	if ( ! function_exists( 'ec_users_is_comment_activity_blog' ) || ! ec_users_is_comment_activity_blog( $blog_id ) ) {
		return $addressed;
	}

	$switched = get_current_blog_id() !== $blog_id;
	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		$context = ec_users_comment_notify_context( $comment_id );
		if ( null === $context ) {
			return $addressed;
		}

		$actor_id = $context['actor_id'];
		$key      = ec_users_comment_notify_idempotency_key( $comment_id, $blog_id );

		// (a) Reply to another member's comment.
		$reply_recipient = ec_users_comment_notify_reply_recipient( $context['comment'], $actor_id );
		if ( $reply_recipient > 0 ) {
			ec_users_notify_with_receipts(
				$reply_recipient,
				array(
					'actor_id'        => $actor_id,
					'type'            => EC_USERS_COMMENT_REPLY_TYPE,
					'title'           => $context['title'],
					'link'            => $context['link'],
					'item_id'         => $context['post']->ID,
					'producer'        => EC_USERS_COMMENT_REPLY_PRODUCER,
					'idempotency_key' => $key,
				)
			);
			$addressed['reply'] = array( $reply_recipient );
		}

		// (b) Comment on an event the member marked as going.
		if ( EC_USERS_COMMENT_NOTIFY_EVENT_POST_TYPE === $context['post']->post_type && function_exists( 'ec_users_get_event_marked_user_ids' ) ) {
			$exclude    = array_merge( array( $actor_id ), $addressed['reply'] );
			$recipients = array();
			foreach ( ec_users_get_event_marked_user_ids( (int) $context['post']->ID, $blog_id ) as $user_id ) {
				if ( in_array( $user_id, $exclude, true ) || ec_users_comment_notify_recipient_blocked( $user_id, $actor_id ) ) {
					continue;
				}
				$recipients[] = $user_id;
			}

			if ( ! empty( $recipients ) ) {
				ec_users_notify_with_receipts(
					$recipients,
					array(
						'actor_id'        => $actor_id,
						'type'            => EC_USERS_EVENT_COMMENT_TYPE,
						'title'           => $context['title'],
						'link'            => $context['link'],
						'item_id'         => $context['post']->ID,
						'producer'        => EC_USERS_EVENT_COMMENT_PRODUCER,
						'idempotency_key' => $key,
					)
				);
				$addressed['event'] = $recipients;
			}
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $addressed;
}
add_action( EC_USERS_COMMENT_NOTIFY_ACTION, 'ec_users_comment_notify_deliver', 10, 2 );

/**
 * Resolve a deliverable comment on the current blog.
 *
 * @param int $comment_id Comment ID.
 * @return array{comment:WP_Comment, post:WP_Post, actor_id:int, title:string, link:string}|null
 */
function ec_users_comment_notify_context( int $comment_id ) {
	$comment = get_comment( $comment_id );
	if ( ! $comment instanceof WP_Comment || '1' !== (string) $comment->comment_approved ) {
		return null;
	}

	if ( defined( 'EC_USERS_COMMENT_ACTIVITY_TYPES' ) && ! in_array( (string) $comment->comment_type, EC_USERS_COMMENT_ACTIVITY_TYPES, true ) ) {
		return null;
	}

	$actor_id = (int) $comment->user_id;
	if ( $actor_id <= 0 || ! get_userdata( $actor_id ) ) {
		return null;
	}

	$post = get_post( (int) $comment->comment_post_ID );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
		return null;
	}

	$link = (string) get_comment_link( $comment );
	if ( '' === $link ) {
		$link = (string) get_permalink( $post ) . '#comment-' . (int) $comment->comment_ID;
	}

	return array(
		'comment'  => $comment,
		'post'     => $post,
		'actor_id' => $actor_id,
		'title'    => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'link'     => $link,
	);
}

/**
 * The member to notify about a reply, or 0 for none.
 *
 * @param WP_Comment $comment  Approved reply.
 * @param int        $actor_id Reply author.
 * @return int
 */
function ec_users_comment_notify_reply_recipient( WP_Comment $comment, int $actor_id ): int {
	$parent_id = (int) $comment->comment_parent;
	if ( $parent_id <= 0 ) {
		return 0;
	}

	$parent = get_comment( $parent_id );
	if ( ! $parent instanceof WP_Comment || '1' !== (string) $parent->comment_approved ) {
		return 0;
	}

	$recipient = (int) $parent->user_id;
	if ( $recipient <= 0 || $recipient === $actor_id || ec_users_comment_notify_recipient_blocked( $recipient, $actor_id ) ) {
		return 0;
	}

	return $recipient;
}

/**
 * Whether a recipient has blocked the commenter.
 *
 * Uses community's member-block reader when it is loaded and otherwise reads
 * the same network user meta, because comments are posted on sites where the
 * community plugin is not active.
 *
 * @param int $recipient_id Candidate recipient.
 * @param int $actor_id     Commenter.
 * @return bool
 */
function ec_users_comment_notify_recipient_blocked( int $recipient_id, int $actor_id ): bool {
	if ( function_exists( 'extrachill_community_member_has_blocked' ) ) {
		return (bool) extrachill_community_member_has_blocked( $recipient_id, $actor_id );
	}

	$blocked = get_user_meta( $recipient_id, EC_USERS_COMMENT_NOTIFY_MEMBER_BLOCKS_META, true );

	return $actor_id > 0 && is_array( $blocked ) && in_array( $actor_id, array_map( 'intval', $blocked ), true );
}
