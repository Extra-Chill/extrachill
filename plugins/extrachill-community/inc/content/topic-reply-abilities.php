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
			'label'               => __( 'List Topics', 'extra-chill-community' ),
			'description'         => __( 'List topics for a forum or across all forums, with pagination.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'forum_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by forum ID (omit for all forums)',
					),
					'per_page' => array(
						'type'        => 'integer',
						'description' => 'Topics per page (default 20, max 100)',
					),
					'page'     => array(
						'type'        => 'integer',
						'description' => 'Page number (default 1)',
					),
					'orderby'  => array(
						'type'        => 'string',
						'description' => 'Order by: date, modified, title (default date)',
					),
					'order'    => array(
						'type'        => 'string',
						'description' => 'Sort order: ASC or DESC (default DESC)',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'topics'   => array( 'type' => 'array' ),
					'total'    => array( 'type' => 'integer' ),
					'pages'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
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
			'label'               => __( 'Get Topic', 'extra-chill-community' ),
			'description'         => __( 'Get a single topic with its content, metadata, and replies.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id'         => array(
						'type'        => 'integer',
						'description' => 'Topic post ID',
					),
					'include_replies'  => array(
						'type'        => 'boolean',
						'description' => 'Include replies (default true)',
					),
					'replies_per_page' => array(
						'type'        => 'integer',
						'description' => 'Replies per page (default 30, max 100)',
					),
					'replies_page'     => array(
						'type'        => 'integer',
						'description' => 'Replies page number (default 1)',
					),
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
			'label'               => __( 'Create Topic', 'extra-chill-community' ),
			'description'         => __( 'Create a new forum topic. Accepts HTML (default) or markdown via the format parameter. Fires bbp_new_topic for notifications and cache.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'forum_id' => array(
						'type'        => 'integer',
						'description' => 'Forum to post in',
					),
					'title'    => array(
						'type'        => 'string',
						'description' => 'Topic title',
					),
					'content'  => array(
						'type'        => 'string',
						'description' => 'Topic content (HTML or markdown depending on format)',
					),
					'format'   => array(
						'type'        => 'string',
						'enum'        => array( 'html', 'markdown' ),
						'description' => 'Content format. "html" (default) is sanitised via wp_kses_post. "markdown" is converted to Gutenberg blocks via bfb_convert() before sanitisation.',
					),
					'user_id'  => array(
						'type'        => 'integer',
						'description' => 'Author user ID (defaults to current user)',
					),
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
			'label'               => __( 'Create Reply', 'extra-chill-community' ),
			'description'         => __( 'Post a reply to a topic. Accepts HTML (default) or markdown via the format parameter. Fires bbp_new_reply for notifications and cache.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id' => array(
						'type'        => 'integer',
						'description' => 'Topic to reply to',
					),
					'content'  => array(
						'type'        => 'string',
						'description' => 'Reply content (HTML or markdown depending on format)',
					),
					'format'   => array(
						'type'        => 'string',
						'enum'        => array( 'html', 'markdown' ),
						'description' => 'Content format. "html" (default) is sanitised via wp_kses_post. "markdown" is converted to Gutenberg blocks via bfb_convert() before sanitisation.',
					),
					'reply_to' => array(
						'type'        => 'integer',
						'description' => 'Parent reply ID for threaded replies (optional)',
					),
					'user_id'  => array(
						'type'        => 'integer',
						'description' => 'Author user ID (defaults to current user)',
					),
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

	// ─── Get Topic For Editor ──────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-get-topic-for-editor',
		array(
			'label'               => __( 'Get Topic For Editor', 'extra-chill-community' ),
			'description'         => __( 'Load a topic for editing: returns serialized post_content (block markup as-stored), permissions envelope, and any pre-publish draft overlay for the current user.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id' => array( 'type' => 'integer' ),
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => 'Optional; defaults to current blog.',
					),
				),
				'required'   => array( 'topic_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'type'        => array(
						'type' => 'string',
						'enum' => array( 'forum_topic' ),
					),
					'title'       => array( 'type' => 'string' ),
					'content'     => array(
						'type'        => 'string',
						'description' => 'Serialized block markup (post_content as-stored).',
					),
					'raw'         => array(
						'type'        => 'string',
						'description' => 'Alias of content for parity with wp/v2 shape.',
					),
					'status'      => array( 'type' => 'string' ),
					'forum_id'    => array( 'type' => 'integer' ),
					'permalink'   => array( 'type' => 'string' ),
					'updated_at'  => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'context'     => array(
						'type'       => 'object',
						'properties' => array(
							'blog_id'  => array( 'type' => 'integer' ),
							'forum_id' => array( 'type' => 'integer' ),
						),
					),
					'permissions' => array(
						'type'       => 'object',
						'properties' => array(
							'canSave'        => array( 'type' => 'boolean' ),
							'canUploadMedia' => array( 'type' => 'boolean' ),
							'canDelete'      => array( 'type' => 'boolean' ),
						),
					),
					'draft'       => array(
						'description' => 'Pre-publish draft overlay if one exists for this user+target. Null when none.',
						'anyOf'       => array(
							array( 'type' => 'object' ),
							array( 'type' => 'null' ),
						),
					),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_topic_for_editor',
			'permission_callback' => 'extrachill_community_ability_get_topic_for_editor_permission',
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

	// ─── Get Reply For Editor ──────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-get-reply-for-editor',
		array(
			'label'               => __( 'Get Reply For Editor', 'extra-chill-community' ),
			'description'         => __( 'Load a reply for editing: returns serialized post_content, permissions envelope, parent topic context.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'reply_id' => array( 'type' => 'integer' ),
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => 'Optional; defaults to current blog.',
					),
				),
				'required'   => array( 'reply_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'type'        => array(
						'type' => 'string',
						'enum' => array( 'forum_reply' ),
					),
					'content'     => array( 'type' => 'string' ),
					'raw'         => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'string' ),
					'topic_id'    => array( 'type' => 'integer' ),
					'forum_id'    => array( 'type' => 'integer' ),
					'reply_to'    => array( 'type' => 'integer' ),
					'permalink'   => array( 'type' => 'string' ),
					'updated_at'  => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'context'     => array(
						'type'       => 'object',
						'properties' => array(
							'blog_id'  => array( 'type' => 'integer' ),
							'topic_id' => array( 'type' => 'integer' ),
							'forum_id' => array( 'type' => 'integer' ),
						),
					),
					'permissions' => array(
						'type'       => 'object',
						'properties' => array(
							'canSave'        => array( 'type' => 'boolean' ),
							'canUploadMedia' => array( 'type' => 'boolean' ),
							'canDelete'      => array( 'type' => 'boolean' ),
						),
					),
					'draft'       => array(
						'anyOf' => array(
							array( 'type' => 'object' ),
							array( 'type' => 'null' ),
						),
					),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_reply_for_editor',
			'permission_callback' => 'extrachill_community_ability_get_reply_for_editor_permission',
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

	// ─── Update Topic ──────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-update-topic',
		array(
			'label'               => __( 'Update Topic', 'extra-chill-community' ),
			'description'         => __( 'Update an existing topic\'s title and/or content. Accepts HTML (default, serialized blocks) or markdown via the format parameter. Enforces bbp_past_edit_lock and fires bbp_edit_topic for cache invalidation.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id' => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'content'  => array(
						'type'        => 'string',
						'description' => 'Serialized block markup.',
					),
					'format'   => array(
						'type'        => 'string',
						'enum'        => array( 'html', 'markdown' ),
						'description' => 'Content format. "html" (default) is sanitised via wp_kses_post. "markdown" is converted to Gutenberg blocks via bfb_convert() before sanitisation.',
					),
					'blog_id'  => array( 'type' => 'integer' ),
					'user_id'  => array(
						'type'        => 'integer',
						'description' => 'Author override; requires edit_others_topics.',
					),
				),
				'required'   => array( 'topic_id', 'content' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'         => array( 'type' => 'integer' ),
					'status'     => array( 'type' => 'string' ),
					'title'      => array( 'type' => 'string' ),
					'content'    => array( 'type' => 'string' ),
					'permalink'  => array( 'type' => 'string' ),
					'updated_at' => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_update_topic',
			'permission_callback' => 'extrachill_community_ability_update_topic_permission',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Update Reply ──────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-update-reply',
		array(
			'label'               => __( 'Update Reply', 'extra-chill-community' ),
			'description'         => __( 'Update an existing reply\'s content. Accepts HTML (default, serialized blocks) or markdown via the format parameter. Enforces bbp_past_edit_lock and fires bbp_edit_reply for cache invalidation.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'reply_id' => array( 'type' => 'integer' ),
					'content'  => array(
						'type'        => 'string',
						'description' => 'Serialized block markup.',
					),
					'format'   => array(
						'type'        => 'string',
						'enum'        => array( 'html', 'markdown' ),
						'description' => 'Content format.',
					),
					'blog_id'  => array( 'type' => 'integer' ),
					'user_id'  => array(
						'type'        => 'integer',
						'description' => 'Author override; requires edit_others_replies.',
					),
				),
				'required'   => array( 'reply_id', 'content' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'         => array( 'type' => 'integer' ),
					'status'     => array( 'type' => 'string' ),
					'content'    => array( 'type' => 'string' ),
					'permalink'  => array( 'type' => 'string' ),
					'updated_at' => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_update_reply',
			'permission_callback' => 'extrachill_community_ability_update_reply_permission',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── List Replies ──────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/community-list-replies',
		array(
			'label'               => __( 'List Replies', 'extra-chill-community' ),
			'description'         => __( 'List replies for a topic with pagination.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'topic_id' => array(
						'type'        => 'integer',
						'description' => 'Topic post ID',
					),
					'per_page' => array(
						'type'        => 'integer',
						'description' => 'Replies per page (default 30, max 100)',
					),
					'page'     => array(
						'type'        => 'integer',
						'description' => 'Page number (default 1)',
					),
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
 * Create a new topic.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_create_topic( $input ) {
	if ( ! function_exists( 'bbp_insert_topic' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$forum_id    = isset( $input['forum_id'] ) ? (int) $input['forum_id'] : 0;
	$title       = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
	$raw_content = isset( $input['content'] ) ? (string) $input['content'] : '';
	$format      = isset( $input['format'] ) ? (string) $input['format'] : 'html';
	$content     = wp_kses_post( extrachill_community_maybe_convert_markdown( $raw_content, $format ) );
	$user_id     = extrachill_community_resolve_user_id( $input );

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

	$topic_id    = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	$raw_content = isset( $input['content'] ) ? (string) $input['content'] : '';
	$format      = isset( $input['format'] ) ? (string) $input['format'] : 'html';
	$content     = wp_kses_post( extrachill_community_maybe_convert_markdown( $raw_content, $format ) );
	$reply_to    = isset( $input['reply_to'] ) ? (int) $input['reply_to'] : 0;
	$user_id     = extrachill_community_resolve_user_id( $input );

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

// ─── Editor: permission callbacks ──────────────────────────────────────────────

/**
 * Permission: can the current caller load this topic for editing?
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_get_topic_for_editor_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	if ( $topic_id <= 0 ) {
		return false;
	}
	return current_user_can( 'read_topic', $topic_id );
}

/**
 * Permission: can the current caller load this reply for editing?
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_get_reply_for_editor_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$reply_id = isset( $input['reply_id'] ) ? (int) $input['reply_id'] : 0;
	if ( $reply_id <= 0 ) {
		return false;
	}
	return current_user_can( 'read_reply', $reply_id );
}

/**
 * Permission: can the current caller update this topic?
 *
 * Enforces edit_topic cap + bbp_past_edit_lock window (matches existing UI guard
 * in loop-single-reply-card.php).
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_update_topic_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	if ( $topic_id <= 0 ) {
		return false;
	}
	if ( ! current_user_can( 'edit_topic', $topic_id ) ) {
		return false;
	}
	if ( function_exists( 'bbp_past_edit_lock' ) ) {
		$post = get_post( $topic_id );
		if ( $post && bbp_past_edit_lock( $post->post_date_gmt ) ) {
			return new WP_Error(
				'edit_lock_expired',
				__( 'The edit window for this topic has expired.', 'extra-chill-community' ),
				array( 'status' => 403 )
			);
		}
	}
	return true;
}

/**
 * Permission: can the current caller update this reply?
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_update_reply_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$reply_id = isset( $input['reply_id'] ) ? (int) $input['reply_id'] : 0;
	if ( $reply_id <= 0 ) {
		return false;
	}
	if ( ! current_user_can( 'edit_reply', $reply_id ) ) {
		return false;
	}
	if ( function_exists( 'bbp_past_edit_lock' ) ) {
		$post = get_post( $reply_id );
		if ( $post && bbp_past_edit_lock( $post->post_date_gmt ) ) {
			return new WP_Error(
				'edit_lock_expired',
				__( 'The edit window for this reply has expired.', 'extra-chill-community' ),
				array( 'status' => 403 )
			);
		}
	}
	return true;
}

// ─── Editor: execute callbacks ─────────────────────────────────────────────────

/**
 * Build the permissions envelope for a topic/reply load response.
 *
 * Computed from the current user's caps so the native client can disable
 * buttons pre-submit. Matches the contract documented in extrachill-multisite#33.
 *
 * @param int    $post_id Post ID.
 * @param string $type    Either 'topic' or 'reply'.
 * @return array{canSave: bool, canUploadMedia: bool, canDelete: bool}
 */
function extrachill_community_build_editor_permissions( $post_id, $type ) {
	$edit_cap   = ( 'topic' === $type ) ? 'edit_topic' : 'edit_reply';
	$delete_cap = ( 'topic' === $type ) ? 'delete_topic' : 'delete_reply';

	$can_save = current_user_can( $edit_cap, $post_id );
	if ( $can_save && function_exists( 'bbp_past_edit_lock' ) ) {
		$post = get_post( $post_id );
		if ( $post && bbp_past_edit_lock( $post->post_date_gmt ) ) {
			$can_save = false;
		}
	}

	return array(
		'canSave'        => (bool) $can_save,
		'canUploadMedia' => (bool) ( is_user_logged_in() && current_user_can( 'upload_files' ) ),
		'canDelete'      => (bool) current_user_can( $delete_cap, $post_id ),
	);
}

/**
 * Look up the pre-publish draft overlay for a given target, if one exists.
 *
 * Uses the existing draft ability helpers so the load envelope and the
 * dedicated draft ability stay in sync. Returns null if no draft is stored.
 *
 * @param int    $user_id User ID.
 * @param string $type    'topic' or 'reply'.
 * @param array  $context Keys: blog_id, forum_id, topic_id, reply_to.
 * @return array|null
 */
function extrachill_community_get_draft_overlay( $user_id, $type, array $context ) {
	if ( ! function_exists( 'extrachill_community_bbpress_drafts_fetch' ) ) {
		return null;
	}
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return null;
	}
	$context['type']    = $type;
	$context['blog_id'] = isset( $context['blog_id'] ) ? (int) $context['blog_id'] : (int) get_current_blog_id();

	return extrachill_community_bbpress_drafts_fetch( $user_id, $context );
}

/**
 * Load a topic for the editor.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_topic_for_editor( $input ) {
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

	$forum_id = function_exists( 'bbp_get_topic_forum_id' )
		? (int) bbp_get_topic_forum_id( $topic_id )
		: (int) $post->post_parent;

	$blog_id = (int) get_current_blog_id();

	$draft = extrachill_community_get_draft_overlay(
		get_current_user_id(),
		'topic',
		array(
			'blog_id'  => $blog_id,
			'forum_id' => $forum_id,
		)
	);

	return array(
		'id'          => (int) $post->ID,
		'type'        => 'forum_topic',
		'title'       => $post->post_title,
		'content'     => $post->post_content,
		'raw'         => $post->post_content,
		'status'      => $post->post_status,
		'forum_id'    => $forum_id,
		'permalink'   => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $post->ID ) : get_permalink( $post->ID ),
		'updated_at'  => mysql_to_rfc3339( $post->post_modified_gmt ),
		'context'     => array(
			'blog_id'  => $blog_id,
			'forum_id' => $forum_id,
		),
		'permissions' => extrachill_community_build_editor_permissions( $post->ID, 'topic' ),
		'draft'       => $draft,
	);
}

/**
 * Load a reply for the editor.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_reply_for_editor( $input ) {
	if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$reply_id = isset( $input['reply_id'] ) ? (int) $input['reply_id'] : 0;
	if ( ! $reply_id ) {
		return new WP_Error( 'missing_reply_id', 'A reply_id is required.' );
	}

	$post = get_post( $reply_id );
	if ( ! $post || $post->post_type !== bbp_get_reply_post_type() ) {
		return new WP_Error( 'not_a_reply', 'Post ID is not a valid reply.' );
	}

	$topic_id = function_exists( 'bbp_get_reply_topic_id' )
		? (int) bbp_get_reply_topic_id( $reply_id )
		: (int) $post->post_parent;
	$forum_id = function_exists( 'bbp_get_reply_forum_id' )
		? (int) bbp_get_reply_forum_id( $reply_id )
		: 0;
	$reply_to = function_exists( 'bbp_get_reply_to' ) ? (int) bbp_get_reply_to( $reply_id ) : 0;
	$blog_id  = (int) get_current_blog_id();

	$draft = extrachill_community_get_draft_overlay(
		get_current_user_id(),
		'reply',
		array(
			'blog_id'  => $blog_id,
			'topic_id' => $topic_id,
			'reply_to' => $reply_to,
		)
	);

	return array(
		'id'          => (int) $post->ID,
		'type'        => 'forum_reply',
		'content'     => $post->post_content,
		'raw'         => $post->post_content,
		'status'      => $post->post_status,
		'topic_id'    => $topic_id,
		'forum_id'    => $forum_id,
		'reply_to'    => $reply_to,
		'permalink'   => function_exists( 'bbp_get_reply_url' ) ? bbp_get_reply_url( $post->ID ) : get_permalink( $post->ID ),
		'updated_at'  => mysql_to_rfc3339( $post->post_modified_gmt ),
		'context'     => array(
			'blog_id'  => $blog_id,
			'topic_id' => $topic_id,
			'forum_id' => $forum_id,
		),
		'permissions' => extrachill_community_build_editor_permissions( $post->ID, 'reply' ),
		'draft'       => $draft,
	);
}

/**
 * Update an existing topic.
 *
 * Uses wp_update_post() for content + title; fires bbp_edit_topic so community
 * cache-invalidation hooks trigger. Reuses extrachill_community_maybe_convert_markdown()
 * and wp_kses_post() from the create path so sanitization stays symmetrical.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_update_topic( $input ) {
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

	$raw_content = isset( $input['content'] ) ? (string) $input['content'] : '';
	$format      = isset( $input['format'] ) ? (string) $input['format'] : 'html';
	$content     = wp_kses_post( extrachill_community_maybe_convert_markdown( $raw_content, $format ) );

	if ( '' === $content ) {
		return new WP_Error( 'missing_content', 'Content is required.' );
	}

	$update = array(
		'ID'           => $topic_id,
		'post_content' => $content,
	);

	if ( isset( $input['title'] ) ) {
		$title = sanitize_text_field( (string) $input['title'] );
		if ( '' === $title ) {
			return new WP_Error( 'missing_title', 'Title cannot be empty.' );
		}
		$update['post_title'] = $title;
	}

	// Optional author override — permission_callback already enforced edit caps,
	// but edit_others_topics is required to actually swap the author.
	if ( isset( $input['user_id'] ) ) {
		$requested_user_id = (int) $input['user_id'];
		if ( $requested_user_id > 0 && $requested_user_id !== (int) $post->post_author ) {
			if ( ! current_user_can( 'edit_others_topics' ) ) {
				return new WP_Error( 'cannot_change_author', 'You cannot change the topic author.' );
			}
			$update['post_author'] = $requested_user_id;
		}
	}

	$forum_id = function_exists( 'bbp_get_topic_forum_id' )
		? (int) bbp_get_topic_forum_id( $topic_id )
		: (int) $post->post_parent;

	$result = wp_update_post( $update, true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Fire bbp_edit_topic so community cache invalidation, notifications, points
	// recalculation, etc. all trigger. Mirrors the create path's bbp_new_topic.
	$author_id      = isset( $update['post_author'] ) ? (int) $update['post_author'] : (int) $post->post_author;
	$anonymous_data = array();
	$is_edit        = true;
	do_action( 'bbp_edit_topic', $topic_id, $forum_id, $anonymous_data, $author_id, $is_edit );

	$fresh = get_post( $topic_id );

	return array(
		'id'         => (int) $topic_id,
		'status'     => $fresh ? $fresh->post_status : $post->post_status,
		'title'      => $fresh ? $fresh->post_title : $post->post_title,
		'content'    => $fresh ? $fresh->post_content : $content,
		'permalink'  => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $topic_id ) : get_permalink( $topic_id ),
		'updated_at' => $fresh ? mysql_to_rfc3339( $fresh->post_modified_gmt ) : mysql_to_rfc3339( gmdate( 'Y-m-d H:i:s' ) ),
	);
}

/**
 * Update an existing reply.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_update_reply( $input ) {
	if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return new WP_Error( 'bbpress_unavailable', 'bbPress is not active.' );
	}

	$reply_id = isset( $input['reply_id'] ) ? (int) $input['reply_id'] : 0;
	if ( ! $reply_id ) {
		return new WP_Error( 'missing_reply_id', 'A reply_id is required.' );
	}

	$post = get_post( $reply_id );
	if ( ! $post || $post->post_type !== bbp_get_reply_post_type() ) {
		return new WP_Error( 'not_a_reply', 'Post ID is not a valid reply.' );
	}

	$raw_content = isset( $input['content'] ) ? (string) $input['content'] : '';
	$format      = isset( $input['format'] ) ? (string) $input['format'] : 'html';
	$content     = wp_kses_post( extrachill_community_maybe_convert_markdown( $raw_content, $format ) );

	if ( '' === $content ) {
		return new WP_Error( 'missing_content', 'Content is required.' );
	}

	$update = array(
		'ID'           => $reply_id,
		'post_content' => $content,
	);

	if ( isset( $input['user_id'] ) ) {
		$requested_user_id = (int) $input['user_id'];
		if ( $requested_user_id > 0 && $requested_user_id !== (int) $post->post_author ) {
			if ( ! current_user_can( 'edit_others_replies' ) ) {
				return new WP_Error( 'cannot_change_author', 'You cannot change the reply author.' );
			}
			$update['post_author'] = $requested_user_id;
		}
	}

	$topic_id = function_exists( 'bbp_get_reply_topic_id' )
		? (int) bbp_get_reply_topic_id( $reply_id )
		: (int) $post->post_parent;
	$forum_id = function_exists( 'bbp_get_reply_forum_id' )
		? (int) bbp_get_reply_forum_id( $reply_id )
		: 0;

	$result = wp_update_post( $update, true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$author_id      = isset( $update['post_author'] ) ? (int) $update['post_author'] : (int) $post->post_author;
	$anonymous_data = array();
	$is_edit        = true;
	$reply_to       = function_exists( 'bbp_get_reply_to' ) ? (int) bbp_get_reply_to( $reply_id ) : 0;
	do_action( 'bbp_edit_reply', $reply_id, $topic_id, $forum_id, $anonymous_data, $author_id, $is_edit, $reply_to );

	$fresh = get_post( $reply_id );

	return array(
		'id'         => (int) $reply_id,
		'status'     => $fresh ? $fresh->post_status : $post->post_status,
		'content'    => $fresh ? $fresh->post_content : $content,
		'permalink'  => function_exists( 'bbp_get_reply_url' ) ? bbp_get_reply_url( $reply_id ) : get_permalink( $reply_id ),
		'updated_at' => $fresh ? mysql_to_rfc3339( $fresh->post_modified_gmt ) : mysql_to_rfc3339( gmdate( 'Y-m-d H:i:s' ) ),
	);
}

// ─── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Convert markdown content to Gutenberg block markup when format === 'markdown'.
 *
 * Relies on Block Format Bridge's public helper bfb_convert(), which is bundled
 * with Data Machine (network-activated). When BFB is unavailable, or when format
 * is anything other than 'markdown', the original content is returned untouched
 * so the caller's existing HTML path continues to work.
 *
 * On conversion failure (BFB returns an empty string), the original markdown is
 * returned so the write isn't blocked — wp_kses_post() downstream will still
 * sanitise it as raw HTML.
 *
 * @param string $content Raw content from the caller.
 * @param string $format  Either 'html' or 'markdown'.
 * @return string Possibly converted content, ready for wp_kses_post().
 */
function extrachill_community_maybe_convert_markdown( $content, $format ) {
	if ( 'markdown' !== $format ) {
		return $content;
	}

	if ( ! function_exists( 'bfb_convert' ) ) {
		error_log( '[Extrachill Community] Markdown format requested but bfb_convert() is unavailable — falling back to raw HTML handling.' );
		return $content;
	}

	$converted = bfb_convert( $content, 'markdown', 'blocks' );
	if ( '' === $converted ) {
		error_log( '[Extrachill Community] bfb_convert() returned an empty string for markdown input — falling back to raw content.' );
		return $content;
	}

	return $converted;
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
		'topic_id'    => (int) $post->ID,
		'title'       => $post->post_title,
		'forum_id'    => (int) $post->post_parent,
		'author_id'   => (int) $post->post_author,
		'author_name' => $author ? $author->display_name : '',
		'date'        => $post->post_date_gmt,
		'modified'    => $post->post_modified_gmt,
		'reply_count' => function_exists( 'bbp_get_topic_reply_count' ) ? (int) bbp_get_topic_reply_count( $post->ID ) : 0,
		'voice_count' => function_exists( 'bbp_get_topic_voice_count' ) ? (int) bbp_get_topic_voice_count( $post->ID ) : 0,
		'url'         => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $post->ID ) : get_permalink( $post->ID ),
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
