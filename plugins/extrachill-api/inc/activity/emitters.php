<?php
/**
 * Core activity emitters for WordPress events.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'transition_post_status', 'extrachill_api_activity_emit_post_events', 10, 3 );
add_action( 'comment_post', 'extrachill_api_activity_emit_comment_event', 10, 3 );

function extrachill_api_activity_build_event_card( WP_Post $post ) {
    $card = array();

    if ( class_exists( '\\DataMachineEvents\\Blocks\\Calendar\\Calendar_Query' ) ) {
        $event_data = \DataMachineEvents\Blocks\Calendar\Calendar_Query::parse_event_data( $post );

        if ( is_array( $event_data ) && ! empty( $event_data['startDate'] ) ) {
            $card['event_date'] = sanitize_text_field( $event_data['startDate'] );

            if ( ! empty( $event_data['startTime'] ) ) {
                $time = sanitize_text_field( $event_data['startTime'] );
                if ( preg_match( '/^\\d{2}:\\d{2}:\\d{2}$/', $time ) ) {
                    $card['event_time'] = $time;
                }
            }
        }
    }

    if ( empty( $card['event_date'] ) || empty( $card['event_time'] ) ) {
        $event_datetime = get_post_meta( $post->ID, '_datamachine_event_datetime', true );
        if ( $event_datetime ) {
            $dt = new DateTime( $event_datetime, wp_timezone() );

            if ( empty( $card['event_date'] ) ) {
                $card['event_date'] = $dt->format( 'Y-m-d' );
            }

            if ( empty( $card['event_time'] ) ) {
                $card['event_time'] = $dt->format( 'H:i:s' );
            }
        }
    }

    $venue_terms = get_the_terms( $post->ID, 'venue' );
    if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
        $card['venue_name'] = $venue_terms[0]->name;
    }

    return $card;
}

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

    if ( $post->post_type === 'datamachine_events' ) {
        $card = array_merge( $card, extrachill_api_activity_build_event_card( $post ) );
    }

    if ( $post->post_type === 'reply' && function_exists( 'bbp_get_reply_topic_id' ) ) {
        $topic_id = bbp_get_reply_topic_id( $post->ID );
        if ( $topic_id ) {
            $card['parent_topic_id']    = (int) $topic_id;
            $card['parent_topic_title'] = html_entity_decode(
                get_the_title( $topic_id ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }
    }

    do_action( 'extrachill_activity_emit', array(
        'type'       => $type,
        'blog_id'    => $blog_id,
        'actor_id'   => $actor_id ? (int) $actor_id : null,
        'summary'    => $summary,
        'visibility' => 'public',
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

    do_action( 'extrachill_activity_emit', array(
        'type'       => 'comment_created',
        'blog_id'    => $blog_id,
        'actor_id'   => $actor_id ? (int) $actor_id : null,
        'summary'    => $summary,
        'visibility' => 'public',
        'primary_object' => array(
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
        ),
    ) );
}
