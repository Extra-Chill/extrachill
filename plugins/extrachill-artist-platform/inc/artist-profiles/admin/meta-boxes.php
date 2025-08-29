<?php
/**
 * Admin Meta Boxes for Artist Profiles
 * 
 * Handles admin meta boxes for artist profile post type.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// --- Meta Box for Artist Settings ---

/**
 * Adds the meta box for artist profile settings.
 */
function bp_add_artist_settings_meta_box() {
    add_meta_box(
        'bp_artist_settings',                     // Unique ID
        __( 'Artist Forum Settings', 'extrachill-artist-platform' ), // Box title
        'bp_render_artist_settings_meta_box',   // Content callback function
        'artist_profile',                    // Post type
        'side',                          // Context (normal, side, advanced)
        'low'                           // Priority
    );
}
add_action( 'add_meta_boxes', 'bp_add_artist_settings_meta_box' );

/**
 * Renders the content of the artist settings meta box.
 *
 * @param WP_Post $post The current post object.
 */
function bp_render_artist_settings_meta_box( $post ) {
    // Add a nonce field for security
    wp_nonce_field( 'bp_save_artist_settings_meta', 'bp_artist_settings_nonce' );

    // Get the current value of the setting
    $allow_public = get_post_meta( $post->ID, '_allow_public_topic_creation', true );

    // Display the checkbox
    echo '<p>';
    echo '<label for="bp_allow_public_topic_creation">';
    echo '<input type="checkbox" id="bp_allow_public_topic_creation" name="bp_allow_public_topic_creation" value="1" ' . checked( $allow_public, '1', false ) . ' /> ';
    echo __( 'Allow non-members to create topics in this artist\'s forum?', 'extrachill-artist-platform' );
    echo '</label>';
    echo '</p>';
    echo '<p class="description">';
    echo __( 'If checked, any logged-in user with permission to create topics site-wide can post in this artist\'s forum. If unchecked, only linked artist members can create new topics.', 'extrachill-artist-platform' );
    echo '</p>';
}

/**
 * Saves the meta box data for artist settings.
 *
 * @param int $post_id The ID of the post being saved.
 */
function bp_save_artist_settings_meta( $post_id ) {
    // Check if nonce is set and valid.
    if ( ! isset( $_POST['bp_artist_settings_nonce'] ) || ! wp_verify_nonce( $_POST['bp_artist_settings_nonce'], 'bp_save_artist_settings_meta' ) ) {
        return;
    }

    // Check if the current user has permission to edit the post.
    // Use the specific capability for the CPT.
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Check if it's an autosave.
    if ( wp_is_post_autosave( $post_id ) ) {
        return;
    }

    // Check if it's a revision.
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Check if the checkbox was checked
    $new_value = isset( $_POST['bp_allow_public_topic_creation'] ) ? '1' : '0';

    // Update the post meta
    update_post_meta( $post_id, '_allow_public_topic_creation', $new_value );
}
/**
 * Centralized wrapper for artist settings meta when using the centralized save system.
 *
 * @param int $artist_id The ID of the artist profile being saved.
 */
function bp_save_artist_settings_meta_centralized( $artist_id ) {
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return;
    }

    $artist_post = get_post( $artist_id );
    if ( ! $artist_post ) {
        return;
    }

    // Call the existing meta save function with the post object and update flag
    bp_save_artist_settings_meta( $artist_id, $artist_post, true );
}

// Hook into centralized save system only - no legacy save_post hook needed
add_action( 'ec_artist_profile_save', 'bp_save_artist_settings_meta_centralized', 10, 1 );