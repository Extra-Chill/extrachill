<?php
/**
 * bbPress Draft Abilities
 *
 * Abilities-first primitive for community draft storage and retrieval.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_draft_abilities' );

function extrachill_community_register_draft_abilities() {
	wp_register_ability(
		'extrachill/get-bbpress-draft',
		array(
			'label'               => __( 'Get bbPress Draft', 'extrachill-community' ),
			'description'         => __( 'Retrieve a stored bbPress topic or reply draft for the current user.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'           => array( 'type' => 'integer' ),
					'type'              => array( 'type' => 'string', 'enum' => array( 'topic', 'reply' ) ),
					'blog_id'           => array( 'type' => 'integer' ),
					'forum_id'          => array( 'type' => 'integer' ),
					'topic_id'          => array( 'type' => 'integer' ),
					'reply_to'          => array( 'type' => 'integer' ),
					'prefer_unassigned' => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'type' ),
			),
			'output_schema'       => array(
				'anyOf' => array(
					array( 'type' => 'object' ),
					array( 'type' => 'null' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_bbpress_draft',
			'permission_callback' => 'extrachill_community_ability_bbpress_draft_permission',
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

	wp_register_ability(
		'extrachill/save-bbpress-draft',
		array(
			'label'               => __( 'Save bbPress Draft', 'extrachill-community' ),
			'description'         => __( 'Create or update a stored bbPress topic or reply draft for the current user.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'   => array( 'type' => 'integer' ),
					'type'      => array( 'type' => 'string', 'enum' => array( 'topic', 'reply' ) ),
					'blog_id'   => array( 'type' => 'integer' ),
					'forum_id'  => array( 'type' => 'integer' ),
					'topic_id'  => array( 'type' => 'integer' ),
					'reply_to'  => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'content'   => array( 'type' => 'string' ),
				),
				'required'   => array( 'type' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_community_ability_save_bbpress_draft',
			'permission_callback' => 'extrachill_community_ability_bbpress_draft_permission',
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

	wp_register_ability(
		'extrachill/delete-bbpress-draft',
		array(
			'label'               => __( 'Delete bbPress Draft', 'extrachill-community' ),
			'description'         => __( 'Delete a stored bbPress topic or reply draft for the current user.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'  => array( 'type' => 'integer' ),
					'type'     => array( 'type' => 'string', 'enum' => array( 'topic', 'reply' ) ),
					'blog_id'  => array( 'type' => 'integer' ),
					'forum_id' => array( 'type' => 'integer' ),
					'topic_id' => array( 'type' => 'integer' ),
					'reply_to' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'type' ),
			),
			'output_schema'       => array( 'type' => 'boolean' ),
			'execute_callback'    => 'extrachill_community_ability_delete_bbpress_draft',
			'permission_callback' => 'extrachill_community_ability_bbpress_draft_permission',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

/**
 * Permission callback for the bbPress draft abilities.
 *
 * Enforces identity at the ability boundary so the ability is self-defending
 * regardless of which caller (REST, CLI, chat tool, MCP, another plugin)
 * invokes it. Rules:
 *
 *  - Caller must be logged in.
 *  - If $input['user_id'] is supplied and does not match the current user,
 *    the caller must hold `edit_others_posts` (the same capability bbPress
 *    uses to gate cross-author content edits).
 *  - Otherwise (no user_id supplied, or it matches the current user), pass.
 *
 * @param array $input Ability input payload.
 * @return bool True if the caller may execute the ability.
 */
function extrachill_community_ability_bbpress_draft_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( ! is_array( $input ) || ! isset( $input['user_id'] ) ) {
		return true;
	}

	$requested_user_id = (int) $input['user_id'];
	$current_user_id   = (int) get_current_user_id();

	if ( $requested_user_id <= 0 || $requested_user_id === $current_user_id ) {
		return true;
	}

	return current_user_can( 'edit_others_posts' );
}

function extrachill_community_build_draft_context_from_input( $input ) {
	return array(
		'type'     => isset( $input['type'] ) ? (string) $input['type'] : '',
		'blog_id'  => isset( $input['blog_id'] ) ? (int) $input['blog_id'] : (int) get_current_blog_id(),
		'forum_id' => isset( $input['forum_id'] ) ? (int) $input['forum_id'] : 0,
		'topic_id' => isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0,
		'reply_to' => isset( $input['reply_to'] ) ? (int) $input['reply_to'] : 0,
	);
}

function extrachill_community_resolve_draft_user_id( $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : get_current_user_id();
	return $user_id > 0 ? $user_id : 0;
}

/**
 * Get-ability execute callback.
 *
 * Reads a single draft row from the storage repository. Falls back to the
 * unassigned-forum draft (forum_id=0) when `prefer_unassigned` is set and the
 * specific-forum lookup returned no row — preserves the "I started typing
 * before picking a forum" UX.
 *
 * @param array $input Ability input.
 * @return array|null
 */
function extrachill_community_ability_get_bbpress_draft( $input ) {
	$user_id = extrachill_community_resolve_draft_user_id( $input );
	if ( $user_id <= 0 ) {
		return null;
	}

	$context = extrachill_community_build_draft_context_from_input( $input );
	$draft   = extrachill_community_bbpress_drafts_fetch( $user_id, $context );

	if ( null === $draft && 'topic' === $context['type'] && ! empty( $input['prefer_unassigned'] ) && $context['forum_id'] > 0 ) {
		$context['forum_id'] = 0;
		$draft               = extrachill_community_bbpress_drafts_fetch( $user_id, $context );
	}

	return $draft;
}

/**
 * Save-ability execute callback.
 *
 * Upserts a single draft row. The storage layer's UNIQUE KEY guarantees
 * atomic, race-free updates even when two browser tabs save the same draft
 * context simultaneously.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Stored draft row, or WP_Error on invalid input.
 */
function extrachill_community_ability_save_bbpress_draft( $input ) {
	$user_id = extrachill_community_resolve_draft_user_id( $input );
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', 'A valid user is required.' );
	}

	$context = extrachill_community_build_draft_context_from_input( $input );
	$draft   = array_merge(
		$context,
		array(
			'title'      => isset( $input['title'] ) ? (string) $input['title'] : '',
			'content'    => isset( $input['content'] ) ? (string) $input['content'] : '',
			'updated_at' => time(),
		)
	);

	$stored = extrachill_community_bbpress_drafts_upsert( $user_id, $draft );

	if ( false === $stored ) {
		return new WP_Error( 'draft_save_failed', 'Failed to save draft.' );
	}

	return $stored;
}

/**
 * Delete-ability execute callback.
 *
 * Deletes a single draft row. Returns true on no-op (row didn't exist).
 *
 * @param array $input Ability input.
 * @return bool
 */
function extrachill_community_ability_delete_bbpress_draft( $input ) {
	$user_id = extrachill_community_resolve_draft_user_id( $input );
	if ( $user_id <= 0 ) {
		return false;
	}

	$context = extrachill_community_build_draft_context_from_input( $input );

	return extrachill_community_bbpress_drafts_delete( $user_id, $context );
}
