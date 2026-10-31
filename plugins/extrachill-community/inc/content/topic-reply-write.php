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
 * Whether this request is running in WordPress's trusted CLI bootstrap.
 *
 * @return bool
 */
function extrachill_community_is_wp_cli_request() {
	// The standalone regression defines WP_CLI midway through one process to test both contexts.
	// @phpstan-ignore-next-line booleanAnd.rightAlwaysTrue
	return defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' );
}

/**
 * Resolve and authorize the trusted actor for a forum write.
 *
 * The ambient Agents API principal is host-resolved and may carry a capability
 * ceiling. Ability input is never an authority for authorship; a legacy
 * self-matching user_id remains accepted for existing internal callers. WP-CLI
 * may explicitly select an author only when no authenticated principal exists.
 *
 * @param array  $input            Ability input.
 * @param string $capability       Required bbPress capability.
 * @param int    $object_id        Optional object ID for meta capabilities.
 * @param bool   $allow_cli_author Whether trusted WP-CLI may select the actor.
 * @param string $error_code       Authorization failure code.
 * @return int|WP_Error Trusted actor ID on success.
 */
function extrachill_community_authorize_forum_action( $input, $capability, $object_id = 0, $allow_cli_author = false, $error_code = 'cannot_publish' ) {
	$current_user_id = get_current_user_id();
	$principal       = null;

	if ( class_exists( 'WP_Agent_Access' ) && class_exists( 'AgentsAPI\\AI\\WP_Agent_Execution_Principal' ) ) {
		try {
			$principal = WP_Agent_Access::get_current_principal(
				array(
					'allow_anonymous_audience' => false,
				)
			);
		} catch ( Throwable $exception ) {
			return new WP_Error( 'invalid_execution_principal', 'The execution principal could not be authenticated.' );
		}
	}

	if ( $principal instanceof AgentsAPI\AI\WP_Agent_Execution_Principal ) {
		$user_id = (int) $principal->acting_user_id;

		if ( $current_user_id > 0 && $current_user_id !== $user_id ) {
			return new WP_Error( 'execution_principal_mismatch', 'The execution principal does not match the authenticated user.' );
		}

		if ( $principal->capability_ceiling instanceof WP_Agent_Capability_Ceiling && (int) $principal->capability_ceiling->user_id !== $user_id ) {
			return new WP_Error( 'execution_principal_mismatch', 'The execution principal capability boundary does not match its acting user.' );
		}
	} else {
		$user_id = $current_user_id;
	}

	if ( $user_id <= 0 && null === $principal && $allow_cli_author && extrachill_community_is_wp_cli_request() && isset( $input['user_id'] ) ) {
		$cli_user_id = (int) $input['user_id'];
		if ( $cli_user_id > 0 && get_userdata( $cli_user_id ) ) {
			$user_id = $cli_user_id;
		}
	}

	if ( $user_id <= 0 ) {
		return new WP_Error( 'missing_user', 'An authenticated user is required.' );
	}

	if ( $principal instanceof AgentsAPI\AI\WP_Agent_Execution_Principal
		&& $principal->capability_ceiling instanceof WP_Agent_Capability_Ceiling
		&& ! $principal->capability_ceiling->allows_capability( $capability )
	) {
		return new WP_Error( $error_code, 'The execution principal does not allow this action.' );
	}

	$site = extrachill_community_switch_to_community_blog();
	try {
		$allowed = $object_id > 0
			? user_can( $user_id, $capability, $object_id )
			: user_can( $user_id, $capability );
	} finally {
		if ( $site['switched'] ) {
			restore_current_blog();
		}
	}

	if ( ! $allowed ) {
		return new WP_Error( $error_code, 'The trusted actor cannot perform this action.' );
	}

	return $user_id;
}

/**
 * Resolve and authorize the author for topic/reply creation.
 *
 * @param array  $input      Ability input.
 * @param string $capability Required bbPress publish capability.
 * @return int|WP_Error Authenticated author ID on success.
 */
function extrachill_community_authorize_post_creation( $input, $capability ) {
	$user_id = extrachill_community_authorize_forum_action( $input, $capability, 0, true );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( isset( $input['user_id'] ) && (int) $input['user_id'] !== $user_id ) {
		return new WP_Error( 'author_mismatch', 'The requested author does not match the authenticated user.' );
	}

	return $user_id;
}

