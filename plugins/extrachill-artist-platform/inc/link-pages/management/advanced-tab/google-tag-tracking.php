<?php
/**
 * Google Tag Tracking Settings (GA4/Google Ads)
 *
 * Handles Google Tag ID validation and retrieval. Tracking code rendering
 * is handled in live page files.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate Google Tag ID format (G-* for GA4, AW-* for Google Ads)
 */
function extrch_validate_google_tag_id( $tag_id ) {
    if ( empty( $tag_id ) ) {
        return true;
    }

    return preg_match( '/^(G|AW)-[a-zA-Z0-9]+$/', $tag_id );
}

function extrch_get_google_tag_id( $artist_id, $link_page_id ) {
    $data = ec_get_link_page_data( $artist_id, $link_page_id );
    return $data['settings']['google_tag_id'] ?? '';
}

function extrch_is_google_tag_enabled( $link_page_id ) {
    $tag_id = extrch_get_google_tag_id( $link_page_id );
    return ! empty( $tag_id ) && extrch_validate_google_tag_id( $tag_id );
}

/**
 * Determine tag type from ID prefix
 */
function extrch_get_google_tag_type( $tag_id ) {
    if ( empty( $tag_id ) ) {
        return 'unknown';
    }

    if ( strpos( $tag_id, 'G-' ) === 0 ) {
        return 'ga4';
    }

    if ( strpos( $tag_id, 'AW-' ) === 0 ) {
        return 'ads';
    }

    return 'unknown';
}

function extrch_get_google_tag_settings( $link_page_id ) {
    $tag_id = extrch_get_google_tag_id( $link_page_id );

    return array(
        'tag_id'      => $tag_id,
        'is_enabled'  => extrch_is_google_tag_enabled( $link_page_id ),
        'is_valid'    => extrch_validate_google_tag_id( $tag_id ),
        'tag_type'    => extrch_get_google_tag_type( $tag_id ),
    );
}