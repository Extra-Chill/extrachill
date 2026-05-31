<?php
/**
 * Topic & Reply Editor Load Abilities
 *
 * Execute callbacks that load a topic or reply for the editor: returns serialized
 * post_content (block markup as-stored) and the permissions envelope. Edit
 * autosaves are owned by WP core /autosaves (see edit-autosave.php).
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

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
	if ( ! $post || bbp_get_topic_post_type() !== $post->post_type ) {
		return new WP_Error( 'not_a_topic', 'Post ID is not a valid topic.' );
	}

	$forum_id = function_exists( 'bbp_get_topic_forum_id' )
		? (int) bbp_get_topic_forum_id( $topic_id )
		: (int) $post->post_parent;

	$blog_id = (int) get_current_blog_id();

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
	if ( ! $post || bbp_get_reply_post_type() !== $post->post_type ) {
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
	);
}
