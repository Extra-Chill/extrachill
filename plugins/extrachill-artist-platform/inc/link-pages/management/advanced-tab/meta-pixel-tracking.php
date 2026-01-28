<?php
/**
 * Meta Pixel Tracking Settings Handler for Advanced Tab
 *
 * SETTINGS MANAGEMENT ONLY - Handles saving/retrieving Meta (Facebook) Pixel IDs
 * and validation for link pages. Rendering/output of pixel code is handled
 * in live page files.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate Meta Pixel ID format
 *
 * @param string $pixel_id The pixel ID to validate
 * @return bool True if valid, false otherwise
 */
function extrachill_artist_validate_meta_pixel_id( $pixel_id ) {
    if ( empty( $pixel_id ) ) {
        return true; // Empty is valid (disabled)
    }

    // Meta Pixel IDs are typically 15-16 digit numbers
    return ctype_digit( $pixel_id ) && strlen( $pixel_id ) >= 15 && strlen( $pixel_id ) <= 16;
}

/**
 * Get Meta Pixel ID for a link page
 *
 * @param int $artist_id The artist profile ID
 * @param int $link_page_id The link page ID
 * @return string The Meta Pixel ID or empty string if not set
 */
function extrachill_artist_get_meta_pixel_id( $artist_id, $link_page_id ) {
    $data = ec_get_link_page_data( $artist_id, $link_page_id );
    return $data['settings']['meta_pixel_id'] ?? '';
}

/**
 * NOTE: Meta Pixel ID saving is now handled by centralized save system
 * in inc/core/actions/save.php - this file only provides helper functions.
 */

/**
 * Check if Meta Pixel tracking is enabled for a link page
 *
 * @param int $link_page_id The link page ID
 * @return bool True if Meta Pixel is enabled, false otherwise
 */
function extrachill_artist_is_meta_pixel_enabled( $link_page_id ) {
    $pixel_id = extrachill_artist_get_meta_pixel_id( $link_page_id );
    return ! empty( $pixel_id ) && extrachill_artist_validate_meta_pixel_id( $pixel_id );
}


/**
 * Get Meta Pixel settings for display in advanced tab
 *
 * @param int $link_page_id The link page ID
 * @return array Array containing Meta Pixel settings
 */
function extrachill_artist_get_meta_pixel_settings( $link_page_id ) {
    return array(
        'pixel_id'    => extrachill_artist_get_meta_pixel_id( $link_page_id ),
        'is_enabled'  => extrachill_artist_is_meta_pixel_enabled( $link_page_id ),
        'is_valid'    => extrachill_artist_validate_meta_pixel_id( extrachill_artist_get_meta_pixel_id( $link_page_id ) ),
    );
}