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
		'post_status'    => bbp_get_public_status_id(),
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => $orderby,
		'order'          => $order,
	);

	if ( ! empty( $input['forum_id'] ) ) {
		$args['post_parent'] = (int) $input['forum_id'];
	}

	$query  = new WP_Query( $args );
	$topics = array();

	foreach ( $query->posts as $post ) {
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
		return new WP_Error( 'not_a_topic', 'Post ID is not a valid topic.' );
	}

	if ( bbp_get_public_status_id() !== $post->post_status ) {
		return new WP_Error( 'topic_not_published', 'Topic is not published.' );
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
