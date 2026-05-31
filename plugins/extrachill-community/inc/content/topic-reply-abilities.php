<?php
/**
 * Topic & Reply Abilities — Registration
 *
 * Thin entry point that registers the abilities-first primitives for listing,
 * reading, creating, and editing bbPress topics and replies. The execute and
 * permission callbacks referenced here live in cohesive sibling files:
 *
 * - topic-reply-read.php                — list_topics, get_topic, list_replies
 * - topic-reply-write.php               — create_topic, create_reply, update_topic, update_reply
 * - topic-reply-editor.php              — get_topic_for_editor, get_reply_for_editor
 * - topic-reply-editor-permissions.php  — *_permission callbacks + build_editor_permissions
 * - topic-reply-formatters.php          — format_topic, format_reply, maybe_convert_markdown
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
			'description'         => __( 'Load a topic for editing: returns serialized post_content (block markup as-stored) and the permissions envelope. Edit autosaves are owned by WP core /autosaves (see edit-autosave.php).', 'extra-chill-community' ),
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
