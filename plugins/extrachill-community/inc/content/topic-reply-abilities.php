<?php
/**
 * Topic & Reply Abilities
 *
 * Abilities-first primitives for listing, reading, creating, and editing
 * bbPress topics and replies. Wraps bbp_insert_topic / bbp_insert_reply
 * and fires the bbp_new_topic / bbp_new_reply actions so that cache
 * invalidation, notifications, draft cleanup, and point recalculation
 * all trigger properly.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_topic_reply_abilities' );

/**
 * Register topic and reply CRUD abilities.
 */
function extrachill_community_register_topic_reply_abilities() {

	// ─── List Topics ───────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-list-topics',
		array(
			'label'               => __( 'List Topics', 'extrachill-community' ),
			'description'         => __( 'List topics for a forum or across all forums, with pagination.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'forum_id' => array( 'type' => 'integer', 'description' => 'Filter by forum ID (omit for all forums)' ),
					'per_page' => array( 'type' => 'integer', 'description' => 'Topics per page (default 20, max 100)' ),
					'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1)' ),
					'orderby'  => array( 'type' => 'string', 'description' => 'Order by: date, modified, title (default date)' ),
					'order'    => array( 'type' => 'string', 'description' => 'Sort order: ASC or DESC (default DESC)' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'topics'    => array( 'type' => 'array' ),
					'total'     => array( 'type' => 'integer' ),
					'pages'     => array( 'type' => 'integer' ),
					'page'      => array( 'type' => 'integer' ),
					'per_page'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_list_topics',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Get Topic ─────────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-get-topic',
		array(
			'label'               => __( 'Get Topic', 'extrachill-community' ),
			'description'         => __( 'Get a single topic with its content, metadata, and replies.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id'       => array( 'type' => 'integer', 'description' => 'Topic post ID' ),
					'include_replies' => array( 'type' => 'boolean', 'description' => 'Include replies (default true)' ),
					'replies_per_page' => array( 'type' => 'integer', 'description' => 'Replies per page (default 30, max 100)' ),
					'replies_page'   => array( 'type' => 'integer', 'description' => 'Replies page number (default 1)' ),
				),
				'required'   => array( 'topic_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'topic'   => array( 'type' => 'object' ),
					'replies' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_topic',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Create Topic ──────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-create-topic',
		array(
			'label'               => __( 'Create Topic', 'extrachill-community' ),
			'description'         => __( 'Create a new forum topic. Fires bbp_new_topic for notifications and cache.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'forum_id' => array( 'type' => 'integer', 'description' => 'Forum to post in' ),
					'title'    => array( 'type' => 'string', 'description' => 'Topic title' ),
					'content'  => array( 'type' => 'string', 'description' => 'Topic content (HTML allowed)' ),
					'user_id'  => array( 'type' => 'integer', 'description' => 'Author user ID (defaults to current user)' ),
				),
				'required'   => array( 'forum_id', 'title', 'content' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id'  => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'url'       => array( 'type' => 'string' ),
					'forum_id'  => array( 'type' => 'integer' ),
					'author_id' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_create_topic',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Create Reply ──────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-create-reply',
		array(
			'label'               => __( 'Create Reply', 'extrachill-community' ),
			'description'         => __( 'Post a reply to a topic. Fires bbp_new_reply for notifications and cache.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id' => array( 'type' => 'integer', 'description' => 'Topic to reply to' ),
					'content'  => array( 'type' => 'string', 'description' => 'Reply content (HTML allowed)' ),
					'reply_to' => array( 'type' => 'integer', 'description' => 'Parent reply ID for threaded replies (optional)' ),
					'user_id'  => array( 'type' => 'integer', 'description' => 'Author user ID (defaults to current user)' ),
				),
				'required'   => array( 'topic_id', 'content' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'reply_id'  => array( 'type' => 'integer' ),
					'topic_id'  => array( 'type' => 'integer' ),
					'forum_id'  => array( 'type' => 'integer' ),
					'url'       => array( 'type' => 'string' ),
					'author_id' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_create_reply',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	// ─── List Replies ──────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-list-replies',
		array(
			'label'               => __( 'List Replies', 'extrachill-community' ),
			'description'         => __( 'List replies for a topic with pagination.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id' => array( 'type' => 'integer', 'description' => 'Topic post ID' ),
					'per_page' => array( 'type' => 'integer', 'description' => 'Replies per page (default 30, max 100)' ),
					'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1)' ),
				),
				'required'   => array( 'topic_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'replies'  => array( 'type' => 'array' ),
					'total'    => array( 'type' => 'integer' ),
					'pages'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_list_replies',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callbacks ─────────────────────────────────────────────────────────

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
	if ( ! $post || $post->post_type !== bbp_get_topic_post_type() ) {
		return new WP_Error( 'not_a_topic', 'Post ID is not a valid topic.' );
	}

	if ( $post->post_status !== bbp_get_public_status_id() ) {
		return new WP_Error( 'topic_not_published', 'Topic is not published.' );
	}

	$topic = extrachill_community_format_topic( $post, true );
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

		$result['replies'] = $replies;
		$result['replies_total'] = (int) $reply_query->found_posts;
		$result['replies_pages'] = (int) $reply_query->max_num_pages;
	}

	return $result;
}

/**
 * Create a new topic.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_create_topic( $input ) {
	if ( ! function_exists( 'bbp_insert_topic' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$forum_id = isset( $input['forum_id'] ) ? (int) $input['forum_id'] : 0;
	$title    = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
	$content  = isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '';
	$user_id  = extrachill_community_resolve_user_id( $input );

	if ( ! $forum_id ) {
		return new WP_Error( 'missing_forum_id', 'A forum_id is required.' );
	}
	if ( empty( $title ) ) {
		return new WP_Error( 'missing_title', 'A title is required.' );
	}
	if ( empty( $content ) ) {
		return new WP_Error( 'missing_content', 'Content is required.' );
	}
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', 'A valid user is required.' );
	}

	// Validate forum exists.
	$forum = get_post( $forum_id );
	if ( ! $forum || $forum->post_type !== bbp_get_forum_post_type() ) {
		return new WP_Error( 'invalid_forum', 'Forum ID does not point to a valid forum.' );
	}

	$topic_data = array(
		'post_parent'  => $forum_id,
		'post_status'  => bbp_get_public_status_id(),
		'post_type'    => bbp_get_topic_post_type(),
		'post_author'  => $user_id,
		'post_content' => $content,
		'post_title'   => $title,
	);

	$topic_meta = array(
		'forum_id' => $forum_id,
	);

	$topic_id = bbp_insert_topic( $topic_data, $topic_meta );

	if ( ! $topic_id ) {
		return new WP_Error( 'create_failed', 'Failed to create topic.' );
	}

	// Fire bbp_new_topic so community hooks (cache, notifications, points, drafts) trigger.
	do_action( 'bbp_new_topic', $topic_id, $forum_id, array(), $user_id );

	return array(
		'topic_id'  => (int) $topic_id,
		'title'     => $title,
		'url'       => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $topic_id ) : get_permalink( $topic_id ),
		'forum_id'  => $forum_id,
		'author_id' => $user_id,
	);
}

/**
 * Create a reply to a topic.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_create_reply( $input ) {
	if ( ! function_exists( 'bbp_insert_reply' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	$content  = isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '';
	$reply_to = isset( $input['reply_to'] ) ? (int) $input['reply_to'] : 0;
	$user_id  = extrachill_community_resolve_user_id( $input );

	if ( ! $topic_id ) {
		return new WP_Error( 'missing_topic_id', 'A topic_id is required.' );
	}
	if ( empty( $content ) ) {
		return new WP_Error( 'missing_content', 'Content is required.' );
	}
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', 'A valid user is required.' );
	}

	// Validate topic exists.
	$topic = get_post( $topic_id );
	if ( ! $topic || $topic->post_type !== bbp_get_topic_post_type() ) {
		return new WP_Error( 'invalid_topic', 'Topic ID does not point to a valid topic.' );
	}

	$forum_id = function_exists( 'bbp_get_topic_forum_id' ) ? (int) bbp_get_topic_forum_id( $topic_id ) : (int) $topic->post_parent;

	$reply_data = array(
		'post_parent'  => $topic_id,
		'post_status'  => bbp_get_public_status_id(),
		'post_type'    => bbp_get_reply_post_type(),
		'post_author'  => $user_id,
		'post_content' => $content,
	);

	$reply_meta = array(
		'forum_id' => $forum_id,
		'topic_id' => $topic_id,
		'reply_to' => $reply_to,
	);

	$reply_id = bbp_insert_reply( $reply_data, $reply_meta );

	if ( ! $reply_id ) {
		return new WP_Error( 'create_failed', 'Failed to create reply.' );
	}

	// Fire bbp_new_reply so community hooks (cache, notifications, points, drafts) trigger.
	do_action( 'bbp_new_reply', $reply_id, $topic_id, $forum_id, array(), $user_id, false, $reply_to );

	return array(
		'reply_id'  => (int) $reply_id,
		'topic_id'  => $topic_id,
		'forum_id'  => $forum_id,
		'url'       => function_exists( 'bbp_get_reply_url' ) ? bbp_get_reply_url( $reply_id ) : get_permalink( $reply_id ),
		'author_id' => $user_id,
	);
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

// ─── Formatters ────────────────────────────────────────────────────────────────

/**
 * Format a topic post into a response array.
 *
 * @param WP_Post $post         Topic post object.
 * @param bool    $include_content Include full content (default false for list views).
 * @return array
 */
function extrachill_community_format_topic( $post, $include_content = false ) {
	$author = get_userdata( $post->post_author );

	$topic = array(
		'topic_id'     => (int) $post->ID,
		'title'        => $post->post_title,
		'forum_id'     => (int) $post->post_parent,
		'author_id'    => (int) $post->post_author,
		'author_name'  => $author ? $author->display_name : '',
		'date'         => $post->post_date_gmt,
		'modified'     => $post->post_modified_gmt,
		'reply_count'  => function_exists( 'bbp_get_topic_reply_count' ) ? (int) bbp_get_topic_reply_count( $post->ID ) : 0,
		'voice_count'  => function_exists( 'bbp_get_topic_voice_count' ) ? (int) bbp_get_topic_voice_count( $post->ID ) : 0,
		'url'          => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $post->ID ) : get_permalink( $post->ID ),
	);

	if ( $include_content ) {
		$topic['content'] = $post->post_content;
	}

	$upvote_count = (int) get_post_meta( $post->ID, 'upvote_count', true );
	if ( $upvote_count > 0 ) {
		$topic['upvote_count'] = $upvote_count;
	}

	return $topic;
}

/**
 * Format a reply post into a response array.
 *
 * @param WP_Post $post Reply post object.
 * @return array
 */
function extrachill_community_format_reply( $post ) {
	$author = get_userdata( $post->post_author );

	$reply = array(
		'reply_id'    => (int) $post->ID,
		'topic_id'    => function_exists( 'bbp_get_reply_topic_id' ) ? (int) bbp_get_reply_topic_id( $post->ID ) : (int) $post->post_parent,
		'author_id'   => (int) $post->post_author,
		'author_name' => $author ? $author->display_name : '',
		'content'     => $post->post_content,
		'date'        => $post->post_date_gmt,
		'reply_to'    => function_exists( 'bbp_get_reply_to' ) ? (int) bbp_get_reply_to( $post->ID ) : 0,
	);

	$upvote_count = (int) get_post_meta( $post->ID, 'upvote_count', true );
	if ( $upvote_count > 0 ) {
		$reply['upvote_count'] = $upvote_count;
	}

	return $reply;
}
