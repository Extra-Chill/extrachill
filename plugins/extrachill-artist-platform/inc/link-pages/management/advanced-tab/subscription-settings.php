<?php
/**
 * Subscription Settings Handler for Advanced Tab
 *
 * SETTINGS MANAGEMENT ONLY - Handles saving/retrieving subscription display modes
 * and form descriptions. Actual subscription form rendering is handled in live page files.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get available subscription display mode options
 *
 * @return array Array of subscription options with values and labels
 */
function extrch_get_subscription_display_options() {
    return array(
        'icon_modal'   => __( 'Show Subscribe Icon (opens modal)', 'extrachill-artist-platform' ),
        'inline_form'  => __( 'Show Inline Subscribe Form (below links)', 'extrachill-artist-platform' ),
        'disabled'     => __( 'Disable Subscription Feature', 'extrachill-artist-platform' ),
    );
}

/**
 * Get current subscription display mode for a link page
 *
 * @param int $artist_id The artist profile ID
 * @param int $link_page_id The link page ID
 * @return string The current subscription display mode
 */
function extrch_get_subscription_display_mode( $artist_id, $link_page_id ) {
    $data = ec_get_link_page_data( $artist_id, $link_page_id );
    return $data['settings']['subscribe_display_mode'] ?? 'icon_modal';
}

/**
 * Get subscription form description for a link page
 *
 * @param int $artist_id The artist profile ID
 * @param int $link_page_id The link page ID
 * @return string The subscription form description
 */
function extrch_get_subscription_description( $artist_id, $link_page_id ) {
    $data = ec_get_link_page_data( $artist_id, $link_page_id );
    return $data['settings']['subscribe_description'] ?? '';
}

/**
 * NOTE: Subscription settings saving is now handled by centralized save system
 * in inc/core/actions/save.php - this file only provides helper functions.
 */

/**
 * Check if subscription feature is enabled for a link page
 *
 * @param int $link_page_id The link page ID
 * @return bool True if subscription is enabled, false if disabled
 */
function extrch_is_subscription_enabled( $link_page_id ) {
    $display_mode = extrch_get_subscription_display_mode( $link_page_id );
    return $display_mode !== 'disabled';
}

/**
 * Get current subscription settings for display in advanced tab
 *
 * @param int    $link_page_id The link page ID
 * @param string $artist_name  Optional artist name for default description
 * @return array Array containing all subscription settings
 */
function extrch_get_subscription_settings( $link_page_id, $artist_name = '' ) {
    return array(
        'display_mode'        => extrch_get_subscription_display_mode( $link_page_id ),
        'description'         => extrch_get_subscription_description( $link_page_id, $artist_name ),
        'available_modes'     => extrch_get_subscription_display_options(),
        'is_enabled'          => extrch_is_subscription_enabled( $link_page_id ),
    );
}