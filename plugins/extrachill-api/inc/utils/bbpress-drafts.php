<?php
/**
 * bbPress Draft Utilities
 *
 * Stores bbPress topic/reply drafts in user meta for cross-device persistence.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returns the canonical user meta key for bbPress drafts.
 *
 * @return string
 */
function extrachill_api_bbpress_drafts_meta_key() {
    return 'ec_bbpress_drafts';
}

/**
 * Build a deterministic draft key.
 *
 * Context shape:
 * - type: topic|reply
 * - blog_id: int (optional; defaults to current)
 * - forum_id: int (topic drafts; required, may be 0)
 * - topic_id: int (reply drafts; required)
 * - reply_to: int (reply drafts; optional, defaults to 0)
 *
 * @param array $context Draft context.
 * @return string
 */
function extrachill_api_bbpress_draft_key( array $context ) {
    $type = isset( $context['type'] ) ? (string) $context['type'] : '';
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

/**
 * Fetch the full drafts map for a user.
 *
 * @param int $user_id User ID.
 * @return array
 */
function extrachill_api_bbpress_drafts_get_all( $user_id ) {
    $user_id = (int) $user_id;
    if ( $user_id <= 0 ) {
        return [];
    }

    $drafts = get_user_meta( $user_id, extrachill_api_bbpress_drafts_meta_key(), true );

    if ( ! is_array( $drafts ) ) {
        return [];
    }

    return $drafts;
}

/**
 * Persist the full drafts map for a user.
 *
 * @param int   $user_id User ID.
 * @param array $drafts Draft map.
 * @return bool
 */
function extrachill_api_bbpress_drafts_set_all( $user_id, array $drafts ) {
    $user_id = (int) $user_id;
    if ( $user_id <= 0 ) {
        return false;
    }

    return (bool) update_user_meta( $user_id, extrachill_api_bbpress_drafts_meta_key(), $drafts );
}

/**
 * Get a single draft by context.
 *
 * @param int   $user_id User ID.
 * @param array $context Draft context.
 * @return array|null
 */
function extrachill_api_bbpress_draft_get( $user_id, array $context ) {
    $drafts = extrachill_api_bbpress_drafts_get_all( $user_id );
    $key = extrachill_api_bbpress_draft_key( $context );

    if ( isset( $drafts[ $key ] ) && is_array( $drafts[ $key ] ) ) {
        return $drafts[ $key ];
    }

    return null;
}

/**
 * Upsert a draft for a user.
 *
 * Expected $draft keys:
 * - type (topic|reply)
 * - forum_id/topic_id
 * - title (topic only)
 * - content
 * - reply_to (optional)
 *
 * @param int   $user_id User ID.
 * @param array $draft Draft payload.
 * @return array
 */
function extrachill_api_bbpress_draft_upsert( $user_id, array $draft ) {
    $user_id = (int) $user_id;
    $drafts = extrachill_api_bbpress_drafts_get_all( $user_id );

    $type = isset( $draft['type'] ) ? (string) $draft['type'] : '';
    $blog_id = isset( $draft['blog_id'] ) ? (int) $draft['blog_id'] : (int) get_current_blog_id();

    $normalized = [
        'type'       => $type,
        'blog_id'    => $blog_id,
        'forum_id'   => isset( $draft['forum_id'] ) ? (int) $draft['forum_id'] : 0,
        'topic_id'   => isset( $draft['topic_id'] ) ? (int) $draft['topic_id'] : 0,
        'reply_to'   => isset( $draft['reply_to'] ) ? (int) $draft['reply_to'] : 0,
        'title'      => isset( $draft['title'] ) ? (string) $draft['title'] : '',
        'content'    => isset( $draft['content'] ) ? (string) $draft['content'] : '',
        'updated_at' => time(),
    ];

    $key = extrachill_api_bbpress_draft_key( $normalized );
    $drafts[ $key ] = $normalized;

    extrachill_api_bbpress_drafts_set_all( $user_id, $drafts );

    return $normalized;
}

/**
 * Delete a single draft by context.
 *
 * @param int   $user_id User ID.
 * @param array $context Draft context.
 * @return bool
 */
function extrachill_api_bbpress_draft_delete( $user_id, array $context ) {
    $user_id = (int) $user_id;
    $drafts = extrachill_api_bbpress_drafts_get_all( $user_id );
    $key = extrachill_api_bbpress_draft_key( $context );

    if ( ! isset( $drafts[ $key ] ) ) {
        return true;
    }

    unset( $drafts[ $key ] );

    return extrachill_api_bbpress_drafts_set_all( $user_id, $drafts );
}
