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
			'permission_callback' => '__return_true',
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
			'permission_callback' => '__return_true',
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

function extrachill_community_bbpress_drafts_meta_key() {
	return 'ec_bbpress_drafts';
}

function extrachill_community_bbpress_draft_key( array $context ) {
	$type    = isset( $context['type'] ) ? (string) $context['type'] : '';
	$blog_id = isset( $context['blog_id'] ) ? (int) $context['blog_id'] : (int) get_current_blog_id();

	if ( 'topic' === $type ) {
		$forum_id = isset( $context['forum_id'] ) ? (int) $context['forum_id'] : 0;
		return sprintf( 'topic:%d:%d', $blog_id, $forum_id );
	}

	if ( 'reply' === $type ) {
		$topic_id = isset( $context['topic_id'] ) ? (int) $context['topic_id'] : 0;
		$reply_to = isset( $context['reply_to'] ) ? (int) $context['reply_to'] : 0;
		return sprintf( 'reply:%d:%d:%d', $blog_id, $topic_id, $reply_to );
	}

	return sprintf( 'unknown:%d', $blog_id );
}

function extrachill_community_bbpress_drafts_get_all( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array();
	}

	$drafts = get_user_meta( $user_id, extrachill_community_bbpress_drafts_meta_key(), true );

	return is_array( $drafts ) ? $drafts : array();
}

function extrachill_community_bbpress_drafts_set_all( $user_id, array $drafts ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	return (bool) update_user_meta( $user_id, extrachill_community_bbpress_drafts_meta_key(), $drafts );
}

function extrachill_community_ability_get_bbpress_draft( $input ) {
	$user_id = extrachill_community_resolve_draft_user_id( $input );
	if ( $user_id <= 0 ) {
		return null;
	}

	$context = extrachill_community_build_draft_context_from_input( $input );
	$drafts  = extrachill_community_bbpress_drafts_get_all( $user_id );
	$key     = extrachill_community_bbpress_draft_key( $context );
	$draft   = isset( $drafts[ $key ] ) && is_array( $drafts[ $key ] ) ? $drafts[ $key ] : null;

	if ( null === $draft && 'topic' === $context['type'] && ! empty( $input['prefer_unassigned'] ) && $context['forum_id'] > 0 ) {
		$context['forum_id'] = 0;
		$key   = extrachill_community_bbpress_draft_key( $context );
		$draft = isset( $drafts[ $key ] ) && is_array( $drafts[ $key ] ) ? $drafts[ $key ] : null;
	}

	return $draft;
}

function extrachill_community_ability_save_bbpress_draft( $input ) {
	$user_id = extrachill_community_resolve_draft_user_id( $input );
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', 'A valid user is required.' );
	}

	$context = extrachill_community_build_draft_context_from_input( $input );
	$draft   = array_merge(
		$context,
		array(
			'title'   => isset( $input['title'] ) ? (string) $input['title'] : '',
			'content' => isset( $input['content'] ) ? (string) $input['content'] : '',
		)
	);

	$drafts = extrachill_community_bbpress_drafts_get_all( $user_id );
	$key    = extrachill_community_bbpress_draft_key( $draft );

	$drafts[ $key ] = array(
		'type'       => $draft['type'],
		'blog_id'    => $draft['blog_id'],
		'forum_id'   => isset( $draft['forum_id'] ) ? (int) $draft['forum_id'] : 0,
		'topic_id'   => isset( $draft['topic_id'] ) ? (int) $draft['topic_id'] : 0,
		'reply_to'   => isset( $draft['reply_to'] ) ? (int) $draft['reply_to'] : 0,
		'title'      => isset( $draft['title'] ) ? (string) $draft['title'] : '',
		'content'    => isset( $draft['content'] ) ? (string) $draft['content'] : '',
		'updated_at' => time(),
	);

	extrachill_community_bbpress_drafts_set_all( $user_id, $drafts );

	return $drafts[ $key ];
}

function extrachill_community_ability_delete_bbpress_draft( $input ) {
	$user_id = extrachill_community_resolve_draft_user_id( $input );
	if ( $user_id <= 0 ) {
		return false;
	}

	$context = extrachill_community_build_draft_context_from_input( $input );
	$drafts  = extrachill_community_bbpress_drafts_get_all( $user_id );
	$key     = extrachill_community_bbpress_draft_key( $context );

	if ( ! isset( $drafts[ $key ] ) ) {
		return true;
	}

	unset( $drafts[ $key ] );

	return extrachill_community_bbpress_drafts_set_all( $user_id, $drafts );
}
