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
	if (!$post_id) {
		return array('success' => false, 'message' => 'No post ID provided');
	}

	if (!$user_id) {
		return array('success' => false, 'message' => 'User not logged in');
	}

	if (!in_array($type, array('topic', 'reply'))) {
		return array('success' => false, 'message' => 'Invalid post type');
	}

	$upvoted_posts = get_user_meta($user_id, 'upvoted_posts', true);
	if (!is_array($upvoted_posts)) {
		$upvoted_posts = array();
	}

	$post_author_id = get_post_field('post_author', $post_id);

	if (in_array($post_id, $upvoted_posts)) {
		// Remove upvote
		$upvoted_posts = array_diff($upvoted_posts, array($post_id));
		update_user_meta($user_id, 'upvoted_posts', $upvoted_posts);

		$upvote_count = max(get_post_meta($post_id, 'upvote_count', true) - 1, 0);
		update_post_meta($post_id, 'upvote_count', $upvote_count);

		$upvoted = false;
		do_action('custom_upvote_action', $post_id, $post_author_id, $upvoted);

		return array(
			'success' => true,
			'message' => 'Upvote removed',
			'new_count' => $upvote_count,
			'upvoted' => false
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
			'success' => true,
			'message' => 'Upvote recorded',
			'new_count' => $upvote_count,
			'upvoted' => true
		);
	}
}

/**
 * Admin-ajax handler for upvote action (thin routing layer)
 */
function handle_upvote_action() {
	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
	$type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
	$user_id = get_current_user_id();

	if (!check_ajax_referer('upvote_nonce', 'nonce', false)) {
		wp_send_json_error(array('message' => 'Security check failed'));
		return;
	}

	$result = extrachill_process_upvote($post_id, $type, $user_id);

	if ($result['success']) {
		wp_send_json_success($result);
	} else {
		wp_send_json_error($result);
	}

	wp_die();
}

function get_upvote_count($post_id) {
	$count = get_post_meta($post_id, 'upvote_count', true);
	return is_numeric($count) ? intval($count) : 0;
}

function extrachill_get_upvoted_posts($post_type, $user_id = null) {
	$current_user_id = get_current_user_id();
	$upvoted = $user_id ? array($user_id) : get_user_meta($current_user_id, 'upvoted_posts', true);

	if (empty($upvoted) || !is_array($upvoted)) {
		return new WP_Query();
	}
	$paged = max( 1, get_query_var('paged'), get_query_var('page') );

	$args = array(
		'post_type' => $post_type,
		'post_status' => 'publish',
		'post__in' => $upvoted,
		'posts_per_page' => get_option('posts_per_page'),
		'paged' => $paged,
	);

	$posts_query = new WP_Query($args);
	return $posts_query;
}


function extrachill_get_user_total_upvotes($user_id) {
	$args = array(
		'author'         => $user_id,
		'post_type'      => array('post', 'reply', 'topic'),
		'posts_per_page' => -1,
		'fields'         => 'ids'
	);

	$user_posts_query = new WP_Query( $args );
	$user_posts_ids = $user_posts_query->posts;

	$total_upvotes = 0;
	if ( is_array( $user_posts_ids ) && !empty( $user_posts_ids ) ) {
		foreach ( $user_posts_ids as $post_id ) {
			$upvote = get_post_meta( $post_id, 'upvote_count', true );
			$total_upvotes += intval( $upvote );
		}
	}
	return $total_upvotes;
}
