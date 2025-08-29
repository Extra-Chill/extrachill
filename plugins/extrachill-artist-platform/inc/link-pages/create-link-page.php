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
 * Hooks into 'publish_artist_profile'.
 *
 * @param int     $post_id The ID of the artist_profile post being published.
 * @param WP_Post $post    The artist_profile post object.
 */
function extrch_create_link_page_for_artist_profile( $post_id, $post ) {
    error_log('[Link Page Creation] Function called for post ID: ' . $post_id . ', post type: ' . ($post ? $post->post_type : 'NULL'));
    
    // Only run on actual post publish, not on auto-save or revisions.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        error_log('[Link Page Creation] Skipping - DOING_AUTOSAVE');
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        error_log('[Link Page Creation] Skipping - post revision');
        return;
    }
    // Check post type is 'artist_profile'.
    if ( 'artist_profile' !== $post->post_type ) {
        error_log('[Link Page Creation] Skipping - wrong post type: ' . $post->post_type);
        return;
    }
    
    error_log('[Link Page Creation] Proceeding with link page creation for artist profile: ' . $post_id);

    // Check if a link page already exists for this artist profile.
    $existing_link_page_id = get_post_meta( $post_id, '_extrch_link_page_id', true );
    if ( $existing_link_page_id && get_post_type( $existing_link_page_id ) === 'artist_link_page' ) {
        // If a link page exists but its status is not 'publish', try publishing it.
        // This handles cases where a draft artist profile was saved (creating a draft link page),
        // and now the artist profile is being published.
        if ( get_post_status( $existing_link_page_id ) !== 'publish' ) {
             wp_publish_post( $existing_link_page_id );
             // Re-fetch the link page ID to ensure it's valid after publish
             $existing_link_page_id = get_post_meta( $post_id, '_extrch_link_page_id', true );
             if ( $existing_link_page_id && get_post_status( $existing_link_page_id ) === 'publish' ) {
                // Also update the title and slug in case they were auto-draft values
                wp_update_post( array(
                    'ID' => $existing_link_page_id,
                    'post_title' => $post->post_title,
                    'post_name'  => $post->post_name,
                    'post_status' => 'publish', // Ensure it's published
                ) );
             }
        }
         return; // Link page exists and is now hopefully published.
    }

    // --- Create the new link page --- 

    // Before creating the link page, ensure we have the latest artist profile data.
    // This is important because the $post object passed to the hook might reflect a state before final title/slug save in admin.
    $latest_artist_profile_post = get_post( $post_id );
    if ( ! $latest_artist_profile_post ) {
        return;
    }

    $link_page_title = $latest_artist_profile_post->post_title; 
    $artist_profile_slug = $latest_artist_profile_post->post_name; // Get the slug from the latest artist_profile post

    // Ensure title and slug are not empty before creating
    if ( empty( $link_page_title ) || empty( $artist_profile_slug ) ) {
        return;
    }

    $new_link_page_args = array(
        'post_type'   => 'artist_link_page',
        'post_title'  => $link_page_title,
        'post_name'   => $artist_profile_slug, // Explicitly set the slug
        'post_status' => 'publish', // Create the link page as published
        'meta_input'  => array(
            '_associated_artist_profile_id' => $post_id,
        ),
    );

    $new_link_page_id = wp_insert_post( $new_link_page_args );

    if ( is_wp_error( $new_link_page_id ) ) {
        // Handle error, e.g., log it.
        return;
    }

    if ( $new_link_page_id ) {
        error_log('[Link Page Creation] Successfully created link page ID: ' . $new_link_page_id);
        
        // Update the artist_profile post with the new link page ID.
        $meta_update_result = update_post_meta( $post_id, '_extrch_link_page_id', $new_link_page_id );
        error_log('[Link Page Creation] Updated artist profile meta _extrch_link_page_id: ' . ($meta_update_result ? 'SUCCESS' : 'FAILED'));

        // Create default links using the centralized filter system
        $link_defaults = ec_get_link_page_defaults_for( 'links' );
        if ( $link_defaults['create_default_section'] ) {
            $artist_profile_url = get_permalink( $latest_artist_profile_post );
            $artist_title = get_the_title( $latest_artist_profile_post );
            $artist_slug = get_post_field( 'post_name', $latest_artist_profile_post );
            
            // Build link text from template
            $link_text = str_replace( '%artist_name%', $artist_title, $link_defaults['link_text_template'] );
            
            $default_links = array(
                array(
                    'section_title' => $link_defaults['section_title'],
                    'links' => array(
                        array(
                            'link_url'       => esc_url( $artist_profile_url ),
                            'link_text'      => esc_html( $link_text ),
                            'link_is_active' => $link_defaults['link_is_active'],
                            'expires_at'     => '',
                        ),
                    ),
                ),
            );
            update_post_meta( $new_link_page_id, '_link_page_links', $default_links );
        }

        // Apply default styles using the centralized filter system
        $default_styles_array = ec_get_link_page_defaults_for( 'styles' );
        update_post_meta( $new_link_page_id, '_link_page_custom_css_vars', $default_styles_array );
        
        error_log('[Link Page Creation] Link page setup completed successfully for artist ID: ' . $post_id);
    } else {
        error_log('[Link Page Creation] FAILED to create link page for artist ID: ' . $post_id);

        // Also save individual meta fields for background type and color for easier initial JS hydration
        // and consistency, as the JS for background controls might look for these specific meta.
        // Note: This code runs in the FAILED case, so we don't have access to default styles
        // Set failed creation flag for artist profile
        // update_post_meta( $new_link_page_id, '_link_page_background_type', 'color' );
        // if (isset($default_styles_array['--link-page-background-color'])) {
        //     update_post_meta( $new_link_page_id, '_link_page_background_color', $default_styles_array['--link-page-background-color'] );
        // }
        // Other defaults like gradient colors could be set here if the default type was gradient.
        // For now, solid color is the default.
    }
}
add_action( 'publish_artist_profile', 'extrch_create_link_page_for_artist_profile', 10, 2 );




/**
 * Clears the associated artist_profile's meta field when a artist_link_page is deleted.
 * Also attempts to clear relevant post cache to prevent stale data issues after deletion/trashing.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function extrch_clear_artist_profile_link_page_id_on_delete( $post_id ) {
    if ( get_post_type( $post_id ) === 'artist_link_page' ) {
        // Get the associated artist_profile_id from the link page's meta
        $associated_artist_profile_id = ec_get_artist_for_link_page( $post_id );

        if ( $associated_artist_profile_id ) {
            // Get the current link page ID stored on the artist profile
            $current_link_page_id_on_artist = get_post_meta( $associated_artist_profile_id, '_extrch_link_page_id', true );

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
add_action( 'before_delete_post', 'extrch_clear_artist_profile_link_page_id_on_delete', 10, 1 );
// Note: 'deleted_post' could also be used, but 'before_delete_post' ensures meta is available.

?>