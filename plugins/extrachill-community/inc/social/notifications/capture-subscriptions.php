<?php
/**
 * Subscription Notification Capture
 *
 * Bridges bbPress topic subscriptions into the Extra Chill notification system.
 *
 * - Auto-subscribes a reply author to the topic (gated on the user preference
 *   provided by extrachill-users).
 * - Notifies all other topic subscribers when a new reply lands, routed through
 *   the shared extrachill_notify action into the network notification substrate.
 * - Disables bbPress's parallel subscription emails so all email flows through
 *   the existing extrachill-users digest sweep.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Capture subscription notifications on a new reply.
 *
 * Runs at priority 11 on `bbp_new_reply`, between the reply-capture handler
 * (priority 10) and the mention-capture handler (priority 12).
 *
 * - Auto-subscribes the reply author to the topic when the
 *   `ec_users_auto_subscribe_enabled` preference is ON (default ON). bbPress's
 *   `bbp_add_user_subscription` has an internal duplicate guard, so the call is
 *   safe to repeat.
 * - Notifies every topic subscriber except the topic author (already covered by
 *   the `reply` notification in capture-replies.php) and the reply author
 *   (mirrors bbPress's own recipient shaping in bbp_notify_topic_subscribers).
 *
 * @param int   $reply_id       Reply post ID.
 * @param int   $topic_id       Topic post ID.
 * @param int   $forum_id       Forum ID.
 * @param array $anonymous_data Anonymous user data.
 * @param int   $reply_author   Reply author user ID.
 */
function extrachill_capture_subscription_notifications( $reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author ) {
	// Bail if bbPress subscriptions are disabled site-wide.
	if ( ! function_exists( 'bbp_is_subscriptions_active' ) || ! bbp_is_subscriptions_active() ) {
		return;
	}

	$reply_id     = (int) $reply_id;
	$topic_id     = (int) $topic_id;
	$reply_author = (int) $reply_author;

	if ( ! $reply_id || ! $topic_id || ! $reply_author ) {
		return;
	}

	// Auto-subscribe the reply author when the preference is enabled. Default
	// is ON once extrachill-users ships the getter; before then the guard skips.
	if ( function_exists( 'ec_users_auto_subscribe_enabled' ) && ec_users_auto_subscribe_enabled( $reply_author ) ) {
		bbp_add_user_subscription( $reply_author, $topic_id );
	}

	// Recipients: topic subscribers minus the topic author (already gets the
	// `reply` notification from capture-replies.php) and the reply author
	// (mirrors bbPress's own recipient shaping in bbp_notify_topic_subscribers).
	$topic_author = (int) get_post_field( 'post_author', $topic_id );

	$subscribers = bbp_get_subscribers( $topic_id );
	if ( empty( $subscribers ) ) {
		return;
	}

	$excluded   = array( $reply_author, $topic_author );
	$recipients = array();

	foreach ( $subscribers as $subscriber_id ) {
		$subscriber_id = (int) $subscriber_id;

		if ( $subscriber_id <= 0 ) {
			continue;
		}

		if ( in_array( $subscriber_id, $excluded, true ) ) {
			continue;
		}

		$recipients[] = $subscriber_id;
	}

	if ( empty( $recipients ) ) {
		return;
	}

	$topic_title = get_the_title( $topic_id );
	$reply_link  = bbp_get_reply_url( $reply_id );

	// Fire through the shared action; the extrachill-users substrate accepts a
	// recipient array and inserts one notification row per user. Payload keys
	// follow the legacy shape used by capture-replies/capture-mentions; the
	// substrate reads topic_title/post_id with item_id as the canonical key.
	do_action( 'extrachill_notify', $recipients, array(
		'actor_id'    => $reply_author,
		'type'        => 'subscription',
		'topic_title' => $topic_title,
		'link'        => $reply_link,
		'item_id'     => $topic_id,
		'post_id'     => $topic_id,
	) );
}

// Hook into bbPress actions (priority 11 - between reply@10 and mention@12).
add_action( 'bbp_new_reply', 'extrachill_capture_subscription_notifications', 11, 5 );

/**
 * Disable bbPress's native subscription/forum emails.
 *
 * Ensures all notification email flows exclusively through the
 * extrachill-users digest sweep, which is type-agnostic and picks up the new
 * `subscription` notification type automatically.
 *
 * bbPress registers its `bbp_notify_*` callbacks at priority 11 during plugin
 * load. Running the removal on `bbp_init` (priority 99) guarantees it executes
 * after registration, and well before any `bbp_new_reply`/`bbp_new_topic` fire
 * on a front-end POST request.
 */
function extrachill_community_disable_bbp_subscription_emails() {
	remove_action( 'bbp_new_reply', 'bbp_notify_topic_subscribers', 11 );
	remove_action( 'bbp_new_topic', 'bbp_notify_forum_subscribers', 11 );
}
add_action( 'bbp_init', 'extrachill_community_disable_bbp_subscription_emails', 99 );
