<?php
/**
 * Handles data synchronization between band_profile and band_link_page CPTs.
 * Ensures that band name, bio, and profile picture are consistent across both.
 *
 * @package ExtrchPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages a flag to prevent recursive synchronization.
 */
class BandDataSyncManager {
    private static bool $is_syncing = false;

    public static function is_syncing(): bool {
        return self::$is_syncing;
    }

    public static function start_sync(): void {
        self::$is_syncing = true;
    }

    public static function stop_sync(): void {
        self::$is_syncing = false;
    }
}

/**
 * Syncs band_profile data (title, content, thumbnail) to its associated band_link_page meta.
 *
 * Triggered when a band_profile post is saved.
 *
 * @param int     $post_id The ID of the post being saved.
 * @param WP_Post $post    The post object.
 * @param bool    $update  Whether this is an existing post being updated or not.
 */
function extrch_sync_band_profile_to_link_page( int $post_id, WP_Post $post, bool $update ): void {
    if ( BandDataSyncManager::is_syncing() ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( 'band_profile' !== $post->post_type ) {
        return;
    }

    $link_page_id = get_post_meta( $post_id, '_extrch_link_page_id', true );
    if ( ! $link_page_id || !is_numeric($link_page_id) || get_post_type( (int) $link_page_id ) !== 'band_link_page' ) {
        return;
    }
    $link_page_id = (int) $link_page_id;

    BandDataSyncManager::start_sync();

    // Sync Title
    if ( get_post_meta( $link_page_id, '_link_page_display_title', true ) !== $post->post_title ) {
        update_post_meta( $link_page_id, '_link_page_display_title', $post->post_title );
    }

    // Sync Bio (Content)
    if ( get_post_meta( $link_page_id, '_link_page_bio_text', true ) !== $post->post_content ) {
        update_post_meta( $link_page_id, '_link_page_bio_text', $post->post_content );
    }

    // Sync Profile Picture (Featured Image ID)
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    $current_link_page_thumbnail_id = get_post_meta( $link_page_id, '_link_page_profile_image_id', true );

    if ( $thumbnail_id ) {
        if ( $current_link_page_thumbnail_id != $thumbnail_id ) {
            update_post_meta( $link_page_id, '_link_page_profile_image_id', $thumbnail_id );
        }
    } elseif ( $current_link_page_thumbnail_id ) {
        // If band_profile has no thumbnail, but link_page still has one associated, remove it from link_page.
        delete_post_meta( $link_page_id, '_link_page_profile_image_id' );
    }

    BandDataSyncManager::stop_sync();
}
add_action( 'save_post_band_profile', 'extrch_sync_band_profile_to_link_page', 10, 3 );

/**
 * Syncs specific band_link_page meta updates (display title, bio, profile image)
 * back to its associated band_profile CPT.
 *
 * @param int    $meta_id     ID of the metadata entry being updated.
 * @param int    $object_id   ID of the object metadata is for (post ID for band_link_page).
 * @param string $meta_key    Meta key being updated.
 * @param mixed  $_meta_value New meta value.
 */
function extrch_sync_link_page_meta_to_band_profile( int $meta_id, int $object_id, string $meta_key, mixed $_meta_value ): void {
    if ( BandDataSyncManager::is_syncing() ) {
        return;
    }

    $post_type = get_post_type( $object_id );
    if ( 'band_link_page' !== $post_type ) {
        return;
    }

    $valid_meta_keys_to_sync = array(
        '_link_page_display_title',
        '_link_page_bio_text',
        '_link_page_profile_image_id',
    );

    if ( ! in_array( $meta_key, $valid_meta_keys_to_sync, true ) ) {
        return;
    }

    $band_profile_id = get_post_meta( $object_id, '_associated_band_profile_id', true );
    if ( ! $band_profile_id || !is_numeric($band_profile_id) || get_post_type( (int) $band_profile_id ) !== 'band_profile' ) {
        return;
    }
    $band_profile_id = (int) $band_profile_id;

    BandDataSyncManager::start_sync();

    $update_args = array( 'ID' => $band_profile_id );
    $perform_post_update = false;

    if ( '_link_page_display_title' === $meta_key ) {
        if ( get_the_title( $band_profile_id ) !== $_meta_value ) {
            $update_args['post_title'] = sanitize_text_field( $_meta_value );
            $perform_post_update = true;
        }
    } elseif ( '_link_page_bio_text' === $meta_key ) {
        if ( get_post_field( 'post_content', $band_profile_id ) !== $_meta_value ) {
            $update_args['post_content'] = wp_kses_post( $_meta_value );
            $perform_post_update = true;
        }
    } elseif ( '_link_page_profile_image_id' === $meta_key ) {
        $current_thumbnail_id = get_post_thumbnail_id( $band_profile_id );
        $new_thumbnail_id = $_meta_value ? absint( $_meta_value ) : 0;

        if ( $new_thumbnail_id && $current_thumbnail_id != $new_thumbnail_id ) {
            set_post_thumbnail( $band_profile_id, $new_thumbnail_id );
        } elseif ( ! $new_thumbnail_id && $current_thumbnail_id ) {
            delete_post_thumbnail( $band_profile_id );
        }
    }

    if ( $perform_post_update && count( $update_args ) > 1 ) {
        wp_update_post( $update_args );
    }

    BandDataSyncManager::stop_sync();
}
// Covers updates to these meta fields on the band_link_page.
add_action( 'updated_post_meta', 'extrch_sync_link_page_meta_to_band_profile', 10, 4 );
// Covers initial creation of these meta fields if they didn't exist.
add_action( 'added_post_meta', 'extrch_sync_link_page_meta_to_band_profile', 10, 4 );

/**
 * Syncs the deletion of the link page's profile image meta to the band_profile.
 * Specifically, removes the featured image from band_profile if _link_page_profile_image_id is deleted.
 *
 * @param array  $meta_ids    An array of metadata entry IDs that were deleted.
 * @param int    $object_id   ID of the object metadata is for.
 * @param string $meta_key    Meta key that was deleted.
 * @param mixed  $_meta_value Value of the meta key that was deleted.
 */
function extrch_sync_link_page_deleted_image_meta_to_band_profile( array $meta_ids, int $object_id, string $meta_key, mixed $_meta_value ): void {
    if ( BandDataSyncManager::is_syncing() ) {
        return;
    }
    if ( '_link_page_profile_image_id' !== $meta_key ) {
        return;
    }

    $post_type = get_post_type( $object_id );
    if ( 'band_link_page' !== $post_type ) {
        return;
    }

    $band_profile_id = get_post_meta( $object_id, '_associated_band_profile_id', true );
    if ( ! $band_profile_id || !is_numeric($band_profile_id) || get_post_type( (int) $band_profile_id ) !== 'band_profile' ) {
        return;
    }
    $band_profile_id = (int) $band_profile_id;

    BandDataSyncManager::start_sync();
    delete_post_thumbnail( $band_profile_id );
    BandDataSyncManager::stop_sync();
}
add_action( 'deleted_post_meta', 'extrch_sync_link_page_deleted_image_meta_to_band_profile', 10, 4 );

