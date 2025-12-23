<?php
/**
 * Festival Wire Migration REST API Endpoints
 *
 * Moves festival_wire posts from main site to wire, including featured images
 * and embedded attachments. Designed for use by Admin Tools UI.
 *
 * @endpoint POST /wp-json/extrachill/v1/admin/festival-wire/preflight
 * @endpoint POST /wp-json/extrachill/v1/admin/festival-wire/migrate
 * @endpoint POST /wp-json/extrachill/v1/admin/festival-wire/validate
 * @endpoint POST /wp-json/extrachill/v1/admin/festival-wire/delete
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_festival_wire_migration_routes' );

function extrachill_api_register_festival_wire_migration_routes() {
    register_rest_route(
        'extrachill/v1',
        '/admin/festival-wire/preflight',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'extrachill_api_festival_wire_preflight',
            'permission_callback' => 'extrachill_api_festival_wire_admin_permission_check',
        )
    );

    register_rest_route(
        'extrachill/v1',
        '/admin/festival-wire/migrate',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'extrachill_api_festival_wire_migrate_batch',
            'permission_callback' => 'extrachill_api_festival_wire_admin_permission_check',
            'args'                => array(
                'batch_size' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );

    register_rest_route(
        'extrachill/v1',
        '/admin/festival-wire/validate',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'extrachill_api_festival_wire_validate',
            'permission_callback' => 'extrachill_api_festival_wire_admin_permission_check',
        )
    );

    register_rest_route(
        'extrachill/v1',
        '/admin/festival-wire/delete',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'extrachill_api_festival_wire_delete_batch',
            'permission_callback' => 'extrachill_api_festival_wire_admin_permission_check',
            'args'                => array(
                'batch_size' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );
}

function extrachill_api_festival_wire_admin_permission_check() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to run migrations.',
            array( 'status' => 403 )
        );
    }

    return true;
}

function extrachill_api_festival_wire_state_key() {
    return 'extrachill_festival_wire_migration_state';
}

function extrachill_api_festival_wire_get_blog_ids() {
    if ( ! function_exists( 'ec_get_blog_id' ) ) {
        return new WP_Error(
            'dependency_missing',
            'Required function ec_get_blog_id() not available.',
            array( 'status' => 500 )
        );
    }

    $source_blog_id = ec_get_blog_id( 'main' );
    $target_blog_id = ec_get_blog_id( 'wire' );

    if ( ! $source_blog_id || ! $target_blog_id ) {
        return new WP_Error(
            'blog_ids_missing',
            'Required blog IDs are not available.',
            array( 'status' => 500 )
        );
    }

    return array(
        'source' => absint( $source_blog_id ),
        'target' => absint( $target_blog_id ),
    );
}

function extrachill_api_festival_wire_preflight() {
    $blog_ids = extrachill_api_festival_wire_get_blog_ids();
    if ( is_wp_error( $blog_ids ) ) {
        return $blog_ids;
    }

    $source_count = 0;
    $target_count = 0;

    try {
        switch_to_blog( $blog_ids['source'] );
        $source_count = (int) wp_count_posts( 'festival_wire' )->publish;
    } finally {
        restore_current_blog();
    }

    try {
        switch_to_blog( $blog_ids['target'] );
        $target_count = (int) wp_count_posts( 'festival_wire' )->publish;
    } finally {
        restore_current_blog();
    }

    $state = get_site_option( extrachill_api_festival_wire_state_key(), array() );

    return rest_ensure_response(
        array(
            'source_blog_id' => $blog_ids['source'],
            'target_blog_id' => $blog_ids['target'],
            'source_publish' => $source_count,
            'target_publish' => $target_count,
            'state'          => $state,
        )
    );
}

function extrachill_api_festival_wire_migrate_batch( WP_REST_Request $request ) {
    $blog_ids = extrachill_api_festival_wire_get_blog_ids();
    if ( is_wp_error( $blog_ids ) ) {
        return $blog_ids;
    }

    $batch_size = absint( $request->get_param( 'batch_size' ) );
    if ( $batch_size < 1 ) {
        $batch_size = 25;
    }
    if ( $batch_size > 200 ) {
        $batch_size = 200;
    }

    $state = get_site_option(
        extrachill_api_festival_wire_state_key(),
        array(
            'last_source_id' => 0,
            'migrated'       => array(),
            'validated'      => false,
        )
    );

    $last_source_id = isset( $state['last_source_id'] ) ? absint( $state['last_source_id'] ) : 0;

    $source_post_ids = array();

    try {
        switch_to_blog( $blog_ids['source'] );

        global $wpdb;

        $source_post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                'festival_wire',
                'publish',
                $last_source_id,
                $batch_size
            )
        );
    } finally {
        restore_current_blog();
    }

    $source_post_ids = array_values( array_filter( array_map( 'absint', $source_post_ids ) ) );

    if ( empty( $source_post_ids ) ) {
        return rest_ensure_response(
            array(
                'message' => 'No posts found for migration batch.',
                'state'   => $state,
            )
        );
    }

    $source_posts = array();

    try {
        switch_to_blog( $blog_ids['source'] );

        $source_posts = get_posts(
            array(
                'post_type'      => 'festival_wire',
                'post_status'    => array( 'publish' ),
                'post__in'       => $source_post_ids,
                'orderby'        => 'post__in',
                'posts_per_page' => count( $source_post_ids ),
                'no_found_rows'  => true,
            )
        );
    } finally {
        restore_current_blog();
    }

    if ( empty( $source_posts ) ) {
        return rest_ensure_response(
            array(
                'message' => 'No posts found for migration batch.',
                'state'   => $state,
            )
        );
    }

    $results = array(
        'migrated' => 0,
        'skipped'  => 0,
        'items'    => array(),
    );

    foreach ( $source_posts as $source_post ) {
        $migrate_result = extrachill_api_festival_wire_migrate_single( $blog_ids, $source_post );
        if ( is_wp_error( $migrate_result ) ) {
            $results['items'][] = array(
                'source_post_id' => $source_post->ID,
                'error'          => $migrate_result->get_error_message(),
            );
            continue;
        }

        if ( ! empty( $migrate_result['already_migrated'] ) ) {
            $results['skipped']++;
        } else {
            $results['migrated']++;
        }

        $results['items'][] = $migrate_result;

        $state['migrated'][ (string) $source_post->ID ] = $migrate_result['target_post_id'];
        $state['last_source_id']                        = $source_post->ID;
    }

    $state['validated'] = false;
    update_site_option( extrachill_api_festival_wire_state_key(), $state );

    return rest_ensure_response(
        array(
            'state'   => $state,
            'results' => $results,
        )
    );
}

function extrachill_api_festival_wire_migrate_single( $blog_ids, WP_Post $source_post ) {
    $source_post_id = absint( $source_post->ID );

    $source_thumbnail_id = 0;
    $source_meta         = array();
    $source_terms        = array();

    try {
        switch_to_blog( $blog_ids['source'] );
        $source_thumbnail_id = (int) get_post_thumbnail_id( $source_post_id );
        $source_meta         = get_post_meta( $source_post_id );
        $source_terms        = array(
            'festival'  => wp_get_object_terms( $source_post_id, 'festival' ),
            'location'  => wp_get_object_terms( $source_post_id, 'location' ),
        );
    } finally {
        restore_current_blog();
    }

    $existing_target_post_id = 0;

    try {
        switch_to_blog( $blog_ids['target'] );

        $existing = get_posts(
            array(
                'post_type'      => 'festival_wire',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => '_ec_migrated_from_post_id',
                        'value'   => $source_post_id,
                        'compare' => '=',
                        'type'    => 'NUMERIC',
                    ),
                ),
            )
        );

        if ( ! empty( $existing ) ) {
            $existing_target_post_id = absint( $existing[0] );
        } else {
            global $wpdb;

            $existing_target_post_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_date_gmt = %s LIMIT 1",
                    'festival_wire',
                    $source_post->post_title,
                    $source_post->post_date_gmt
                )
            );

            if ( ! $existing_target_post_id ) {
                $existing_target_post_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
                        'festival_wire',
                        $source_post->post_title
                    )
                );
            }

            if ( $existing_target_post_id > 0 ) {
                update_post_meta( $existing_target_post_id, '_ec_migrated_from_blog', $blog_ids['source'] );
                update_post_meta( $existing_target_post_id, '_ec_migrated_from_post_id', $source_post_id );
            }
        }
    } finally {
        restore_current_blog();
    }

    if ( $existing_target_post_id ) {
        return array(
            'source_post_id'   => $source_post_id,
            'target_post_id'   => $existing_target_post_id,
            'already_migrated' => true,
        );
    }

    $target_post_id = 0;

    try {
        switch_to_blog( $blog_ids['target'] );

        $target_post_id = wp_insert_post(
            array(
                'post_type'      => 'festival_wire',
                'post_status'    => $source_post->post_status,
                'post_title'     => $source_post->post_title,
                'post_name'      => $source_post->post_name,
                'post_content'   => $source_post->post_content,
                'post_excerpt'   => $source_post->post_excerpt,
                'post_author'    => $source_post->post_author,
                'post_date'      => $source_post->post_date,
                'post_date_gmt'  => $source_post->post_date_gmt,
                'post_modified'  => $source_post->post_modified,
                'post_modified_gmt' => $source_post->post_modified_gmt,
            ),
            true
        );

        if ( is_wp_error( $target_post_id ) ) {
            return $target_post_id;
        }

        foreach ( $source_meta as $key => $values ) {
            $key = sanitize_key( $key );

            if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
                continue;
            }

            foreach ( $values as $value ) {
                update_post_meta( $target_post_id, $key, maybe_unserialize( $value ) );
            }
        }

        update_post_meta( $target_post_id, '_ec_migrated_from_blog', $blog_ids['source'] );
        update_post_meta( $target_post_id, '_ec_migrated_from_post_id', $source_post_id );

        foreach ( $source_terms as $taxonomy => $terms ) {
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            $slugs = array();
            foreach ( $terms as $term ) {
                $existing = term_exists( $term->slug, $taxonomy );
                if ( ! $existing ) {
                    wp_insert_term( $term->name, $taxonomy, array( 'slug' => $term->slug ) );
                }
                $slugs[] = $term->slug;
            }

            if ( ! empty( $slugs ) ) {
                wp_set_object_terms( $target_post_id, $slugs, $taxonomy, false );
            }
        }

        $attachment_id_map  = array();
        $attachment_url_map = array();

        if ( $source_thumbnail_id ) {
            $new_thumb_id = extrachill_api_festival_wire_migrate_attachment( $blog_ids, $source_thumbnail_id, $target_post_id, $attachment_url_map );
            if ( is_wp_error( $new_thumb_id ) ) {
                return $new_thumb_id;
            }

            set_post_thumbnail( $target_post_id, $new_thumb_id );
            $attachment_id_map[ $source_thumbnail_id ] = $new_thumb_id;
        }

        $content_result = extrachill_api_festival_wire_migrate_content_attachments( $blog_ids, $source_post->post_content, $target_post_id, $attachment_id_map, $attachment_url_map );
        if ( is_wp_error( $content_result ) ) {
            return $content_result;
        }

        $content_with_ids                  = $content_result['content'];
        $first_inline_target_attachment_id = absint( $content_result['first_inline_target_attachment_id'] );

        if ( ! $source_thumbnail_id && $first_inline_target_attachment_id ) {
            set_post_thumbnail( $target_post_id, $first_inline_target_attachment_id );
        }

        if ( $content_with_ids !== $source_post->post_content ) {
            wp_update_post(
                array(
                    'ID'           => $target_post_id,
                    'post_content' => $content_with_ids,
                )
            );
        }
    } finally {
        restore_current_blog();
    }

    return array(
        'source_post_id'   => $source_post_id,
        'target_post_id'   => (int) $target_post_id,
        'already_migrated' => false,
    );
}

function extrachill_api_festival_wire_migrate_content_attachments( $blog_ids, $content, $target_post_id, &$attachment_id_map, &$attachment_url_map ) {
    $first_inline_target_attachment_id = 0;

    $content = extrachill_api_festival_wire_migrate_inline_uploads_in_html(
        $blog_ids,
        $content,
        $target_post_id,
        $attachment_id_map,
        $attachment_url_map,
        $first_inline_target_attachment_id
    );

    if ( is_wp_error( $content ) ) {
        return $content;
    }

    $blocks = parse_blocks( $content );

    $blocks = extrachill_api_festival_wire_update_blocks_attachment_ids( $blog_ids, $blocks, $target_post_id, $attachment_id_map, $attachment_url_map );
    if ( is_wp_error( $blocks ) ) {
        return $blocks;
    }

    $new_content = serialize_blocks( $blocks );

    foreach ( $attachment_id_map as $old_id => $new_id ) {
        $new_content = str_replace( 'wp-image-' . (int) $old_id, 'wp-image-' . (int) $new_id, $new_content );
    }

    foreach ( $attachment_url_map as $old_url => $new_url ) {
        $new_content = str_replace( $old_url, $new_url, $new_content );
    }

    return array(
        'content'                          => $new_content,
        'first_inline_target_attachment_id' => (int) $first_inline_target_attachment_id,
    );
}

function extrachill_api_festival_wire_extract_upload_urls_from_content( $content ) {
    $urls = array();

    if ( preg_match_all( '#https?://[^\s"\']+/wp-content/uploads/[^\s"\'>]+#i', $content, $matches ) ) {
        $urls = array_merge( $urls, $matches[0] );
    }

    if ( preg_match_all( '#/wp-content/uploads/[^\s"\'>]+#i', $content, $matches ) ) {
        $urls = array_merge( $urls, $matches[0] );
    }

    if ( preg_match_all( '#srcset\s*=\s*"([^"]+)"#i', $content, $matches ) ) {
        foreach ( $matches[1] as $srcset ) {
            $candidates = explode( ',', $srcset );
            foreach ( $candidates as $candidate ) {
                $candidate = trim( $candidate );
                if ( '' === $candidate ) {
                    continue;
                }

                $parts = preg_split( '/\s+/', $candidate );
                if ( ! empty( $parts[0] ) ) {
                    $urls[] = $parts[0];
                }
            }
        }
    }

    $urls = array_values( array_unique( array_filter( array_map( 'trim', $urls ) ) ) );

    return $urls;
}

function extrachill_api_festival_wire_normalize_upload_url_for_blog( $url, $uploads_baseurl ) {
        if ( strpos( $url, '/wp-content/uploads/' ) === false ) {
            return $url;
        }

        if ( str_starts_with( $url, '/' ) ) {
            $url = 'https://example.invalid' . $url;
        }

    $parts = explode( '/wp-content/uploads/', $url, 2 );
    if ( empty( $parts[1] ) ) {
        return $url;
    }

    $relative = ltrim( $parts[1], '/' );

    return trailingslashit( untrailingslashit( $uploads_baseurl ) ) . $relative;
}

function extrachill_api_festival_wire_attachment_id_from_upload_url( $url ) {
    $attachment_id = absint( attachment_url_to_postid( $url ) );
    if ( $attachment_id ) {
        return $attachment_id;
    }

    $url_parts = wp_parse_url( $url );
    if ( empty( $url_parts['path'] ) ) {
        return 0;
    }

    $path = $url_parts['path'];
    $path = preg_replace( '#-\d+x\d+(\.[a-zA-Z0-9]+)$#', '$1', $path );

    $rebuilt = $url;
    if ( isset( $url_parts['scheme'], $url_parts['host'] ) ) {
        $rebuilt = $url_parts['scheme'] . '://' . $url_parts['host'] . $path;
        if ( ! empty( $url_parts['query'] ) ) {
            $rebuilt .= '?' . $url_parts['query'];
        }
    }

    return absint( attachment_url_to_postid( $rebuilt ) );
}

function extrachill_api_festival_wire_migrate_inline_uploads_in_html( $blog_ids, $content, $target_post_id, &$attachment_id_map, &$attachment_url_map, &$first_inline_target_attachment_id ) {
    $urls = extrachill_api_festival_wire_extract_upload_urls_from_content( $content );
    if ( empty( $urls ) ) {
        return $content;
    }

    $source_uploads_baseurl = '';

    try {
        switch_to_blog( $blog_ids['source'] );
        $source_uploads = wp_upload_dir();
        $source_uploads_baseurl = isset( $source_uploads['baseurl'] ) ? (string) $source_uploads['baseurl'] : '';
    } finally {
        restore_current_blog();
    }

    foreach ( $urls as $url ) {
        $normalized = $url;

        if ( $source_uploads_baseurl ) {
            $normalized = extrachill_api_festival_wire_normalize_upload_url_for_blog( $url, $source_uploads_baseurl );
        }

        $source_attachment_id = 0;

        try {
            switch_to_blog( $blog_ids['source'] );
            $source_attachment_id = extrachill_api_festival_wire_attachment_id_from_upload_url( $normalized );
        } finally {
            restore_current_blog();
        }

        if ( ! $source_attachment_id ) {
            continue;
        }

        if ( isset( $attachment_id_map[ $source_attachment_id ] ) ) {
            continue;
        }

        $new_id = extrachill_api_festival_wire_migrate_attachment( $blog_ids, $source_attachment_id, $target_post_id, $attachment_url_map );
        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        $attachment_id_map[ $source_attachment_id ] = $new_id;

        $target_url = '';

        try {
            switch_to_blog( $blog_ids['target'] );
            $target_url = (string) wp_get_attachment_url( $new_id );
        } finally {
            restore_current_blog();
        }

        if ( $target_url ) {
            $attachment_url_map[ $url ] = $target_url;

            if ( $normalized && $normalized !== $url ) {
                $attachment_url_map[ $normalized ] = $target_url;
            }
        }

        if ( ! $first_inline_target_attachment_id ) {
            $first_inline_target_attachment_id = (int) $new_id;
        }
    }

    return $content;
}

function extrachill_api_festival_wire_update_blocks_attachment_ids( $blog_ids, $blocks, $target_post_id, &$attachment_id_map, &$attachment_url_map ) {
    $id_keys = array( 'id', 'mediaId', 'imageId' );

    foreach ( $blocks as &$block ) {
        if ( ! is_array( $block ) ) {
            continue;
        }

        if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
            foreach ( $id_keys as $key ) {
                if ( isset( $block['attrs'][ $key ] ) && is_numeric( $block['attrs'][ $key ] ) ) {
                    $old_id = absint( $block['attrs'][ $key ] );
                    if ( $old_id && ! isset( $attachment_id_map[ $old_id ] ) ) {
                        $new_id = extrachill_api_festival_wire_migrate_attachment( $blog_ids, $old_id, $target_post_id, $attachment_url_map );
                        if ( is_wp_error( $new_id ) ) {
                            return $new_id;
                        }
                        $attachment_id_map[ $old_id ] = $new_id;
                    }

                    if ( isset( $attachment_id_map[ $old_id ] ) ) {
                        $block['attrs'][ $key ] = $attachment_id_map[ $old_id ];
                    }
                }
            }

            if ( isset( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
                foreach ( $block['attrs']['ids'] as $i => $old_id ) {
                    $old_id = absint( $old_id );
                    if ( ! $old_id ) {
                        continue;
                    }

                    if ( ! isset( $attachment_id_map[ $old_id ] ) ) {
                        $new_id = extrachill_api_festival_wire_migrate_attachment( $blog_ids, $old_id, $target_post_id, $attachment_url_map );
                        if ( is_wp_error( $new_id ) ) {
                            return $new_id;
                        }
                        $attachment_id_map[ $old_id ] = $new_id;
                    }

                    $block['attrs']['ids'][ $i ] = $attachment_id_map[ $old_id ];
                }
            }
        }

        if ( ! empty( $block['innerBlocks'] ) ) {
            $inner = extrachill_api_festival_wire_update_blocks_attachment_ids( $blog_ids, $block['innerBlocks'], $target_post_id, $attachment_id_map, $attachment_url_map );
            if ( is_wp_error( $inner ) ) {
                return $inner;
            }
            $block['innerBlocks'] = $inner;
        }
    }

    return $blocks;
}

function extrachill_api_festival_wire_migrate_attachment( $blog_ids, $source_attachment_id, $target_post_id, &$attachment_url_map ) {
    $source_attachment_id = absint( $source_attachment_id );

    $attachment_post = null;
    $file_path       = '';
    $source_url      = '';
    $source_sizes    = array();

    try {
        switch_to_blog( $blog_ids['source'] );

        $attachment_post = get_post( $source_attachment_id );
        if ( ! $attachment_post || 'attachment' !== $attachment_post->post_type ) {
            return new WP_Error( 'invalid_attachment', 'Invalid attachment ID.' );
        }

        $file_path = get_attached_file( $source_attachment_id );
        $source_url = wp_get_attachment_url( $source_attachment_id );

        $source_meta = wp_get_attachment_metadata( $source_attachment_id );

        if ( $source_url && is_array( $source_meta ) && ! empty( $source_meta['file'] ) ) {
            $source_dir = trailingslashit( dirname( $source_url ) );

            if ( ! empty( $source_meta['sizes'] ) && is_array( $source_meta['sizes'] ) ) {
                foreach ( $source_meta['sizes'] as $size_meta ) {
                    if ( ! empty( $size_meta['file'] ) ) {
                        $source_sizes[] = $source_dir . ltrim( $size_meta['file'], '/' );
                    }
                }
            }
        }
    } finally {
        restore_current_blog();
    }

    if ( ! $file_path || ! file_exists( $file_path ) ) {
        return new WP_Error( 'missing_file', 'Attachment file not found on disk.' );
    }

    $filename = wp_basename( $file_path );

    $new_attachment_id = 0;

    try {
        switch_to_blog( $blog_ids['target'] );

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'upload_dir_error', $uploads['error'] );
        }

        wp_mkdir_p( $uploads['path'] );

        $new_filename = wp_unique_filename( $uploads['path'], $filename );
        $new_path     = trailingslashit( $uploads['path'] ) . $new_filename;

        if ( ! copy( $file_path, $new_path ) ) {
            return new WP_Error( 'copy_failed', 'Failed to copy attachment file.' );
        }

        $filetype = wp_check_filetype( $new_filename, null );

        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => $attachment_post->post_title,
            'post_content'   => $attachment_post->post_content,
            'post_excerpt'   => $attachment_post->post_excerpt,
            'post_status'    => 'inherit',
        );

        $new_attachment_id = wp_insert_attachment( $attachment, $new_path, $target_post_id );
        if ( is_wp_error( $new_attachment_id ) ) {
            return $new_attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_data = wp_generate_attachment_metadata( $new_attachment_id, $new_path );
        wp_update_attachment_metadata( $new_attachment_id, $attach_data );

        $target_url = wp_get_attachment_url( $new_attachment_id );
        if ( $source_url && $target_url ) {
            $attachment_url_map[ $source_url ] = $target_url;
        }

        if ( ! empty( $source_sizes ) ) {
            foreach ( $source_sizes as $maybe_source_url ) {
                if ( $maybe_source_url ) {
                    $attachment_url_map[ $maybe_source_url ] = $target_url;
                }
            }
        }
    } finally {
        restore_current_blog();
    }

    return (int) $new_attachment_id;
}

function extrachill_api_festival_wire_validate() {
    $blog_ids = extrachill_api_festival_wire_get_blog_ids();
    if ( is_wp_error( $blog_ids ) ) {
        return $blog_ids;
    }

    $state = get_site_option( extrachill_api_festival_wire_state_key(), array() );

    $migrated = isset( $state['migrated'] ) && is_array( $state['migrated'] ) ? $state['migrated'] : array();

    $state['validated'] = ! empty( $migrated );
    update_site_option( extrachill_api_festival_wire_state_key(), $state );

    return rest_ensure_response(
        array(
            'validated'      => (bool) $state['validated'],
            'migrated_count' => count( $migrated ),
            'state'          => $state,
        )
    );
}

function extrachill_api_festival_wire_delete_batch( WP_REST_Request $request ) {
    $blog_ids = extrachill_api_festival_wire_get_blog_ids();
    if ( is_wp_error( $blog_ids ) ) {
        return $blog_ids;
    }

    $batch_size = absint( $request->get_param( 'batch_size' ) );
    if ( $batch_size < 1 ) {
        $batch_size = 25;
    }
    if ( $batch_size > 200 ) {
        $batch_size = 200;
    }

    $state = get_site_option( extrachill_api_festival_wire_state_key(), array() );

    if ( empty( $state['validated'] ) ) {
        return new WP_Error(
            'not_validated',
            'Run validate before deleting source content.',
            array( 'status' => 400 )
        );
    }

    $migrated = isset( $state['migrated'] ) && is_array( $state['migrated'] ) ? $state['migrated'] : array();
    $source_ids = array_map( 'absint', array_keys( $migrated ) );
    sort( $source_ids );

    $source_ids = array_slice( $source_ids, 0, $batch_size );

    if ( empty( $source_ids ) ) {
        return rest_ensure_response(
            array(
                'message' => 'No migrated source posts queued for deletion.',
                'state'   => $state,
            )
        );
    }

    $deleted = array();

    try {
        switch_to_blog( $blog_ids['source'] );

        foreach ( $source_ids as $source_post_id ) {
            $post = get_post( $source_post_id );
            if ( ! $post || 'festival_wire' !== $post->post_type ) {
                unset( $state['migrated'][ (string) $source_post_id ] );
                continue;
            }

            $attachment_ids = array();

            $thumb_id = (int) get_post_thumbnail_id( $source_post_id );
            if ( $thumb_id ) {
                $attachment_ids[] = $thumb_id;
            }

            $blocks = parse_blocks( $post->post_content );
            $attachment_ids = array_merge( $attachment_ids, extrachill_api_festival_wire_collect_attachment_ids_from_blocks( $blocks ) );

            $urls = extrachill_api_festival_wire_extract_upload_urls_from_content( $post->post_content );
            if ( ! empty( $urls ) ) {
                $uploads = wp_upload_dir();
                $baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';

                foreach ( $urls as $url ) {
                    $normalized = $url;

                    if ( $baseurl ) {
                        $normalized = extrachill_api_festival_wire_normalize_upload_url_for_blog( $url, $baseurl );
                    }

                    $maybe_id = extrachill_api_festival_wire_attachment_id_from_upload_url( $normalized );
                    if ( $maybe_id ) {
                        $attachment_ids[] = $maybe_id;
                    }
                }
            }

            $attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );

            foreach ( $attachment_ids as $attachment_id ) {
                $attachment_post = get_post( $attachment_id );
                if ( ! $attachment_post || 'attachment' !== $attachment_post->post_type ) {
                    continue;
                }

                if ( (int) $attachment_post->post_parent !== (int) $source_post_id ) {
                    continue;
                }

                wp_delete_attachment( $attachment_id, true );
            }

            wp_delete_post( $source_post_id, true );
            unset( $state['migrated'][ (string) $source_post_id ] );

            $deleted[] = $source_post_id;
        }
    } finally {
        restore_current_blog();
    }

    update_site_option( extrachill_api_festival_wire_state_key(), $state );

    return rest_ensure_response(
        array(
            'deleted_source_post_ids' => $deleted,
            'state'                  => $state,
        )
    );
}

function extrachill_api_festival_wire_collect_attachment_ids_from_blocks( $blocks ) {
    $ids = array();

    foreach ( $blocks as $block ) {
        if ( ! is_array( $block ) ) {
            continue;
        }

        if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
            foreach ( array( 'id', 'mediaId', 'imageId' ) as $key ) {
                if ( isset( $block['attrs'][ $key ] ) && is_numeric( $block['attrs'][ $key ] ) ) {
                    $ids[] = absint( $block['attrs'][ $key ] );
                }
            }

            if ( isset( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
                foreach ( $block['attrs']['ids'] as $id ) {
                    $ids[] = absint( $id );
                }
            }
        }

        if ( ! empty( $block['innerBlocks'] ) ) {
            $ids = array_merge( $ids, extrachill_api_festival_wire_collect_attachment_ids_from_blocks( $block['innerBlocks'] ) );
        }
    }

    return $ids;
}
