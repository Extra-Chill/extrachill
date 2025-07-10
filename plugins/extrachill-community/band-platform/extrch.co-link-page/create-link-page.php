<?php
/**
 * Centralized Link Page Creation Logic for extrch.co / extrachill.link
 *
 * Handles automatic creation of link pages for new band profiles
 * and the creation/management of the default site link page.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates a link page for a given band profile when it is published for the first time.
 * Hooks into 'publish_band_profile'.
 *
 * @param int     $post_id The ID of the band_profile post being published.
 * @param WP_Post $post    The band_profile post object.
 */
function extrch_create_link_page_for_band_profile( $post_id, $post ) {
    // Only run on actual post publish, not on auto-save or revisions.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    // Check post type is 'band_profile'.
    if ( 'band_profile' !== $post->post_type ) {
        return;
    }

    // Check if a link page already exists for this band profile.
    $existing_link_page_id = get_post_meta( $post_id, '_extrch_link_page_id', true );
    if ( $existing_link_page_id && get_post_type( $existing_link_page_id ) === 'band_link_page' ) {
        // If a link page exists but its status is not 'publish', try publishing it.
        // This handles cases where a draft band profile was saved (creating a draft link page),
        // and now the band profile is being published.
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

    // Before creating the link page, ensure we have the latest band profile data.
    // This is important because the $post object passed to the hook might reflect a state before final title/slug save in admin.
    $latest_band_profile_post = get_post( $post_id );
    if ( ! $latest_band_profile_post ) {
        return;
    }

    $link_page_title = $latest_band_profile_post->post_title; 
    $band_profile_slug = $latest_band_profile_post->post_name; // Get the slug from the latest band_profile post

    // Ensure title and slug are not empty before creating
    if ( empty( $link_page_title ) || empty( $band_profile_slug ) ) {
        return;
    }

    $new_link_page_args = array(
        'post_type'   => 'band_link_page',
        'post_title'  => $link_page_title,
        'post_name'   => $band_profile_slug, // Explicitly set the slug
        'post_status' => 'publish', // Create the link page as published
        'meta_input'  => array(
            '_associated_band_profile_id' => $post_id,
        ),
    );

    $new_link_page_id = wp_insert_post( $new_link_page_args );

    if ( is_wp_error( $new_link_page_id ) ) {
        // Handle error, e.g., log it.
        return;
    }

    if ( $new_link_page_id ) {
        // Update the band_profile post with the new link page ID.
        update_post_meta( $post_id, '_extrch_link_page_id', $new_link_page_id );

        // Add default link to the band profile itself.
        // Use the $latest_band_profile_post object for getting the permalink and title.
        $band_profile_url = get_permalink( $latest_band_profile_post );
        $band_title = get_the_title( $latest_band_profile_post );

        $default_links = array( // This is an array of sections
            array( // The first (and only, by default) section
                'section_title' => '', // Empty title for the default section
                'links' => array( // Array of links within this section
                    array( // The actual link item
                        'link_url'       => esc_url( $band_profile_url ),
                        'link_text'      => esc_html( $band_title ) . ' Forum',
                        'link_is_active' => true,
                        'expires_at'     => '', // Ensure expires_at is present even if empty
                    ),
                ),
            ),
        );
        update_post_meta( $new_link_page_id, '_link_page_links', $default_links );

        // Apply default styles.
        $default_styles_array = extrch_get_default_link_page_styles();
        update_post_meta( $new_link_page_id, '_link_page_custom_css_vars', $default_styles_array );

        // Also save individual meta fields for background type and color for easier initial JS hydration
        // and consistency, as the JS for background controls might look for these specific meta.
        update_post_meta( $new_link_page_id, '_link_page_background_type', 'color' );
        if (isset($default_styles_array['--link-page-background-color'])) {
            update_post_meta( $new_link_page_id, '_link_page_background_color', $default_styles_array['--link-page-background-color'] );
        }
        // Other defaults like gradient colors could be set here if the default type was gradient.
        // For now, solid color is the default.
    }
}
add_action( 'publish_band_profile', 'extrch_create_link_page_for_band_profile', 10, 2 );

/**
 * Gets the default styles for a new link page.
 * These are based on the light mode variables in css/root.css.
 * Dark mode preference detection is typically client-side.
 *
 * @return array Default CSS variables for the link page.
 */
function extrch_get_default_link_page_styles() {
    // Default color palette (dark theme inspired)
    $default_page_bg_color           = '#121212'; // Matches extrch-links.css :root
    $default_card_bg_color           = 'rgba(0, 0, 0, 0.4)'; // Matches extrch-links.css :root
    $default_page_text_color         = '#e5e5e5'; // Matches extrch-links.css :root
    $default_link_text_color         = '#ffffff'; // Matches extrch-links.css :root (for button/link text)
    $default_button_bg_color         = '#0b5394'; // Matches extrch-links.css :root
    $default_button_border_color     = '#0b5394'; // Matches extrch-links.css :root
    $default_button_hover_bg_color   = '#53940b'; // Matches extrch-links.css :root
    $default_button_hover_text_color = '#ffffff'; // Matches extrch-links.css :root
    $default_muted_text_color        = '#aaa';    // Matches extrch-links.css :root
    $default_overlay_color           = 'rgba(0, 0, 0, 0.5)'; // Renamed for clarity, matches CSS var --link-page-overlay-color

    // Default font identifiers and sizes
    $default_title_font_family = 'WilcoLoftSans'; 
    $default_title_font_size   = '2.1em'; // Matches extrch-links.css :root
    $default_body_font_family  = 'Helvetica'; 
    $default_body_font_size    = '1em';   

    // Default Profile Image settings
    $default_profile_img_size        = '30%'; 
    $default_profile_img_shape       = 'circle'; 
    $default_profile_img_aspect_ratio = '1/1'; 
    $default_profile_img_border_radius= '50%';

    // Default Button settings
    $default_button_radius = '8px';

    return array(
        // Background & Page Styles
                    '--link-page-background-color'              => $default_page_bg_color,
        '--link-page-background-type'               => 'color', 
        '--link-page-background-gradient-start'       => $default_button_bg_color, 
        '--link-page-background-gradient-end'         => $default_button_hover_bg_color, 
        '--link-page-background-gradient-direction'   => 'to right',
        '--link-page-card-bg-color'                 => $default_card_bg_color,
        
        // Text & Overlay Styles
        '--link-page-text-color'                    => $default_page_text_color,
        '--link-page-muted-text-color'              => $default_muted_text_color,
        '--link-page-overlay-color'                 => $default_overlay_color, 
        'overlay'                                   => '1', 

        // Button & Link Styles
        '--link-page-button-bg-color'               => $default_button_bg_color,
        '--link-page-link-text-color'               => $default_link_text_color, 
        '--link-page-button-hover-bg-color'         => $default_button_hover_bg_color,
        '--link-page-button-hover-text-color'       => $default_button_hover_text_color,
        '--link-page-button-radius'                 => $default_button_radius,
        '--link-page-button-border-width'           => '0px', 
        '--link-page-button-border-color'           => $default_button_border_color, 

        // Font Styles
        '--link-page-title-font-family'             => $default_title_font_family,
        '--link-page-title-font-size'               => $default_title_font_size,
        '--link-page-body-font-family'              => $default_body_font_family,
        '--link-page-body-font-size'                => $default_body_font_size,

        // Profile Image Styles
        '--link-page-profile-img-size'              => $default_profile_img_size,
        '--link-page-profile-img-border-radius'     => $default_profile_img_border_radius,
        '--link-page-profile-img-aspect-ratio'      => $default_profile_img_aspect_ratio,
        '_link_page_profile_img_shape'              => $default_profile_img_shape, 
    );
}


/**
 * Retrieves or creates the default site band profile and its associated link page.
 * The default band profile slug is 'extra-chill'.
 *
 * @param bool $create_if_missing Whether to create the items if they don't exist. Defaults to true.
 * @return array|null An array containing 'band_id' and 'link_page_id', or null if not found and not creating.
 */
function extrch_get_or_create_default_admin_link_page( $create_if_missing = true ) {
    $default_band_slug = 'extra-chill'; // Ensure this matches your actual default band profile slug
    $default_band_profile = get_page_by_path( $default_band_slug, OBJECT, 'band_profile' );
    $band_id = null;
    $link_page_id = null;

    if ( ! $default_band_profile ) {
        if ( ! $create_if_missing ) {
            return null; // Don't create, just report as not found
        }
        // Create the default band profile if it doesn't exist AND $create_if_missing is true.
        $new_band_id = wp_insert_post( array(
            'post_type'   => 'band_profile',
            'post_title'  => 'Extra Chill',
            'post_name'   => $default_band_slug,
            'post_status' => 'publish',
        ) );

        if ( is_wp_error( $new_band_id ) || ! $new_band_id ) {
            return null;
        }
        $band_id = $new_band_id;
        $admin_user_id = get_current_user_id();
        if ( $admin_user_id && function_exists('bp_add_band_membership') ) {
            bp_add_band_membership( $admin_user_id, $band_id );
        }
    } else {
        $band_id = $default_band_profile->ID;
    }

    // Now check for or create the link page for this band_id.
    $link_page_id = get_post_meta( $band_id, '_extrch_link_page_id', true );
    $link_page_post_exists = $link_page_id ? (get_post_status($link_page_id) && get_post_type($link_page_id) === 'band_link_page') : false;

    if ( ! $link_page_post_exists ) {
        if ( ! $create_if_missing ) {
            // If we are not creating, and it doesn't exist (or meta points to invalid post), return null for link_page_id
            return array('band_id' => $band_id, 'link_page_id' => null);
        }

        $band_profile_post_obj = get_post( $band_id );
        if ( ! $band_profile_post_obj ) {
            return null;
        }
        // Create the link page if it doesn't exist AND $create_if_missing is true.
        extrch_create_link_page_for_band_profile( $band_id, $band_profile_post_obj );
        $link_page_id = get_post_meta( $band_id, '_extrch_link_page_id', true );

        if ( ! $link_page_id || !get_post_status($link_page_id) ) { // Check if it was actually created
            return array('band_id' => $band_id, 'link_page_id' => null);
        }
    }
    
    // Ensure the default link page has a specific title if it exists and needs one
    if ($link_page_id && get_post_status($link_page_id) && get_the_title($link_page_id) !== 'Extra Chill Landing Page') {
        wp_update_post(array(
            'ID' => $link_page_id,
            'post_title' => 'Extra Chill Landing Page'
        ));
    }

    return array(
        'band_id'      => $band_id,
        'link_page_id' => ($link_page_id && get_post_status($link_page_id)) ? $link_page_id : null,
    );
}

/**
 * Clears the associated band_profile's meta field when a band_link_page is deleted.
 * Also attempts to clear relevant post cache to prevent stale data issues after deletion/trashing.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function extrch_clear_band_profile_link_page_id_on_delete( $post_id ) {
    if ( get_post_type( $post_id ) === 'band_link_page' ) {
        // Get the associated band_profile_id from the link page's meta
        $associated_band_profile_id = get_post_meta( $post_id, '_associated_band_profile_id', true );

        if ( $associated_band_profile_id ) {
            // Get the current link page ID stored on the band profile
            $current_link_page_id_on_band = get_post_meta( $associated_band_profile_id, '_extrch_link_page_id', true );

            // Only delete the meta if it matches the ID of the link page being deleted
            if ( (int) $current_link_page_id_on_band === (int) $post_id ) {
                delete_post_meta( $associated_band_profile_id, '_extrch_link_page_id' );
            }
        }

        // Attempt to clear the post cache for the deleted link page.
        // This is important as get_posts can sometimes return cached results.
        wp_cache_delete( $post_id, 'posts' );
        wp_cache_delete( $post_id, 'post_meta' );
        // If using persistent object cache, you might need more specific cache clearing depending on the implementation.
    }
}
add_action( 'before_delete_post', 'extrch_clear_band_profile_link_page_id_on_delete', 10, 1 );
// Note: 'deleted_post' could also be used, but 'before_delete_post' ensures meta is available.

?>