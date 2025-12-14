<?php
/**
 * Activity event normalization and validation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function extrachill_api_activity_normalize_event( $event ) {
    if ( ! is_array( $event ) ) {
        return new WP_Error( 'invalid_event', 'Event must be an array.', array( 'status' => 400 ) );
    }

    $type = isset( $event['type'] ) ? sanitize_text_field( $event['type'] ) : '';
    if ( '' === $type ) {
        return new WP_Error( 'invalid_event', 'Event type is required.', array( 'status' => 400 ) );
    }

    $blog_id = isset( $event['blog_id'] ) ? absint( $event['blog_id'] ) : 0;
    if ( ! $blog_id ) {
        return new WP_Error( 'invalid_event', 'Event blog_id is required.', array( 'status' => 400 ) );
    }

    $primary = isset( $event['primary_object'] ) ? $event['primary_object'] : null;
    if ( ! is_array( $primary ) ) {
        return new WP_Error( 'invalid_event', 'primary_object is required.', array( 'status' => 400 ) );
    }

    $primary_object_type = isset( $primary['object_type'] ) ? sanitize_text_field( $primary['object_type'] ) : '';
    $primary_object_blog_id = isset( $primary['blog_id'] ) ? absint( $primary['blog_id'] ) : 0;
    $primary_object_id = isset( $primary['id'] ) ? (string) $primary['id'] : '';

    if ( '' === $primary_object_type || ! $primary_object_blog_id || '' === $primary_object_id ) {
        return new WP_Error( 'invalid_event', 'primary_object.object_type, blog_id, and id are required.', array( 'status' => 400 ) );
    }

    $secondary = isset( $event['secondary_object'] ) ? $event['secondary_object'] : null;

    $secondary_object_type = null;
    $secondary_object_blog_id = null;
    $secondary_object_id = null;

    if ( is_array( $secondary ) ) {
        $secondary_object_type = isset( $secondary['object_type'] ) ? sanitize_text_field( $secondary['object_type'] ) : null;
        $secondary_object_blog_id = isset( $secondary['blog_id'] ) ? absint( $secondary['blog_id'] ) : null;
        $secondary_object_id = isset( $secondary['id'] ) ? (string) $secondary['id'] : null;

        if ( '' === (string) $secondary_object_type || ! $secondary_object_blog_id || '' === (string) $secondary_object_id ) {
            return new WP_Error( 'invalid_event', 'secondary_object must include object_type, blog_id, and id when present.', array( 'status' => 400 ) );
        }
    }

    $actor_id = null;
    if ( array_key_exists( 'actor_id', $event ) ) {
        $actor_id = $event['actor_id'] ? absint( $event['actor_id'] ) : null;
    }

    $summary = isset( $event['summary'] ) ? wp_strip_all_tags( (string) $event['summary'] ) : '';
    if ( '' === $summary ) {
        return new WP_Error( 'invalid_event', 'Event summary is required.', array( 'status' => 400 ) );
    }

    $visibility = isset( $event['visibility'] ) ? sanitize_text_field( $event['visibility'] ) : 'private';
    if ( '' === $visibility ) {
        $visibility = 'private';
    }

    if ( ! in_array( $visibility, array( 'private', 'public' ), true ) ) {
        return new WP_Error( 'invalid_event', 'Event visibility must be private or public.', array( 'status' => 400 ) );
    }

    $data = null;
    if ( array_key_exists( 'data', $event ) ) {
        $data = $event['data'];
        if ( null !== $data && ! is_array( $data ) ) {
            return new WP_Error( 'invalid_event', 'Event data must be an object/array when present.', array( 'status' => 400 ) );
        }
    }

    return array(
        'created_at' => gmdate( 'Y-m-d H:i:s' ),
        'type'       => $type,
        'blog_id'    => $blog_id,
        'actor_id'   => $actor_id,
        'primary'    => array(
            'type'    => $primary_object_type,
            'blog_id' => $primary_object_blog_id,
            'id'      => $primary_object_id,
        ),
        'secondary'  => array(
            'type'    => $secondary_object_type,
            'blog_id' => $secondary_object_blog_id,
            'id'      => $secondary_object_id,
        ),
        'summary'    => $summary,
        'visibility' => $visibility,
        'data'       => $data,
    );
}
