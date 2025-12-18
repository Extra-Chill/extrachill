<?php
/**
 * Activity storage helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function extrachill_api_activity_insert( $event ) {
    $normalized = extrachill_api_activity_normalize_event( $event );
    if ( is_wp_error( $normalized ) ) {
        return $normalized;
    }

    $table_name = extrachill_api_activity_get_table_name();

    global $wpdb;

    $data_json = null;
    if ( null !== $normalized['data'] ) {
        $data_json = wp_json_encode( $normalized['data'] );
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'created_at'              => $normalized['created_at'],
            'type'                    => $normalized['type'],
            'blog_id'                 => $normalized['blog_id'],
            'actor_id'                => $normalized['actor_id'],
            'primary_object_type'     => $normalized['primary']['type'],
            'primary_object_blog_id'  => $normalized['primary']['blog_id'],
            'primary_object_id'       => $normalized['primary']['id'],
            'secondary_object_type'   => $normalized['secondary']['type'],
            'secondary_object_blog_id' => $normalized['secondary']['blog_id'],
            'secondary_object_id'     => $normalized['secondary']['id'],
            'summary'                 => $normalized['summary'],
            'visibility'              => $normalized['visibility'],
            'data'                    => $data_json,
        ),
        array(
            '%s',
            '%s',
            '%d',
            '%d',
            '%s',
            '%d',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );

    if ( false === $inserted ) {
        return new WP_Error( 'db_insert_failed', 'Failed to record activity event.', array( 'status' => 500 ) );
    }

    $id = (int) $wpdb->insert_id;

    return array(
        'id'         => $id,
        'created_at' => gmdate( 'c', strtotime( $normalized['created_at'] . ' UTC' ) ),
        'type'       => $normalized['type'],
        'blog_id'    => $normalized['blog_id'],
        'actor_id'   => $normalized['actor_id'],
        'summary'    => $normalized['summary'],
        'visibility' => $normalized['visibility'],
        'data'       => $normalized['data'],
        'primary_object' => array(
            'object_type' => $normalized['primary']['type'],
            'blog_id'     => $normalized['primary']['blog_id'],
            'id'          => $normalized['primary']['id'],
        ),
        'secondary_object' => $normalized['secondary']['type'] ? array(
            'object_type' => $normalized['secondary']['type'],
            'blog_id'     => $normalized['secondary']['blog_id'],
            'id'          => $normalized['secondary']['id'],
        ) : null,
        'created_at' => gmdate( 'c', strtotime( $normalized['created_at'] . ' UTC' ) ),
    );
}

function extrachill_api_activity_query( $args = array() ) {
    global $wpdb;

    $table_name = extrachill_api_activity_get_table_name();

    $limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
    if ( $limit < 1 ) {
        $limit = 50;
    }

    if ( $limit > 100 ) {
        $limit = 100;
    }

    $cursor     = isset( $args['cursor'] ) ? absint( $args['cursor'] ) : 0;
    $blog_id     = isset( $args['blog_id'] ) ? absint( $args['blog_id'] ) : 0;
    $actor_id    = isset( $args['actor_id'] ) ? absint( $args['actor_id'] ) : 0;
    $visibility = isset( $args['visibility'] ) ? sanitize_text_field( $args['visibility'] ) : 'private';

    if ( '' === $visibility ) {
        $visibility = 'private';
    }

    if ( ! in_array( $visibility, array( 'private', 'public' ), true ) ) {
        $visibility = 'private';
    }

    $types = isset( $args['types'] ) && is_array( $args['types'] ) ? $args['types'] : array();
    $types = array_values( array_filter( array_map( 'sanitize_text_field', $types ) ) );

    $where = array( 'visibility = %s' );
    $params = array( $visibility );

    if ( $cursor ) {
        $where[] = 'id < %d';
        $params[] = $cursor;
    }

    if ( $blog_id ) {
        $where[] = 'blog_id = %d';
        $params[] = $blog_id;
    }

    if ( $actor_id ) {
        $where[] = 'actor_id = %d';
        $params[] = $actor_id;
    }

    if ( ! empty( $types ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $where[] = "type IN ({$placeholders})";
        foreach ( $types as $type ) {
            $params[] = $type;
        }
    }

    $where_sql = implode( ' AND ', $where );

    $sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY id DESC LIMIT %d";
    $params[] = $limit;

    $prepared = $wpdb->prepare( $sql, $params );
    $rows = $wpdb->get_results( $prepared, ARRAY_A );

        $items = array();
    foreach ( $rows as $row ) {
        $data = null;
        if ( ! empty( $row['data'] ) ) {
            $decoded = json_decode( $row['data'], true );
            if ( is_array( $decoded ) ) {
                $data = $decoded;
            }
        }

        $items[] = array(
            'id'         => (int) $row['id'],
            'created_at' => extrachill_api_activity_normalize_created_at( $row['created_at'] ),
            'type'       => $row['type'],
            'blog_id'    => (int) $row['blog_id'],
            'actor_id'   => null !== $row['actor_id'] ? (int) $row['actor_id'] : null,
            'summary'    => $row['summary'],
            'visibility' => $row['visibility'],
            'data'       => $data,
            'primary_object' => array(
                'object_type' => $row['primary_object_type'],
                'blog_id'     => (int) $row['primary_object_blog_id'],
                'id'          => $row['primary_object_id'],
            ),
            'secondary_object' => $row['secondary_object_type'] ? array(
                'object_type' => $row['secondary_object_type'],
                'blog_id'     => (int) $row['secondary_object_blog_id'],
                'id'          => $row['secondary_object_id'],
            ) : null,
        );
    }

    $next_cursor = null;
    if ( ! empty( $items ) ) {
        $next_cursor = (int) $items[ count( $items ) - 1 ]['id'];
    }

    return array(
        'items' => $items,
        'next_cursor' => $next_cursor,
    );
}

function extrachill_api_activity_normalize_created_at( $created_at ) {
    return gmdate( 'c', strtotime( $created_at . ' UTC' ) );
}
