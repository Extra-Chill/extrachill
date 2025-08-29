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
 * @param int $link_page_id The link page ID
 * @return array Array containing redirect settings
 */
function extrch_get_temporary_redirect_settings( $link_page_id ) {
    if ( empty( $link_page_id ) ) {
        return array(
            'enabled' => false,
            'target_url' => '',
        );
    }

    return array(
        'enabled' => get_post_meta( $link_page_id, '_link_page_redirect_enabled', true ) === '1',
        'target_url' => get_post_meta( $link_page_id, '_link_page_redirect_target_url', true ),
    );
}