/**
 * Authorize a topic/reply update and any requested author reassignment.
 *
 * @param array   $input Ability input.
 * @param WP_Post $post  Topic or reply post.
 * @param string  $type  Either topic or reply.
 * @return int|WP_Error Trusted actor ID on success.
 */
function extrachill_community_authorize_post_update( $input, $post, $type ) {
	$edit_cap = 'topic' === $type ? 'edit_topic' : 'edit_reply';
	$user_id  = extrachill_community_authorize_forum_action( $input, $edit_cap, (int) $post->ID, false, 'cannot_edit' );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( ! isset( $input['user_id'] ) || (int) $input['user_id'] === (int) $post->post_author ) {
		return $user_id;
	}

	$requested_user_id = (int) $input['user_id'];
	if ( $requested_user_id <= 0 || ! get_userdata( $requested_user_id ) ) {
		return new WP_Error( 'invalid_author', 'The requested author is not a valid user.' );
	}

	$edit_others_cap = 'topic' === $type ? 'edit_others_topics' : 'edit_others_replies';
	return extrachill_community_authorize_forum_action( $input, $edit_others_cap, 0, false, 'cannot_change_author' );
}

/**
 * Permission callback for topic creation.
 *
 * @param array $input Ability input.
 * @return bool
 */
function extrachill_community_ability_create_topic_permission( $input = array() ) {
	return ! is_wp_error( extrachill_community_authorize_post_creation( $input, 'publish_topics' ) );
}

/**
 * Permission callback for reply creation.
 *
 * @param array $input Ability input.
 * @return bool
 */
