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
 * Creates a link page for a given band profile if one doesn't already exist.
 * Hooks into 'save_post_band_profile'.
 *
 * @param int     $post_id The ID of the band_profile post being saved.
 * @param WP_Post $post    The band_profile post object.
 * @param bool    $update  Whether this is an existing post being updated or not.
 */
function extrch_create_link_page_for_band_profile( $post_id, $post, $update ) {
    // Only run on actual post save, not on auto-save or revisions.
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
        return; // Link page already exists.
    }

    // Create the new link page.
    $link_page_title = $post->post_title . ' - Link Page'; // Or a more generic title.
    $new_link_page_args = array(
        'post_type'   => 'band_link_page',
        'post_title'  => $link_page_title,
        'post_status' => 'publish', // Or 'draft' if preferred initially.
        'meta_input'  => array(
            '_associated_band_profile_id' => $post_id,
        ),
    );

    $new_link_page_id = wp_insert_post( $new_link_page_args );

    if ( is_wp_error( $new_link_page_id ) ) {
        // Handle error, e.g., log it.
        error_log( 'Error creating link page for band profile ' . $post_id . ': ' . $new_link_page_id->get_error_message() );
        return;
    }

    if ( $new_link_page_id ) {
        // Update the band_profile post with the new link page ID.
        update_post_meta( $post_id, '_extrch_link_page_id', $new_link_page_id );

        // Add default link to the band profile itself.
        $band_profile_url = get_permalink( $post_id );
        $band_title = get_the_title( $post_id );
        $default_links = array( // This is an array of sections
            array( // The first (and only, by default) section
                'section_title' => '', // Empty title for the default section
                'links' => array( // Array of links within this section
                    array( // The actual link item
                        'link_url'       => esc_url( $band_profile_url ),
                        'link_text'      => esc_html( $band_title ) . ' Profile',
                        'link_is_active' => true,
                        'expires_at'     => '', // Ensure expires_at is present even if empty
                    ),
                ),
            ),
        );
        update_post_meta( $new_link_page_id, '_link_page_links', $default_links );

        // Apply default styles.
        $default_styles_array = extrch_get_default_link_page_styles();
        update_post_meta( $new_link_page_id, '_link_page_custom_css_vars', wp_json_encode( $default_styles_array ) );

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
add_action( 'save_post_band_profile', 'extrch_create_link_page_for_band_profile', 10, 3 );

/**
 * Gets the default styles for a new link page.
 * These are based on the light mode variables in css/root.css.
 * Dark mode preference detection is typically client-side.
 *
 * @return array Default CSS variables for the link page.
 */
function extrch_get_default_link_page_styles() {
    // Default to a dark theme based on css/root.css dark mode values
    $default_page_bg_color     = '#1a1a1a'; // --background-color from dark mode
    $default_page_text_color   = '#e5e5e5'; // --text-color from dark mode
    $default_button_bg_color   = '#0b5394'; // --button-bg (blue, contrasts well with dark bg)
    $default_button_text_color = '#ffffff'; // Ensure button text is light
    $default_button_hover_bg   = '#083b6c'; // --button-hover-bg

    $default_font_family_value = 'WilcoLoftSans'; // Default font identifier

    return array(
        // Page specific styles
        '--link-page-background-color'    => $default_page_bg_color, // For solid background type
        '--link-page-text-color'          => $default_page_text_color,   // General page text

        // Button specific styles
        '--link-page-button-color'        => $default_button_bg_color,   // Button background
        '--link-page-link-text-color'     => $default_button_text_color, // Text on buttons
        '--link-page-hover-color'         => $default_button_hover_bg,   // Button hover background

        // Other non-color defaults
        '--link-page-profile-img-radius'  => '12px', // Default to rectangle
        '--link-page-title-font-family'   => $default_font_family_value,
        // Font size will default via CSS or JS if not set here
    );
}


/**
 * Retrieves or creates the default site band profile and its associated link page.
 * The default band profile slug is 'extrachill'.
 *
 * @param bool $create_if_missing Whether to create the items if they don't exist. Defaults to true.
 * @return array|null An array containing 'band_id' and 'link_page_id', or null if not found and not creating.
 */
function extrch_get_or_create_default_admin_link_page( $create_if_missing = true ) {
    $default_band_slug = 'extrachill';
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
            error_log( 'Error creating default band profile: ' . ( is_wp_error( $new_band_id ) ? $new_band_id->get_error_message() : 'Unknown error' ) );
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
             error_log( 'Error retrieving default band profile object for ID: ' . $band_id );
            return null;
        }
        // Create the link page if it doesn't exist AND $create_if_missing is true.
        extrch_create_link_page_for_band_profile( $band_id, $band_profile_post_obj, true ); // true for $update as band_profile exists
        $link_page_id = get_post_meta( $band_id, '_extrch_link_page_id', true );

        if ( ! $link_page_id || !get_post_status($link_page_id) ) { // Check if it was actually created
            error_log( 'Error creating or retrieving link page for default band profile ID: ' . $band_id );
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
    }
}
add_action( 'before_delete_post', 'extrch_clear_band_profile_link_page_id_on_delete', 10, 1 );
// Note: 'deleted_post' could also be used, but 'before_delete_post' ensures meta is available.

?>