<?php
/**
 * Link page ID generation helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Map ID type to meta key storage on link page post.
 *
 * @return array<string, string>
 */
function extrachill_api_id_meta_key_map() {
    return array(
        'section' => '_ec_section_id_counter',
        'link'    => '_ec_link_id_counter',
        'social'  => '_ec_social_id_counter',
    );
}

/**
 * Returns next available ID for a given type.
 *
 * @param int    $link_page_id Link page post ID.
 * @param string $type         Type key: section|link|social.
 *
 * @return string
 */
function extrachill_api_get_next_id( $link_page_id, $type ) {
    $map = extrachill_api_id_meta_key_map();
    if ( ! isset( $map[ $type ] ) ) {
        return '';
    }

    $meta_key   = $map[ $type ];
    $next_index = (int) get_post_meta( $link_page_id, $meta_key, true );
    $next_index++;
    update_post_meta( $link_page_id, $meta_key, $next_index );

    return sprintf( '%d-%s-%d', $link_page_id, $type, $next_index );
}

/**
 * Determines if ID needs assignment (blank or temp).
 *
 * @param string $id Input ID.
 *
 * @return bool
 */
function extrachill_api_needs_id_assignment( $id ) {
    return empty( $id ) || str_starts_with( $id, 'temp-' );
}

/**
 * Sync counter based on an existing ID value.
 *
 * @param int    $link_page_id Link page post ID.
 * @param string $type         Type key.
 * @param string $id           Existing ID.
 */
function extrachill_api_sync_counter_from_id( $link_page_id, $type, $id ) {
    $map = extrachill_api_id_meta_key_map();
    if ( ! isset( $map[ $type ] ) ) {
        return;
    }

    $pattern = sprintf( '/^(%d)\-%s\-(\d+)$/', (int) $link_page_id, preg_quote( $type, '/' ) );
    if ( 1 !== preg_match( $pattern, $id, $matches ) ) {
        return;
    }

    $current  = (int) $matches[2];
    $meta_key = $map[ $type ];
    $stored   = (int) get_post_meta( $link_page_id, $meta_key, true );

    if ( $current > $stored ) {
        update_post_meta( $link_page_id, $meta_key, $current );
    }
}
