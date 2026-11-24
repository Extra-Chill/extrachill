<?php
/**
 * Network-wide comment policy.
 *
 * Every Extra Chill site defaults `default_comment_status` to open, so every
 * attachment and every comment-supporting post type stores
 * `comment_status = open`, including sites with no comment UX at all (auth,
 * docs, studio, wire, link pages). Spam bots post straight to
 * wp-comments-post.php with an arbitrary `comment_post_ID`. Disabling
 * attachment pages only redirects the view and does not close comments.
 *
 * This file is the one canonical policy. Comments (and pings) stay open only
 * for post types on the site allowlist below. Core's comment submission
 * handler checks comments_open(), so the filter governs both the rendered
 * form and wp-comments-post.php. This is the same pattern
 * extrachill-events uses in inc/single-event/comments.php.
 *
 * The filter runs at priority 20, after the events opt-in (priority 9) and
 * core's close-comments-for-old-posts check (priority 10). It can only close
 * comments. It never opens a post whose stored status is closed.
 *
 * Feature plugins opt a post type in through the
 * `extrachill_network_commentable_post_types` filter.
 *
 * It also rejects guest comments that use a registered account's email, so a
 * logged-out visitor cannot post under an existing user's identity.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default commentable post types keyed by logical site key.
 *
 * Sites not listed here take no comments at all. Attachments are never
 * listed.
 *
 * @return array<string,string[]>
 */
function extrachill_network_default_commentable_post_types() {
	return array(
		'main'       => array( 'post' ),
		'events'     => array( 'data_machine_events' ),
		'newsletter' => array( 'newsletter' ),
	);
}

/**
 * Post types that accept comments on a blog.
 *
 * @param int $blog_id Blog ID.
 * @return string[]
 */
function extrachill_network_commentable_post_types( $blog_id ) {
	$blog_id  = (int) $blog_id;
	$defaults = extrachill_network_default_commentable_post_types();
	$types    = array();

	foreach ( $defaults as $site_key => $site_types ) {
		if ( (int) ec_get_blog_id( $site_key ) === $blog_id ) {
			$types = $site_types;
			break;
		}
	}

	/**
	 * Filter the post types that accept comments on a blog.
	 *
	 * Feature plugins that own a comment UX add their post type here.
	 *
	 * @param string[] $types   Commentable post type slugs.
	 * @param int      $blog_id Blog ID.
	 */
	$types = apply_filters( 'extrachill_network_commentable_post_types', $types, $blog_id );

	return is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : array();
}

/**
 * Whether the network policy allows discussion on a post.
 *
 * @param int|WP_Post|null $post Post ID or object.
 * @return bool
 */
function extrachill_network_post_allows_comments( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}

	if ( 'attachment' === $post->post_type ) {
		return false;
	}

	return in_array( $post->post_type, extrachill_network_commentable_post_types( get_current_blog_id() ), true );
}

/**
 * Close comments and pings on content outside the network policy.
 *
 * @hook comments_open
 * @hook pings_open
 * @param bool             $open    Whether discussion is open.
 * @param int|WP_Post|null $post_id Post ID or object.
 * @return bool
 */
function extrachill_network_filter_discussion_open( $open, $post_id ) {
	if ( ! $open ) {
		return false;
	}

	return extrachill_network_post_allows_comments( $post_id );
}
add_filter( 'comments_open', 'extrachill_network_filter_discussion_open', 20, 2 );
add_filter( 'pings_open', 'extrachill_network_filter_discussion_open', 20, 2 );

/**
 * Reject guest comments that use a registered account's email.
 *
 * A logged-in commenter's email comes from their account, so user_id is set.
 * A guest comment with user_id 0 and a registered email is impersonation.
 * The commenter has to log in instead.
 *
 * Runs after extrachill-users' logged-in auto-approval (priority 10).
 *
 * @hook pre_comment_approved
 * @param int|string|WP_Error $approved    Approval status.
 * @param array               $commentdata Comment data.
 * @return int|string|WP_Error
 */
function extrachill_network_reject_guest_registered_email( $approved, $commentdata ) {
	if ( is_wp_error( $approved ) ) {
		return $approved;
	}

	if ( ! empty( $commentdata['user_id'] ) || is_user_logged_in() ) {
		return $approved;
	}

	$type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
	if ( '' !== $type && 'comment' !== $type ) {
		return $approved;
	}

	$email = isset( $commentdata['comment_author_email'] ) ? trim( (string) $commentdata['comment_author_email'] ) : '';
	if ( '' === $email || ! email_exists( $email ) ) {
		return $approved;
	}

	return new WP_Error(
		'comment_registered_email',
		__( 'That email belongs to a registered account. Please log in to comment.', 'extrachill-network' ),
		403
	);
}
add_filter( 'pre_comment_approved', 'extrachill_network_reject_guest_registered_email', 20, 2 );
