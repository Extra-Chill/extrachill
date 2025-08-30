<?php
/**
 * Delete Actions - Centralized cleanup operations
 * 
 * Handles all delete/cleanup side effects triggered by custom actions.
 * Keeps the filter system pure for data reading only.
 * 
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delete old background image attachment
 *
 * @param int $old_image_id The attachment ID to delete
 */
function extrch_cleanup_background_image( $old_image_id ) {
    if ( $old_image_id && is_numeric( $old_image_id ) ) {
        wp_delete_attachment( $old_image_id, true );
    }
}
add_action( 'ec_delete_old_bg_image', 'extrch_cleanup_background_image' );

/**
 * Delete old profile image attachment
 *
 * @param int $old_image_id The attachment ID to delete
 */
function extrch_cleanup_profile_image( $old_image_id ) {
    if ( $old_image_id && is_numeric( $old_image_id ) ) {
        wp_delete_attachment( $old_image_id, true );
    }
}
add_action( 'ec_delete_old_profile_image', 'extrch_cleanup_profile_image' );