function extrachill_community_ability_create_reply_permission( $input = array() ) {
	return ! is_wp_error( extrachill_community_authorize_post_creation( $input, 'publish_replies' ) );
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
	$user_id     = extrachill_community_authorize_post_creation( $input, 'publish_topics' );

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$voice_change = function_exists( 'extrachill_community_prepare_public_voice_change' )
		? extrachill_community_prepare_public_voice_change( $input, $user_id, $user_id )
		: null;
	if ( is_wp_error( $voice_change ) ) {
		return $voice_change;
	}

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
	if ( function_exists( 'extrachill_community_persist_public_voice' ) ) {
		extrachill_community_persist_public_voice( $topic_id, $voice_change );
	}

	// Fire bbp_new_topic so community hooks (cache, notifications, points, drafts) trigger.
	do_action( 'bbp_new_topic', $topic_id, $forum_id, array(), $user_id );

	$result = array(
		'topic_id'  => (int) $topic_id,
		'title'     => $title,
		'url'       => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $topic_id ) : get_permalink( $topic_id ),
		'forum_id'  => $forum_id,
		'author_id' => $user_id,
	);
	if ( function_exists( 'extrachill_community_format_post_public_voice' ) ) {
		$result['public_voice'] = extrachill_community_format_post_public_voice( $topic_id );
	}
	return $result;
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
	$user_id     = extrachill_community_authorize_post_creation( $input, 'publish_replies' );

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$voice_change = function_exists( 'extrachill_community_prepare_public_voice_change' )
		? extrachill_community_prepare_public_voice_change( $input, $user_id, $user_id )
		: null;
	if ( is_wp_error( $voice_change ) ) {
		return $voice_change;
	}

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
	if ( function_exists( 'extrachill_community_persist_public_voice' ) ) {
		extrachill_community_persist_public_voice( $reply_id, $voice_change );
	}

	// Fire bbp_new_reply so community hooks (cache, notifications, points, drafts) trigger.
	do_action( 'bbp_new_reply', $reply_id, $topic_id, $forum_id, array(), $user_id, false, $reply_to );

	$result = array(
		'reply_id'  => (int) $reply_id,
		'topic_id'  => $topic_id,
		'forum_id'  => $forum_id,
		'url'       => function_exists( 'bbp_get_reply_url' ) ? bbp_get_reply_url( $reply_id ) : get_permalink( $reply_id ),
		'author_id' => $user_id,
	);
	if ( function_exists( 'extrachill_community_format_post_public_voice' ) ) {
		$result['public_voice'] = extrachill_community_format_post_public_voice( $reply_id );
	}
	return $result;
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

	$actor_id = extrachill_community_authorize_post_update( $input, $post, 'topic' );
	if ( is_wp_error( $actor_id ) ) {
		return $actor_id;
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

	if ( isset( $input['user_id'] ) ) {
		$requested_user_id = (int) $input['user_id'];
		if ( $requested_user_id > 0 && $requested_user_id !== (int) $post->post_author ) {
			$update['post_author'] = $requested_user_id;
		}
	}

	$new_author_id = isset( $update['post_author'] ) ? (int) $update['post_author'] : (int) $post->post_author;
	$voice_change  = function_exists( 'extrachill_community_prepare_public_voice_change' )
		? extrachill_community_prepare_public_voice_change( $input, (int) $post->post_author, $actor_id, $topic_id, $new_author_id )
		: null;
	if ( is_wp_error( $voice_change ) ) {
		return $voice_change;
	}

	$forum_id = function_exists( 'bbp_get_topic_forum_id' )
		? (int) bbp_get_topic_forum_id( $topic_id )
		: (int) $post->post_parent;

	$result = wp_update_post( $update, true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( function_exists( 'extrachill_community_persist_public_voice' ) ) {
		extrachill_community_persist_public_voice( $topic_id, $voice_change );
	}

	// Fire bbp_edit_topic so community cache invalidation, notifications, points
	// recalculation, etc. all trigger. Mirrors the create path's bbp_new_topic.
	$author_id      = isset( $update['post_author'] ) ? (int) $update['post_author'] : (int) $post->post_author;
	$anonymous_data = array();
	$is_edit        = true;
	do_action( 'bbp_edit_topic', $topic_id, $forum_id, $anonymous_data, $author_id, $is_edit );

	$fresh = get_post( $topic_id );

	$result = array(
		'id'         => (int) $topic_id,
		'status'     => $fresh->post_status,
		'title'      => $fresh->post_title,
		'content'    => $fresh->post_content,
		'permalink'  => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $topic_id ) : get_permalink( $topic_id ),
		'updated_at' => mysql_to_rfc3339( $fresh->post_modified_gmt ),
	);
	if ( function_exists( 'extrachill_community_format_post_public_voice' ) ) {
		$result['public_voice'] = extrachill_community_format_post_public_voice( $topic_id );
	}
	return $result;
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

	$actor_id = extrachill_community_authorize_post_update( $input, $post, 'reply' );
	if ( is_wp_error( $actor_id ) ) {
		return $actor_id;
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
			$update['post_author'] = $requested_user_id;
		}
	}

	$new_author_id = isset( $update['post_author'] ) ? (int) $update['post_author'] : (int) $post->post_author;
	$voice_change  = function_exists( 'extrachill_community_prepare_public_voice_change' )
		? extrachill_community_prepare_public_voice_change( $input, (int) $post->post_author, $actor_id, $reply_id, $new_author_id )
		: null;
	if ( is_wp_error( $voice_change ) ) {
		return $voice_change;
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
	if ( function_exists( 'extrachill_community_persist_public_voice' ) ) {
		extrachill_community_persist_public_voice( $reply_id, $voice_change );
	}

	$author_id      = isset( $update['post_author'] ) ? (int) $update['post_author'] : (int) $post->post_author;
	$anonymous_data = array();
	$is_edit        = true;
	$reply_to       = function_exists( 'bbp_get_reply_to' ) ? (int) bbp_get_reply_to( $reply_id ) : 0;
	do_action( 'bbp_edit_reply', $reply_id, $topic_id, $forum_id, $anonymous_data, $author_id, $is_edit, $reply_to );

	$fresh = get_post( $reply_id );

	$result = array(
		'id'         => (int) $reply_id,
		'status'     => $fresh->post_status,
		'content'    => $fresh->post_content,
		'permalink'  => function_exists( 'bbp_get_reply_url' ) ? bbp_get_reply_url( $reply_id ) : get_permalink( $reply_id ),
		'updated_at' => mysql_to_rfc3339( $fresh->post_modified_gmt ),
	);
	if ( function_exists( 'extrachill_community_format_post_public_voice' ) ) {
		$result['public_voice'] = extrachill_community_format_post_public_voice( $reply_id );
	}
	return $result;
}
