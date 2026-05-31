<?php
/**
 * Topic & Reply Write Abilities
 *
 * Execute callbacks for the write topic/reply abilities: create topic, create
 * reply, update topic, update reply. Wraps bbp_insert_topic / bbp_insert_reply /
 * wp_update_post and fires the bbp_new_* / bbp_edit_* actions so cache
 * invalidation, notifications, draft cleanup, and point recalculation trigger.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

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
	if ( ! $forum || bbp_get_forum_post_type() !== $forum->post_type ) {
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
	if ( ! $topic || bbp_get_topic_post_type() !== $topic->post_type ) {
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
	if ( ! $post || bbp_get_topic_post_type() !== $post->post_type ) {
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
	if ( ! $post || bbp_get_reply_post_type() !== $post->post_type ) {
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
