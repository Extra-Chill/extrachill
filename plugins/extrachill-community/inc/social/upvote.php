<?php
/**
 * Upvote System
 *
 * Upvoting functionality for forum topics and replies.
 * Manages vote state, counts, and triggers point calculation hooks.
 *
 * @package ExtraChillCommunity
 */

/**
 * Process upvote for a topic or reply (business logic)
 *
 * @param int $post_id Post ID to upvote
 * @param string $type Post type ('topic' or 'reply')
 * @param int $user_id User ID performing the upvote
 * @return array Response array with success status, message, new_count, and upvoted flag
 */
function extrachill_process_upvote($post_id, $type, $user_id) {
	if ( ! $post_id ) {
		return array(
			'success' => false,
			'message' => 'No post ID provided',
		);
	}

	if ( ! $user_id ) {
		return array(
			'success' => false,
			'message' => 'User not logged in',
		);
	}

	if ( ! in_array($type, array( 'topic', 'reply' ), true) ) {
		return array(
			'success' => false,
			'message' => 'Invalid post type',
		);
	}

	$upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
	if ( ! is_array($upvoted_posts) ) {
		$upvoted_posts = array();
	}

	$post_author_id = get_post_field('post_author', $post_id);

	if ( in_array($post_id, $upvoted_posts, true) ) {
		// Remove upvote
		$upvoted_posts = array_diff($upvoted_posts, array( $post_id ));
		update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

		$upvote_count = max(get_post_meta($post_id, 'upvote_count', true) - 1, 0);
		update_post_meta($post_id, 'upvote_count', $upvote_count);

		$upvoted = false;
		do_action('custom_upvote_action', $post_id, $post_author_id, $upvoted);

		return array(
			'success'   => true,
			'message'   => 'Upvote removed',
			'new_count' => $upvote_count,
			'upvoted'   => false,
		);
	} else {
		// Add upvote
		$upvoted_posts[] = $post_id;
		update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

		$upvote_count = get_post_meta($post_id, 'upvote_count', true);
		$upvote_count = empty($upvote_count) ? 1 : intval($upvote_count) + 1;
		update_post_meta($post_id, 'upvote_count', $upvote_count);

		$upvoted = true;
		do_action('custom_upvote_action', $post_id, $post_author_id, $upvoted);

		return array(
			'success'   => true,
			'message'   => 'Upvote recorded',
			'new_count' => $upvote_count,
			'upvoted'   => true,
		);
	}
}

function get_upvote_count($post_id) {
	$count = get_post_meta($post_id, 'upvote_count', true);
	return is_numeric($count) ? intval($count) : 0;
}

function extrachill_get_upvoted_posts($post_type, $user_id = null) {
	$current_user_id = get_current_user_id();
	$upvoted         = $user_id ? array( $user_id ) : get_user_meta($current_user_id, 'upvoted_posts', true);

	if ( empty($upvoted) || ! is_array($upvoted) ) {
		return new WP_Query();
	}
	$paged = max( 1, get_query_var('paged'), get_query_var('page') );

	$args = array(
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'post__in'       => $upvoted,
		'posts_per_page' => get_option('posts_per_page'),
		'paged'          => $paged,
	);

	$posts_query = new WP_Query($args);
	return $posts_query;
}


/**
 * Get the total upvotes a user has received across their authored content.
 *
 * Reads an incrementally-maintained `extrachill_upvotes_received` user meta
 * counter that is kept current by extrachill_adjust_user_upvotes_received() on
 * every upvote add/remove (see the custom_upvote_action hook below). This
 * avoids the previous O(author lifetime post count) behaviour, where every
 * call ran an unbounded `posts_per_page => -1` WP_Query across all of the
 * user's posts/topics/replies and a get_post_meta() per result.
 *
 * The first time the counter is missing for a user it is lazily backfilled
 * once via extrachill_backfill_user_upvotes_received(). After backfill the
 * counter is authoritative and is never re-scanned.
 *
 * @param int $user_id WordPress user ID.
 * @return int Total upvotes received.
 */
function extrachill_get_user_total_upvotes($user_id) {
	$stored = get_user_meta( $user_id, 'extrachill_upvotes_received', true );

	// '' (never set) triggers a one-time backfill; '0' is a valid stored value.
	if ( '' === $stored || null === $stored ) {
		return extrachill_backfill_user_upvotes_received( $user_id );
	}

	return max( 0, intval( $stored ) );
}

/**
 * One-time backfill of the upvotes-received counter from authored content.
 *
 * This is the ONLY place the legacy full scan still runs, and only once per
 * user (when the counter has never been initialised). The result is persisted
 * to the `extrachill_upvotes_received` user meta so subsequent reads are O(1)
 * and all future changes are applied incrementally.
 *
 * @param int $user_id WordPress user ID.
 * @return int Backfilled upvotes-received total.
 */
function extrachill_backfill_user_upvotes_received($user_id) {
	$args = array(
		'author'                 => $user_id,
		'post_type'              => array( 'post', 'reply', 'topic' ),
		'post_status'            => 'any',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	);

	$user_posts_query = new WP_Query( $args );
	$user_posts_ids   = $user_posts_query->posts;

	$total_upvotes = 0;
	if ( is_array( $user_posts_ids ) && ! empty( $user_posts_ids ) ) {
		foreach ( $user_posts_ids as $post_id ) {
			$upvote         = get_post_meta( $post_id, 'upvote_count', true );
			$total_upvotes += intval( $upvote );
		}
	}

	$total_upvotes = max( 0, $total_upvotes );
	update_user_meta( $user_id, 'extrachill_upvotes_received', $total_upvotes );

	return $total_upvotes;
}

/**
 * Incrementally adjust a user's stored upvotes-received counter by a delta.
 *
 * Keeps the `extrachill_upvotes_received` user meta current without rescanning
 * the user's content. The matching points delta (0.5 per upvote) is applied
 * separately by extrachill_increment_user_points() on the same hook, so rank
 * display stays consistent between full recomputes.
 *
 * If the counter has never been initialised it is backfilled first so the
 * delta is applied to a correct base.
 *
 * @param int $user_id WordPress user ID of the content author.
 * @param int $delta    +1 when an upvote is added, -1 when removed.
 */
function extrachill_adjust_user_upvotes_received($user_id, $delta) {
	if ( empty( $user_id ) ) {
		return;
	}

	$stored = get_user_meta( $user_id, 'extrachill_upvotes_received', true );

	if ( '' === $stored || null === $stored ) {
		// Backfill establishes the base; it already reflects the new upvote
		// state because the post meta was updated before this hook fired.
		extrachill_backfill_user_upvotes_received( $user_id );
		return;
	}

	$new_total = max( 0, intval( $stored ) + intval( $delta ) );
	update_user_meta( $user_id, 'extrachill_upvotes_received', $new_total );
}

// Keep the incremental upvotes-received counter current on every upvote change.
add_action('custom_upvote_action', function( $post_id, $post_author_id, $upvoted ) {
	extrachill_adjust_user_upvotes_received( $post_author_id, $upvoted ? 1 : -1 );
}, 5, 3);
