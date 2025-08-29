<?php
/**
 * YouTube Embed Control Settings Handler for Advanced Tab
 *
 * SETTINGS MANAGEMENT ONLY - Handles saving/retrieving YouTube embed settings.
 * Actual URL processing for live pages is handled elsewhere.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check if YouTube inline embed is enabled for a link page
 *
 * @param int $link_page_id The link page ID
 * @return bool True if YouTube inline embed is enabled, false otherwise
 */
function extrch_is_youtube_inline_embed_enabled( $link_page_id ) {
    if ( empty( $link_page_id ) ) {
        return true; // Default to enabled
    }

    // Check if inline embed is explicitly disabled
    $is_disabled = get_post_meta( $link_page_id, '_enable_youtube_inline_embed', true );
    return $is_disabled !== '0';
}

/**
 * NOTE: YouTube embed settings saving is now handled by centralized save system
 * in inc/core/actions/save.php - this file only provides helper functions.
 */

/**
 * Get current YouTube embed settings for display
 *
 * @param int $link_page_id The link page ID
 * @return array Array containing YouTube embed settings
 */
function extrch_get_youtube_embed_settings( $link_page_id ) {
    if ( empty( $link_page_id ) ) {
        return array(
            'inline_enabled' => true,
            'disable_checkbox_checked' => false,
        );
    }

    $is_enabled = extrch_is_youtube_inline_embed_enabled( $link_page_id );
    
    return array(
        'inline_enabled' => $is_enabled,
        'disable_checkbox_checked' => ! $is_enabled, // Checkbox should be checked if feature is disabled
    );
}

