<?php
/**
 * Temporary Redirect Settings Handler for Advanced Tab
 *
 * SETTINGS MANAGEMENT ONLY - Handles saving/retrieving temporary redirect settings.
 * Actual redirect processing is handled in core rewrite rules.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;


/**
 * NOTE: Temporary redirect settings saving is now handled by centralized save system
 * in inc/core/actions/save.php - this file only provides helper functions.
 */

/**
 * Get current temporary redirect settings for display
 *
 * @param int $artist_id The artist profile ID
 * @param int $link_page_id The link page ID
 * @return array Array containing redirect settings
 */
function extrachill_artist_get_temporary_redirect_settings( $artist_id, $link_page_id ) {
    $data = ec_get_link_page_data( $artist_id, $link_page_id );
    return array(
        'enabled' => $data['settings']['redirect_enabled'] ?? false,
        'target_url' => $data['settings']['redirect_target_url'] ?? '',
    );
}