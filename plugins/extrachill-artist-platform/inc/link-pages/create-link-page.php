<?php
/**
 * Centralized Link Page Creation Logic for extrch.co / extrachill.link
 *
 * Handles automatic creation of link pages for new artist profiles
 * and the creation/management of the default site link page.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates a link page for a given artist profile when it is published for the first time.
 * Uses the centralized creation filter system.
 *
 * @param int     $post_id The ID of the artist_profile post being published.
 * @param WP_Post $post    The artist_profile post object.
 */
function extrachill_artist_create_link_page_for_artist_profile( $post_id, $post ) {
    // Only run on actual post publish, not on auto-save or revisions
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( 'artist_profile' !== $post->post_type ) {
        return;
    }
    
    // Skip if we're in sync mode to prevent duplicate creation
    if ( class_exists( 'ArtistDataSyncManager' ) && ArtistDataSyncManager::is_syncing() ) {
        return;
    }
    
    // Use centralized creation filter system
    $result = ec_create_link_page( $post_id );
    
    // Creation filter handles everything - no defensive programming needed
}
// Auto-creation of link pages is now handled by the centralized join flow system
// Only join flow registrations will automatically create link pages
// add_action( 'publish_artist_profile', 'extrachill_artist_create_link_page_for_artist_profile', 10, 2 );




/**
 * Clears the associated artist_profile's meta field when a artist_link_page is deleted.
 * Also attempts to clear relevant post cache to prevent stale data issues after deletion/trashing.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function extrachill_artist_clear_artist_profile_link_page_id_on_delete( $post_id ) {
    if ( get_post_type( $post_id ) === 'artist_link_page' ) {
        // Get the associated artist_profile_id from the link page's meta
        $associated_artist_profile_id = apply_filters('ec_get_artist_id', $post_id);

        if ( $associated_artist_profile_id ) {
            // Get the current link page ID stored on the artist profile
            $current_link_page_id_on_artist = apply_filters('ec_get_link_page_id', $associated_artist_profile_id);

            // Only delete the meta if it matches the ID of the link page being deleted
            if ( (int) $current_link_page_id_on_artist === (int) $post_id ) {
                delete_post_meta( $associated_artist_profile_id, '_extrch_link_page_id' );
            }
        }

        // Attempt to clear the post cache for the deleted link page.
        // This is important as get_posts can sometimes return cached results.
        wp_cache_delete( $post_id, 'posts' );
        wp_cache_delete( $post_id, 'post_meta' );
        // If using persistent object cache, you might need more specific cache clearing depending on the implementation.
    }
}
add_action( 'before_delete_post', 'extrachill_artist_clear_artist_profile_link_page_id_on_delete', 10, 1 );
// Note: 'deleted_post' could also be used, but 'before_delete_post' ensures meta is available.

?>