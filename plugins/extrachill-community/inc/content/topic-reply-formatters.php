<?php
/**
 * Topic & Reply Formatters
 *
 * Shared helpers for topic/reply abilities: markdown→blocks conversion and the
 * response-shaping formatters used by the read and editor abilities.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

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
		return $content;
	}

	$converted = bfb_convert( $content, 'markdown', 'blocks' );
	if ( '' === $converted ) {
		return $content;
	}

	return $converted;
}

/**
 * Format a topic post into a response array.
 *
 * @param WP_Post $post         Topic post object.
 * @param bool    $include_content Include full content (default false for list views).
 * @return array
 */
function extrachill_community_format_topic( $post, $include_content = false ) {
	$author = get_userdata( (int) $post->post_author );

	$topic = array(
		'topic_id'    => (int) $post->ID,
		'title'       => $post->post_title,
		'forum_id'    => (int) $post->post_parent,
		'author_id'   => (int) $post->post_author,
		'author_name' => $author ? $author->display_name : '',
		'status'      => (string) $post->post_status,
		'date'        => mysql2date( DATE_W3C, $post->post_date_gmt ? $post->post_date_gmt : $post->post_date, false ),
		'modified'    => mysql2date( DATE_W3C, $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified, false ),
		'reply_count' => function_exists( 'bbp_get_topic_reply_count' ) ? (int) bbp_get_topic_reply_count( $post->ID ) : 0,
		'voice_count' => function_exists( 'bbp_get_topic_voice_count' ) ? (int) bbp_get_topic_voice_count( $post->ID ) : 0,
		'url'         => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $post->ID ) : get_permalink( $post->ID ),
	);

	if ( $include_content ) {
		$topic['content'] = $post->post_content;
	}

	if ( function_exists( 'extrachill_community_format_post_public_voice' ) ) {
		$public_voice = extrachill_community_format_post_public_voice( $post->ID );
		if ( $public_voice ) {
			$topic['public_voice'] = $public_voice;
		}
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
	$author = get_userdata( (int) $post->post_author );

	$reply = array(
		'reply_id'    => (int) $post->ID,
		'topic_id'    => function_exists( 'bbp_get_reply_topic_id' ) ? (int) bbp_get_reply_topic_id( $post->ID ) : (int) $post->post_parent,
		'forum_id'    => function_exists( 'bbp_get_reply_forum_id' ) ? (int) bbp_get_reply_forum_id( $post->ID ) : 0,
		'author_id'   => (int) $post->post_author,
		'author_name' => $author ? $author->display_name : '',
		'content'     => $post->post_content,
		'status'      => (string) $post->post_status,
		'date'        => mysql2date( DATE_W3C, $post->post_date_gmt ? $post->post_date_gmt : $post->post_date, false ),
		'reply_to'    => function_exists( 'bbp_get_reply_to' ) ? (int) bbp_get_reply_to( $post->ID ) : 0,
		'url'         => function_exists( 'bbp_get_reply_url' ) ? bbp_get_reply_url( $post->ID ) : get_permalink( $post->ID ),
	);

	if ( function_exists( 'extrachill_community_format_post_public_voice' ) ) {
		$public_voice = extrachill_community_format_post_public_voice( $post->ID );
		if ( $public_voice ) {
			$reply['public_voice'] = $public_voice;
		}
	}

	$upvote_count = (int) get_post_meta( $post->ID, 'upvote_count', true );
	if ( $upvote_count > 0 ) {
		$reply['upvote_count'] = $upvote_count;
	}

	return $reply;
}
