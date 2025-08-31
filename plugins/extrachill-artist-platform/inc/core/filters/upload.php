<?php
/**
 * File Upload Functions for ExtraChill Artist Platform
 * 
 * Centralized file upload handling for artist profiles and link pages.
 * Uses WordPress native media_handle_upload() and related functions.
 * 
 * Note: This module will be moved to the theme when theme architecture
 * is restructured to handle user-facing file uploads.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle file uploads for link pages
 *
 * @param int $link_page_id The link page ID
 * @param array $files_data $_FILES array
 */
function ec_handle_link_page_file_uploads( $link_page_id, $files_data ) {
    
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
    }

    $max_file_size = 5 * 1024 * 1024; // 5MB

    // Background image upload
    if ( ! empty( $files_data['link_page_background_image_upload']['tmp_name'] ) ) {
        if ( $files_data['link_page_background_image_upload']['size'] <= $max_file_size ) {
            $associated_artist_id = apply_filters('ec_get_artist_id', $link_page_id);
            // Get old image from filter BEFORE updating (single source of truth)
            $data = ec_get_link_page_data( $associated_artist_id, $link_page_id );
            $old_bg_image_id = $data['settings']['background_image_id'] ?? '';
            $new_bg_image_id = media_handle_upload( 'link_page_background_image_upload', $link_page_id );
            
            if ( is_numeric( $new_bg_image_id ) ) {
                update_post_meta( $link_page_id, '_link_page_background_image_id', $new_bg_image_id );
                if ( $old_bg_image_id && $old_bg_image_id != $new_bg_image_id ) {
                    /**
                     * Fires when an old background image should be deleted.
                     * 
                     * This action hook allows cleanup of old background images when
                     * a new one is uploaded, helping maintain storage efficiency.
                     * 
                     * @since 1.0.0
                     * 
                     * @param int $old_bg_image_id The attachment ID of the old background image to delete.
                     */
                    do_action( 'ec_delete_old_bg_image', $old_bg_image_id );
                }
            }
        }
    }

    // Profile image upload
    if ( ! empty( $files_data['link_page_profile_image_upload']['tmp_name'] ) ) {
        if ( $files_data['link_page_profile_image_upload']['size'] <= $max_file_size ) {
            $associated_artist_id = apply_filters('ec_get_artist_id', $link_page_id);
            if ( $associated_artist_id ) {
                // Get old image from filter BEFORE updating (single source of truth) 
                $data = ec_get_link_page_data( $associated_artist_id, $link_page_id );
                $old_profile_image_id = $data['settings']['profile_image_id'] ?? '';
                $attach_id = media_handle_upload( 'link_page_profile_image_upload', $associated_artist_id );
                if ( is_numeric( $attach_id ) ) {
                    set_post_thumbnail( $associated_artist_id, $attach_id );
                    update_post_meta( $link_page_id, '_link_page_profile_image_id', $attach_id );
                    if ( $old_profile_image_id && $old_profile_image_id != $attach_id ) {
                        /**
                         * Fires when an old profile image should be deleted.
                         * 
                         * This action hook allows cleanup of old profile images when
                         * a new one is uploaded, helping maintain storage efficiency.
                         * 
                         * @since 1.0.0
                         * 
                         * @param int $old_profile_image_id The attachment ID of the old profile image to delete.
                         */
                        do_action( 'ec_delete_old_profile_image', $old_profile_image_id );
                    }
                }
            }
        }
    }
}

/**
 * Handle file uploads for artist profiles
 *
 * @param int $artist_id The artist profile ID
 * @param array $files_data $_FILES array
 */
function ec_handle_artist_profile_file_uploads( $artist_id, $files_data ) {
    
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
    }

    $max_file_size = 5 * 1024 * 1024; // 5MB

    // Featured image upload
    if ( ! empty( $files_data['featured_image']['tmp_name'] ) ) {
        if ( $files_data['featured_image']['size'] <= $max_file_size && $files_data['featured_image']['error'] == UPLOAD_ERR_OK ) {
            $old_thumbnail_id = get_post_thumbnail_id( $artist_id );
            $new_image_id = media_handle_upload( 'featured_image', $artist_id );
            
            if ( is_numeric( $new_image_id ) ) {
                set_post_thumbnail( $artist_id, $new_image_id );
                if ( $old_thumbnail_id && $old_thumbnail_id != $new_image_id ) {
                    wp_delete_attachment( $old_thumbnail_id, true );
                }
            }
        }
    } elseif ( isset( $files_data['prefill_avatar_id'] ) && is_numeric( $files_data['prefill_avatar_id'] ) ) {
        // Handle prefill avatar for new artist profiles
        $prefill_avatar_id = absint( $files_data['prefill_avatar_id'] );
        if ( wp_attachment_is_image( $prefill_avatar_id ) ) {
            set_post_thumbnail( $artist_id, $prefill_avatar_id );
        }
    }

    // Artist header image upload
    if ( ! empty( $files_data['artist_header_image']['tmp_name'] ) ) {
        if ( $files_data['artist_header_image']['size'] <= $max_file_size && $files_data['artist_header_image']['error'] == UPLOAD_ERR_OK ) {
            $old_header_image_id = get_post_meta( $artist_id, '_artist_profile_header_image_id', true );
            $new_header_image_id = media_handle_upload( 'artist_header_image', $artist_id );
            
            if ( is_numeric( $new_header_image_id ) ) {
                update_post_meta( $artist_id, '_artist_profile_header_image_id', $new_header_image_id );
                if ( $old_header_image_id && $old_header_image_id != $new_header_image_id ) {
                    wp_delete_attachment( $old_header_image_id, true );
                }
            }
        }
    }
}