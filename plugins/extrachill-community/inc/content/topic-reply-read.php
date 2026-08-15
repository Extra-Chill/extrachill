<?php
/**
 * Topic & Reply Read Abilities
 *
 * Execute callbacks for the read-only topic/reply abilities: list topics,
 * get a single topic (with optional replies), and list replies.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * List topics with pagination.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_list_topics( $input ) {
	if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$per_page = isset( $input['per_page'] ) ? min( max( (int) $input['per_page'], 1 ), 100 ) : 20;
	$page     = isset( $input['page'] ) ? max( (int) $input['page'], 1 ) : 1;
	$orderby  = isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'modified', 'title' ), true )
		? $input['orderby'] : 'date';
	$order    = isset( $input['order'] ) && in_array( strtoupper( $input['order'] ), array( 'ASC', 'DESC' ), true )
		? strtoupper( $input['order'] ) : 'DESC';

	$args = array(
		'post_type'      => bbp_get_topic_post_type(),
		'post_status'    => array( bbp_get_public_status_id(), bbp_get_closed_status_id() ),
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => $orderby,
		'order'          => $order,
	);

	if ( ! empty( $input['forum_id'] ) ) {
		$forum_id = (int) $input['forum_id'];
		if ( ! extrachill_community_can_read_forum( $forum_id ) ) {
			return new WP_Error( 'forum_not_found', 'Forum is unavailable.', array( 'status' => 404 ) );
		}
		$args['post_parent'] = $forum_id;
	} else {
		$forum_ids = extrachill_community_get_readable_forum_ids();
		if ( empty( $forum_ids ) ) {
			return array(
				'topics'   => array(),
				'total'    => 0,
				'pages'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}
		$args['post_parent__in'] = $forum_ids;
	}

	$query  = new WP_Query( $args );
	$topics = array();

	foreach ( $query->posts as $post ) {
		if ( ! extrachill_community_can_read_forum( $post->post_parent ) ) {
			continue;
		}
		$topics[] = extrachill_community_format_topic( $post );
	}

	return array(
		'topics'   => $topics,
		'total'    => (int) $query->found_posts,
		'pages'    => (int) $query->max_num_pages,
		'page'     => $page,
		'per_page' => $per_page,
	);
}

/**
 * Get a single topic with optional replies.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_topic( $input ) {
	if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	if ( ! $topic_id ) {
		return new WP_Error( 'missing_topic_id', 'A topic_id is required.' );
	}

	$post = get_post( $topic_id );
	if ( ! $post || bbp_get_topic_post_type() !== $post->post_type ) {
		return new WP_Error( 'not_a_topic', 'Post ID is not a valid topic.', array( 'status' => 404 ) );
	}

	if ( ! in_array( $post->post_status, array( bbp_get_public_status_id(), bbp_get_closed_status_id() ), true ) || ! extrachill_community_can_read_forum( $post->post_parent ) ) {
		return new WP_Error( 'topic_not_published', 'Topic is not published.', array( 'status' => 404 ) );
	}

	$topic  = extrachill_community_format_topic( $post, true );
	$result = array( 'topic' => $topic );

	$include_replies = isset( $input['include_replies'] ) ? (bool) $input['include_replies'] : true;

	if ( $include_replies ) {
		$replies_per_page = isset( $input['replies_per_page'] ) ? min( max( (int) $input['replies_per_page'], 1 ), 100 ) : 30;
		$replies_page     = isset( $input['replies_page'] ) ? max( (int) $input['replies_page'], 1 ) : 1;

		$reply_query = new WP_Query(
			array(
				'post_type'      => bbp_get_reply_post_type(),
				'post_parent'    => $topic_id,
				'post_status'    => bbp_get_public_status_id(),
				'posts_per_page' => $replies_per_page,
				'paged'          => $replies_page,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$replies = array();
		foreach ( $reply_query->posts as $reply_post ) {
			$replies[] = extrachill_community_format_reply( $reply_post );
		}

		$result['replies']       = $replies;
		$result['replies_total'] = (int) $reply_query->found_posts;
		$result['replies_pages'] = (int) $reply_query->max_num_pages;
	}

	return $result;
}

/**
 * List replies for a topic with pagination.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_list_replies( $input ) {
	if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	if ( ! $topic_id ) {
		return new WP_Error( 'missing_topic_id', 'A topic_id is required.' );
	}

	$topic = get_post( $topic_id );
	if ( ! $topic || bbp_get_topic_post_type() !== $topic->post_type || ! in_array( $topic->post_status, array( bbp_get_public_status_id(), bbp_get_closed_status_id() ), true ) || ! extrachill_community_can_read_forum( $topic->post_parent ) ) {
		return new WP_Error( 'topic_not_published', 'Topic is not published.', array( 'status' => 404 ) );
	}

	$per_page = isset( $input['per_page'] ) ? min( max( (int) $input['per_page'], 1 ), 100 ) : 30;
	$page     = isset( $input['page'] ) ? max( (int) $input['page'], 1 ) : 1;

	$query = new WP_Query(
		array(
			'post_type'      => bbp_get_reply_post_type(),
			'post_parent'    => $topic_id,
			'post_status'    => bbp_get_public_status_id(),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	);

	$replies = array();
	foreach ( $query->posts as $post ) {
		$replies[] = extrachill_community_format_reply( $post );
	}

	return array(
		'replies'  => $replies,
		'total'    => (int) $query->found_posts,
		'pages'    => (int) $query->max_num_pages,
		'page'     => $page,
		'per_page' => $per_page,
	);
}
