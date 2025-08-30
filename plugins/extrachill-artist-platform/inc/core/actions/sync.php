<?php
/**
 * Centralized Artist Platform Sync Operations
 * 
 * Handles complete bidirectional synchronization between artist profiles and link pages.
 * One unified sync action handles ALL data - no granular sync types needed.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages a flag to prevent recursive synchronization.
 */
class ArtistDataSyncManager {
    private static $is_syncing = false;

    public static function is_syncing() {
        return self::$is_syncing;
    }

    public static function start_sync() {
        self::$is_syncing = true;
    }

    public static function stop_sync() {
        self::$is_syncing = false;
    }
}

/**
 * Central function to handle complete bidirectional sync between artist profile and link page
 *
 * @param int $artist_id The artist profile ID to sync
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function ec_handle_artist_platform_sync( $artist_id ) {
    
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID for sync' );
    }

    // Check if sync is already in progress to prevent recursion
    if ( class_exists( 'ArtistDataSyncManager' ) && ArtistDataSyncManager::is_syncing() ) {
        return true; // Skip if already syncing
    }

    $artist_post = get_post( $artist_id );
    if ( ! $artist_post ) {
        return new WP_Error( 'artist_not_found', 'Artist profile post not found' );
    }

    // Get associated link page
    $link_page_id = get_post_meta( $artist_id, '_extrch_link_page_id', true );
    if ( ! $link_page_id || get_post_type( $link_page_id ) !== 'artist_link_page' ) {
        // No link page exists - this is normal for some artist profiles
        return true;
    }

    // Start sync protection
    if ( class_exists( 'ArtistDataSyncManager' ) ) {
        ArtistDataSyncManager::start_sync();
    }

    try {
        // Perform complete bidirectional sync
        $sync_result = ec_perform_complete_sync( $artist_id, $link_page_id );
        
        if ( is_wp_error( $sync_result ) ) {
            return $sync_result;
        }

        // Fire the sync action hook for extensibility
        do_action( 'ec_artist_platform_sync', $artist_id );

        return true;

    } finally {
        // Always stop sync protection
        if ( class_exists( 'ArtistDataSyncManager' ) ) {
            ArtistDataSyncManager::stop_sync();
        }
    }
}

/**
 * Performs the complete bidirectional sync between artist profile and link page
 *
 * @param int $artist_id The artist profile ID
 * @param int $link_page_id The link page ID
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function ec_perform_complete_sync( $artist_id, $link_page_id ) {
    
    $artist_post = get_post( $artist_id );
    if ( ! $artist_post ) {
        return new WP_Error( 'artist_not_found', 'Artist profile not found during sync' );
    }

    // --- ARTIST PROFILE → LINK PAGE SYNC ---
    // Use centralized data system (single source of truth)
    $data = ec_get_link_page_data( $artist_id, $link_page_id );
    
    // Sync Title
    $artist_title = $artist_post->post_title;
    $current_link_title = $data['display_title'] ?? '';
    if ( $current_link_title !== $artist_title ) {
        update_post_meta( $link_page_id, '_link_page_display_title', $artist_title );
    }

    // Sync Bio (Content)
    $artist_bio = $artist_post->post_content;
    $current_link_bio = $data['bio'] ?? '';
    if ( $current_link_bio !== $artist_bio ) {
        update_post_meta( $link_page_id, '_link_page_bio_text', $artist_bio );
    }

    // Sync Profile Picture (Featured Image)
    $artist_thumbnail_id = get_post_thumbnail_id( $artist_id );
    $current_link_thumbnail_id = $data['settings']['profile_image_id'] ?? '';
    
    if ( $artist_thumbnail_id ) {
        if ( $current_link_thumbnail_id != $artist_thumbnail_id ) {
            update_post_meta( $link_page_id, '_link_page_profile_image_id', $artist_thumbnail_id );
        }
    } elseif ( $current_link_thumbnail_id ) {
        // Artist has no thumbnail, remove from link page
        delete_post_meta( $link_page_id, '_link_page_profile_image_id' );
    }

    // --- LINK PAGE → ARTIST PROFILE SYNC ---
    
    // Use centralized data that might have been updated independently (already retrieved above)
    $link_page_title = $data['display_title'] ?? '';
    $link_page_bio = $data['bio'] ?? '';
    $link_page_thumbnail_id = $data['settings']['profile_image_id'] ?? '';

    $artist_update_data = array( 'ID' => $artist_id );
    $needs_artist_update = false;

    // Sync title back to artist profile if link page has different data
    if ( ! empty( $link_page_title ) && $link_page_title !== $artist_post->post_title ) {
        $artist_update_data['post_title'] = $link_page_title;
        $needs_artist_update = true;
    }

    // Sync bio back to artist profile if link page has different data
    if ( ! empty( $link_page_bio ) && $link_page_bio !== $artist_post->post_content ) {
        $artist_update_data['post_content'] = $link_page_bio;
        $needs_artist_update = true;
    }

    // Update artist profile if needed
    if ( $needs_artist_update ) {
        $update_result = wp_update_post( $artist_update_data, true );
        if ( is_wp_error( $update_result ) ) {
            return $update_result;
        }
    }

    // Sync profile picture back to artist if link page has different image
    if ( ! empty( $link_page_thumbnail_id ) && absint( $link_page_thumbnail_id ) > 0 ) {
        if ( absint( $artist_thumbnail_id ) !== absint( $link_page_thumbnail_id ) ) {
            set_post_thumbnail( $artist_id, absint( $link_page_thumbnail_id ) );
        }
    }

    return true;
}

/**
 * Hook meta updates to trigger unified sync
 *
 * @param int $meta_id ID of the metadata entry
 * @param int $object_id ID of the object (post ID) 
 * @param string $meta_key Meta key being updated
 * @param mixed $meta_value New meta value
 */
function ec_sync_on_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
    // Suppress unused parameter warnings - we need all 4 parameters for the hook signature
    unset( $meta_id, $meta_value );
    // Only sync on relevant link page meta keys
    $sync_keys = array(
        '_link_page_display_title',
        '_link_page_bio_text',
        '_link_page_profile_image_id'
    );
    
    if ( get_post_type( $object_id ) === 'artist_link_page' && 
         in_array( $meta_key, $sync_keys, true ) ) {
        
        $artist_id = get_post_meta( $object_id, '_associated_artist_profile_id', true );
        if ( $artist_id && get_post_type( $artist_id ) === 'artist_profile' ) {
            ec_handle_artist_platform_sync( $artist_id );
        }
    }
}
add_action( 'updated_post_meta', 'ec_sync_on_meta_update', 10, 4 );
add_action( 'added_post_meta', 'ec_sync_on_meta_update', 10, 4 );

/**
 * Trigger sync for an artist profile (public API)
 *
 * @param int $artist_id The artist profile ID to sync
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function ec_sync_artist_platform( $artist_id ) {
    return ec_handle_artist_platform_sync( $artist_id );
}