<?php
/**
 * Creation Filter Functions for ExtraChill Artist Platform
 * 
 * Centralized creation logic using WordPress filters for extensibility.
 * Handles creation vs editing mode distinction and prevents duplicate creation.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a link page for an artist profile (centralized creation logic)
 * 
 * @param int  $artist_id The artist profile ID to create a link page for
 * @param bool $force     Force creation even if link page already exists
 * @return int|WP_Error   Link page ID on success, WP_Error on failure
 */
function ec_create_link_page( $artist_id, $force = false ) {
    // Validate artist profile
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID for link page creation' );
    }

    // Check if link page already exists (unless forced)
    $existing_link_page_id = apply_filters( 'ec_get_link_page_id', 0, $artist_id );
    if ( $existing_link_page_id && ! $force ) {
        // Link page already exists - return existing ID
        if ( get_post_type( $existing_link_page_id ) === 'artist_link_page' ) {
            return $existing_link_page_id;
        }
    }

    // Get latest artist profile data
    $artist_post = get_post( $artist_id );
    if ( ! $artist_post ) {
        return new WP_Error( 'artist_not_found', 'Artist profile post not found' );
    }

    // Prepare link page data
    $link_page_title = $artist_post->post_title;
    $artist_profile_slug = $artist_post->post_name;

    // Ensure title and slug are not empty
    if ( empty( $link_page_title ) || empty( $artist_profile_slug ) ) {
        return new WP_Error( 'incomplete_data', 'Artist profile must have title and slug for link page creation' );
    }

    // Create link page
    $link_page_args = array(
        'post_type'   => 'artist_link_page',
        'post_title'  => $link_page_title,
        'post_name'   => $artist_profile_slug,
        'post_status' => 'publish',
        'meta_input'  => array(
            '_associated_artist_profile_id' => $artist_id,
        ),
    );


    // Create the link page
    $new_link_page_id = wp_insert_post( $link_page_args );

    if ( is_wp_error( $new_link_page_id ) ) {
        return $new_link_page_id;
    }

    if ( ! $new_link_page_id ) {
        return new WP_Error( 'creation_failed', 'Failed to create link page' );
    }

    // Update artist profile with link page ID
    update_post_meta( $artist_id, '_extrch_link_page_id', $new_link_page_id );

    // Apply default settings
    ec_setup_default_link_page_data( $new_link_page_id, $artist_id );

    /**
     * Fires after a link page has been created successfully.
     *
     * This action hook allows other plugins and theme functions to perform
     * additional setup operations after link page creation. The link page
     * and associated artist profile are both available and properly linked.
     *
     * @since 1.0.0
     *
     * @param int $new_link_page_id The ID of the newly created link page.
     * @param int $artist_id        The ID of the associated artist profile.
     * @param bool $force           Whether creation was forced.
     */
    do_action( 'ec_link_page_created', $new_link_page_id, $artist_id, $force );

    return $new_link_page_id;
}

/**
 * Set up default data for a newly created link page
 *
 * @param int $link_page_id The link page ID
 * @param int $artist_id    The associated artist profile ID
 */
function ec_setup_default_link_page_data( $link_page_id, $artist_id ) {
    // Apply default styles using centralized filter system
    $default_styles = ec_get_link_page_defaults_for( 'styles' );
    if ( ! empty( $default_styles ) ) {
        update_post_meta( $link_page_id, '_link_page_custom_css_vars', $default_styles );
    }

}

/**
 * Check if a link page should be created for an artist profile
 *
 * This function provides a clean way to determine creation eligibility
 * without actually performing the creation.
 *
 * @param int $artist_id The artist profile ID
 * @return bool|WP_Error True if creation should proceed, WP_Error if not eligible
 */
function ec_should_create_link_page( $artist_id ) {
    // Validate artist profile
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return new WP_Error( 'invalid_artist_profile', 'Invalid artist profile ID' );
    }

    // Check if link page already exists
    $existing_link_page_id = apply_filters( 'ec_get_link_page_id', 0, $artist_id );
    if ( $existing_link_page_id && get_post_type( $existing_link_page_id ) === 'artist_link_page' ) {
        return new WP_Error( 'already_exists', 'Link page already exists for this artist profile' );
    }

    // Check artist profile has required data
    $artist_post = get_post( $artist_id );
    if ( ! $artist_post ) {
        return new WP_Error( 'artist_not_found', 'Artist profile post not found' );
    }

    if ( empty( $artist_post->post_title ) || empty( $artist_post->post_name ) ) {
        return new WP_Error( 'incomplete_data', 'Artist profile must have title and slug' );
    }

    /**
     * Filters whether a link page should be created for an artist profile.
     *
     * This filter allows plugins to add additional conditions for link page creation.
     * Return WP_Error to prevent creation with a specific reason.
     *
     * @since 1.0.0
     *
     * @param bool|WP_Error $should_create True to allow creation, WP_Error to prevent.
     * @param int           $artist_id     The artist profile ID being evaluated.
     * @param WP_Post       $artist_post   The artist profile post object.
     */
    return apply_filters( 'ec_should_create_link_page', true, $artist_id, $artist_post );
}