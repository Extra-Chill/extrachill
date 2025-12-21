<?php
/**
 * bbPress Draft Restore + Cleanup
 *
 * Restores server-backed drafts into bbPress forms when empty and clears drafts
 * after successful topic/reply creation.
 *
 * @package ExtraChillCommunity
 * @subpackage ForumFeatures\Content\Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function extrachill_community_bbpress_drafts_enabled() {
    return is_user_logged_in() && function_exists( 'extrachill_api_bbpress_draft_get' );
}

function extrachill_community_bbpress_get_current_forum_id_for_topic_draft() {
    $forum_id = 0;

    if ( function_exists( 'bbp_is_single_forum' ) && bbp_is_single_forum() && function_exists( 'bbp_get_forum_id' ) ) {
        $forum_id = (int) bbp_get_forum_id();
    } elseif ( function_exists( 'bbp_get_form_topic_forum' ) ) {
        $forum_id = (int) bbp_get_form_topic_forum();
    }

    return $forum_id >= 0 ? $forum_id : 0;
}

function extrachill_community_bbpress_restore_topic_draft_content( $content ) {
    if ( ! extrachill_community_bbpress_drafts_enabled() ) {
        return $content;
    }

    if ( ! function_exists( 'bbp_is_topic_edit' ) || bbp_is_topic_edit() ) {
        return $content;
    }

    if ( trim( (string) $content ) !== '' ) {
        return $content;
    }

    $forum_id = extrachill_community_bbpress_get_current_forum_id_for_topic_draft();

    $draft = extrachill_api_bbpress_draft_get(
        get_current_user_id(),
        [
            'type'    => 'topic',
            'blog_id' => (int) get_current_blog_id(),
            'forum_id' => $forum_id,
        ]
    );

    if ( ! $draft && $forum_id !== 0 ) {
        $draft = extrachill_api_bbpress_draft_get(
            get_current_user_id(),
            [
                'type'    => 'topic',
                'blog_id' => (int) get_current_blog_id(),
                'forum_id' => 0,
            ]
        );
    }

    if ( ! $draft || empty( $draft['content'] ) ) {
        return $content;
    }

    return (string) $draft['content'];
}
add_filter( 'bbp_get_form_topic_content', 'extrachill_community_bbpress_restore_topic_draft_content', 50 );

function extrachill_community_bbpress_restore_reply_draft_content( $content ) {
    if ( ! extrachill_community_bbpress_drafts_enabled() ) {
        return $content;
    }

    if ( ! function_exists( 'bbp_is_reply_edit' ) || bbp_is_reply_edit() ) {
        return $content;
    }

    if ( trim( (string) $content ) !== '' ) {
        return $content;
    }

    if ( ! function_exists( 'bbp_get_topic_id' ) ) {
        return $content;
    }

    $topic_id = (int) bbp_get_topic_id();
    if ( $topic_id <= 0 ) {
        return $content;
    }

    $reply_to = 0;
    if ( function_exists( 'bbp_get_form_reply_to' ) ) {
        $reply_to = (int) bbp_get_form_reply_to();
    }

    $draft = extrachill_api_bbpress_draft_get(
        get_current_user_id(),
        [
            'type'     => 'reply',
            'blog_id'  => (int) get_current_blog_id(),
            'topic_id' => $topic_id,
            'reply_to' => $reply_to,
        ]
    );

    if ( ! $draft || empty( $draft['content'] ) ) {
        return $content;
    }

    return (string) $draft['content'];
}
add_filter( 'bbp_get_form_reply_content', 'extrachill_community_bbpress_restore_reply_draft_content', 50 );

function extrachill_community_bbpress_restore_topic_draft_title( $title ) {
    if ( ! extrachill_community_bbpress_drafts_enabled() ) {
        return $title;
    }

    if ( ! function_exists( 'bbp_is_topic_edit' ) || bbp_is_topic_edit() ) {
        return $title;
    }

    if ( trim( (string) $title ) !== '' ) {
        return $title;
    }

    $forum_id = extrachill_community_bbpress_get_current_forum_id_for_topic_draft();

    $draft = extrachill_api_bbpress_draft_get(
        get_current_user_id(),
        [
            'type'    => 'topic',
            'blog_id' => (int) get_current_blog_id(),
            'forum_id' => $forum_id,
        ]
    );

    if ( ! $draft && $forum_id !== 0 ) {
        $draft = extrachill_api_bbpress_draft_get(
            get_current_user_id(),
            [
                'type'    => 'topic',
                'blog_id' => (int) get_current_blog_id(),
                'forum_id' => 0,
            ]
        );
    }

    if ( ! $draft || empty( $draft['title'] ) ) {
        return $title;
    }

    return (string) $draft['title'];
}
add_filter( 'bbp_get_form_topic_title', 'extrachill_community_bbpress_restore_topic_draft_title', 50 );

function extrachill_community_bbpress_restore_topic_draft_forum_id( $forum_id ) {
    if ( ! extrachill_community_bbpress_drafts_enabled() ) {
        return $forum_id;
    }

    if ( ! function_exists( 'bbp_is_topic_edit' ) || bbp_is_topic_edit() ) {
        return $forum_id;
    }

    $forum_id = (int) $forum_id;
    if ( $forum_id > 0 ) {
        return $forum_id;
    }

    $draft = extrachill_api_bbpress_draft_get(
        get_current_user_id(),
        [
            'type'    => 'topic',
            'blog_id' => (int) get_current_blog_id(),
            'forum_id' => 0,
        ]
    );

    if ( ! $draft ) {
        return $forum_id;
    }

    if ( empty( $draft['forum_id'] ) ) {
        return $forum_id;
    }

    return (int) $draft['forum_id'];
}
add_filter( 'bbp_get_form_topic_forum', 'extrachill_community_bbpress_restore_topic_draft_forum_id', 50 );

function extrachill_community_bbpress_clear_topic_drafts_on_new_topic( $topic_id, $forum_id ) {
    if ( ! function_exists( 'extrachill_api_bbpress_draft_delete' ) ) {
        return;
    }

    if ( ! function_exists( 'bbp_get_topic_author_id' ) ) {
        return;
    }

    $user_id = (int) bbp_get_topic_author_id( $topic_id );
    if ( $user_id <= 0 ) {
        return;
    }

    $blog_id  = (int) get_current_blog_id();
    $forum_id = (int) $forum_id;

    extrachill_api_bbpress_draft_delete(
        $user_id,
        [
            'type'    => 'topic',
            'blog_id' => $blog_id,
            'forum_id' => $forum_id,
        ]
    );

    extrachill_api_bbpress_draft_delete(
        $user_id,
        [
            'type'    => 'topic',
            'blog_id' => $blog_id,
            'forum_id' => 0,
        ]
    );
}
add_action( 'bbp_new_topic', 'extrachill_community_bbpress_clear_topic_drafts_on_new_topic', 20, 2 );

function extrachill_community_bbpress_clear_reply_drafts_on_new_reply( $reply_id ) {
    if ( ! function_exists( 'extrachill_api_bbpress_draft_delete' ) ) {
        return;
    }

    if ( ! function_exists( 'bbp_get_reply_topic_id' ) || ! function_exists( 'bbp_get_reply_author_id' ) ) {
        return;
    }

    $user_id = (int) bbp_get_reply_author_id( $reply_id );
    if ( $user_id <= 0 ) {
        return;
    }

    $topic_id = (int) bbp_get_reply_topic_id( $reply_id );
    if ( $topic_id <= 0 ) {
        return;
    }

    $reply_to = 0;
    if ( function_exists( 'bbp_get_reply_to' ) ) {
        $reply_to = (int) bbp_get_reply_to( $reply_id );
    }

    extrachill_api_bbpress_draft_delete(
        $user_id,
        [
            'type'     => 'reply',
            'blog_id'  => (int) get_current_blog_id(),
            'topic_id' => $topic_id,
            'reply_to' => $reply_to,
        ]
    );
}
add_action( 'bbp_new_reply', 'extrachill_community_bbpress_clear_reply_drafts_on_new_reply', 20, 1 );
