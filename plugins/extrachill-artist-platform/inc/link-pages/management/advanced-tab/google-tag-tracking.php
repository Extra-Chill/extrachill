<?php
/**
 * Google Tag Tracking Settings Handler for Advanced Tab
 *
 * SETTINGS MANAGEMENT ONLY - Handles saving/retrieving Google Tag IDs (GA4/Ads)
 * and validation for link pages. Rendering/output of tracking code is handled
 * in live page files.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate Google Tag ID format
 *
 * @param string $tag_id The tag ID to validate
 * @return bool True if valid, false otherwise
 */
function extrch_validate_google_tag_id( $tag_id ) {
    if ( empty( $tag_id ) ) {
        return true; // Empty is valid (disabled)
    }

    // Google Tag IDs follow formats like G-XXXXXXXXXX or AW-XXXXXXXXXX
    return preg_match( '/^(G|AW)-[a-zA-Z0-9]+$/', $tag_id );
}

/**
 * Get Google Tag ID for a link page
 *
 * @param int $link_page_id The link page ID
 * @return string The Google Tag ID or empty string if not set
 */
function extrch_get_google_tag_id( $link_page_id ) {
    if ( empty( $link_page_id ) ) {
        return '';
    }

    return get_post_meta( $link_page_id, '_link_page_google_tag_id', true );
}

/**
 * NOTE: Google Tag ID saving is now handled by centralized save system
 * in inc/core/actions/save.php - this file only provides helper functions.
 */

/**
 * Check if Google Tag tracking is enabled for a link page
 *
 * @param int $link_page_id The link page ID
 * @return bool True if Google Tag is enabled, false otherwise
 */
function extrch_is_google_tag_enabled( $link_page_id ) {
    $tag_id = extrch_get_google_tag_id( $link_page_id );
    return ! empty( $tag_id ) && extrch_validate_google_tag_id( $tag_id );
}


/**
 * Get the type of Google Tag (GA4 or Google Ads)
 *
 * @param string $tag_id The Google Tag ID
 * @return string 'ga4', 'ads', or 'unknown'
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

/**
 * Get Google Tag settings for display in advanced tab
 *
 * @param int $link_page_id The link page ID
 * @return array Array containing Google Tag settings
 */
function extrch_get_google_tag_settings( $link_page_id ) {
    $tag_id = extrch_get_google_tag_id( $link_page_id );
    
    return array(
        'tag_id'      => $tag_id,
        'is_enabled'  => extrch_is_google_tag_enabled( $link_page_id ),
        'is_valid'    => extrch_validate_google_tag_id( $tag_id ),
        'tag_type'    => extrch_get_google_tag_type( $tag_id ),
    );
}