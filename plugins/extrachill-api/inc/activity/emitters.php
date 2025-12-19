<?php
/**
 * Core activity emitters for WordPress events.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'transition_post_status', 'extrachill_api_activity_emit_post_events', 10, 3 );
add_action( 'comment_post', 'extrachill_api_activity_emit_comment_event', 10, 3 );

function extrachill_api_activity_emit_post_events( $new_status, $old_status, $post ) {
    if ( ! function_exists( 'extrachill_api_activity_insert' ) ) {
        return;
    }

    if ( ! ( $post instanceof WP_Post ) ) {
        return;
    }

    if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
        return;
    }

    if ( $post->post_type === 'attachment' ) {
        return;
    }

    if ( 'publish' !== $new_status ) {
        return;
    }

    $blog_id  = get_current_blog_id();
    $actor_id = get_current_user_id();
    if ( ! $actor_id ) {
        $actor_id = (int) $post->post_author;
    }

    $title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    if ( 'publish' === $old_status ) {
        $type    = 'post_updated';
        $summary = 'Updated: ' . $title;
    } else {
        $type    = 'post_published';
        $summary = 'Published: ' . $title;
    }

    $card = array(
        'title'     => $title,
        'excerpt'   => html_entity_decode( get_the_excerpt( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
        'permalink' => get_permalink( $post ),
    );

		do_action( 'extrachill_activity_emit', array(
			'type'           => $type,
			'blog_id'        => $blog_id,
			'actor_id'       => $actor_id ? (int) $actor_id : null,
			'summary'        => $summary,
			'visibility'     => 'public',
        'primary_object' => array(
            'object_type' => 'post',
            'blog_id'     => $blog_id,
            'id'          => (string) $post->ID,
        ),
        'data' => array(
            'post_type'  => $post->post_type,
            'card'       => $card,
            'taxonomies' => extrachill_api_activity_get_post_taxonomies( $post ),
        ),
    ) );
}

function extrachill_api_activity_emit_comment_event( $comment_id, $comment_approved, array $commentdata ) {
    if ( ! function_exists( 'extrachill_api_activity_insert' ) ) {
        return;
    }

    if ( in_array( (string) $comment_approved, array( 'spam', 'trash' ), true ) ) {
        return;
    }

    if ( ! in_array( (string) $comment_approved, array( '1', 'approve' ), true ) ) {
        return;
    }

    $comment_id = absint( $comment_id );
    if ( ! $comment_id ) {
        return;
    }

    $comment = get_comment( $comment_id );
    if ( ! $comment ) {
        return;
    }

    $post_id = absint( $comment->comment_post_ID );
    if ( ! $post_id ) {
        return;
    }

    $blog_id  = get_current_blog_id();
    $actor_id = isset( $commentdata['user_ID'] ) ? absint( $commentdata['user_ID'] ) : null;
    if ( ! $actor_id ) {
        $actor_id = get_current_user_id();
    }

    $post_title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $summary    = 'Commented on: ' . $post_title;

    $card = array(
        'title'     => $post_title,
        'permalink' => get_permalink( $post_id ),
    );

	do_action( 'extrachill_activity_emit', array(
		'type'            => 'comment_created',
		'blog_id'         => $blog_id,
		'actor_id'        => $actor_id ? (int) $actor_id : null,
		'summary'         => $summary,
		'visibility'      => 'public',
        'primary_object'  => array(
            'object_type' => 'comment',
            'blog_id'     => $blog_id,
            'id'          => (string) $comment_id,
        ),
        'secondary_object' => array(
            'object_type' => 'post',
            'blog_id'     => $blog_id,
            'id'          => (string) $post_id,
        ),
        'data' => array(
            'post_id' => (int) $post_id,
            'card'    => $card,
        ),
    ) );
}
