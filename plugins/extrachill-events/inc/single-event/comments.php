<?php
/**
 * Single Event Comments
 *
 * Event discussion runs on plain WordPress comments on the event page (#1632).
 * data-machine-events registers `data_machine_events` without `comments`
 * support, so Extra Chill adds it here instead of editing that generic plugin.
 *
 * Single events render through the theme's single-post template, which already
 * shows the theme comments template (inc/single/comments.php) under the event
 * details whenever comments are open. No event-specific comments template.
 *
 * New events: once the post type supports comments, core's
 * get_default_comment_status() returns the site's `default_comment_status`
 * option, so new events follow the site's discussion settings without an
 * extra filter.
 *
 * Existing events: core stored `comment_status = closed` on every event
 * inserted while the post type lacked comments support. That value reflects
 * the missing support, not an editorial decision, so rather than rewriting
 * thousands of rows we open those events at read time through `comments_open`
 * whenever the site's discussion settings default to open. Core's comment
 * submission handler checks comments_open(), so the filter governs both the
 * rendered form and wp-comments-post.php.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event post type slug owned by data-machine-events.
 */
if ( ! defined( 'EXTRACHILL_EVENTS_COMMENTS_POST_TYPE' ) ) {
	define( 'EXTRACHILL_EVENTS_COMMENTS_POST_TYPE', 'data_machine_events' );
}

/**
 * Add comments support to the event post type.
 *
 * data-machine-events registers the post type on init priority 0. Adding the
 * feature afterwards is safe either way: add_post_type_support() only records
 * the feature, and register_post_type() merges rather than resets features.
 *
 * @hook init
 * @return void
 */
function extrachill_events_add_comments_support() {
	add_post_type_support( EXTRACHILL_EVENTS_COMMENTS_POST_TYPE, 'comments' );
}
add_action( 'init', 'extrachill_events_add_comments_support', 5 );

/**
 * Open comments on events whose stored status predates comments support.
 *
 * Runs at priority 9 so core's close-comments-for-old-posts check (priority
 * 10) still applies afterwards when a site enables it for events.
 *
 * @hook comments_open
 * @param bool            $open    Whether comments are open.
 * @param int|WP_Post|null $post_id Post ID or object.
 * @return bool
 */
function extrachill_events_comments_open( $open, $post_id ) {
	if ( $open ) {
		return $open;
	}

	$post = get_post( $post_id );
	if ( ! $post || EXTRACHILL_EVENTS_COMMENTS_POST_TYPE !== $post->post_type ) {
		return $open;
	}

	if ( ! post_type_supports( EXTRACHILL_EVENTS_COMMENTS_POST_TYPE, 'comments' ) ) {
		return $open;
	}

	$site_default_open = 'open' === get_option( 'default_comment_status' );

	/**
	 * Filter whether an event with a stored closed comment status is open.
	 *
	 * Defaults to the site's discussion setting. Return false to keep a
	 * specific event closed.
	 *
	 * @param bool    $open Whether comments are open on the event.
	 * @param WP_Post $post Event post.
	 */
	return (bool) apply_filters( 'extrachill_events_comments_open', $site_default_open, $post );
}
add_filter( 'comments_open', 'extrachill_events_comments_open', 9, 2 );
