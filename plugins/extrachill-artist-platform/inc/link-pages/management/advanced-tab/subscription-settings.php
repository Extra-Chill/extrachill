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
function extrachill_artist_get_subscription_display_options() {
	return array(
		'icon_modal'  => __( 'Show Subscribe Icon (opens modal)', 'extrachill-artist-platform' ),
		'inline_form' => __( 'Show Inline Subscribe Form (below links)', 'extrachill-artist-platform' ),
		'disabled'    => __( 'Disable Subscription Feature', 'extrachill-artist-platform' ),
	);
}

/**
 * Get current subscription display mode for a link page
 *
 * @param int $artist_id The artist profile ID
 * @param int $link_page_id The link page ID
 * @return string The current subscription display mode
 */
function extrachill_artist_get_subscription_display_mode( $artist_id, $link_page_id ) {
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
function extrachill_artist_get_subscription_description( $artist_id, $link_page_id ) {
	$data = ec_get_link_page_data( $artist_id, $link_page_id );
	return $data['settings']['subscribe_description'] ?? '';
}

/**
 * NOTE: Subscription settings saving is now handled by centralized save system
 * in inc/core/actions/save.php - this file only provides helper functions.
 */
