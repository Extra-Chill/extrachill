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
function ec_add_artist_settings_meta_box() {
    add_meta_box(
        'ec_artist_settings',                     // Unique ID
        __( 'Artist Forum Settings', 'extrachill-artist-platform' ), // Box title
        'ec_render_artist_settings_meta_box',   // Content callback function
        'artist_profile',                    // Post type
        'side',                          // Context (normal, side, advanced)
        'low'                           // Priority
    );
}
add_action( 'add_meta_boxes', 'ec_add_artist_settings_meta_box' );

/**
 * Renders the content of the artist settings meta box.
 *
 * @param WP_Post $post The current post object.
 */
function ec_render_artist_settings_meta_box( $post ) {
    // Add a nonce field for security
    wp_nonce_field( 'ec_save_artist_settings_meta', 'ec_artist_settings_nonce' );

    // Get the current value of the setting
    $allow_public = get_post_meta( $post->ID, '_allow_public_topic_creation', true );

    // Display the checkbox
    echo '<p>';
    echo '<label for="ec_allow_public_topic_creation">';
    echo '<input type="checkbox" id="ec_allow_public_topic_creation" name="ec_allow_public_topic_creation" value="1" ' . checked( $allow_public, '1', false ) . ' /> ';
    echo __( 'Allow non-members to create topics in this artist\'s forum?', 'extrachill-artist-platform' );
    echo '</label>';
    echo '</p>';
    echo '<p class="description">';
    echo __( 'If checked, any logged-in user with permission to create topics site-wide can post in this artist\'s forum. If unchecked, only linked artist members can create new topics.', 'extrachill-artist-platform' );
    echo '</p>';
}

/**
 * Persist the forum settings meta from the submitted request payload.
 *
 * Shared writer for both the wp-admin metabox path and the centralized
 * front-end save path. Authorization (nonce verification and/or capability
 * checks) is the responsibility of each caller — by the time this runs the
 * request has already been authorized.
 *
 * @param int $post_id The ID of the artist profile being saved.
 */
function ec_write_artist_settings_meta( $post_id ) {
    // Checkbox: present in $_POST means checked, absent means unchecked.
    $new_value = isset( $_POST['ec_allow_public_topic_creation'] ) ? '1' : '0';

    update_post_meta( $post_id, '_allow_public_topic_creation', $new_value );
}

/**
 * Saves the meta box data for artist settings (wp-admin metabox path).
 *
 * This path carries its own nonce (rendered by ec_render_artist_settings_meta_box)
 * and is authorized by verifying that nonce plus the edit_post capability.
 *
 * @param int $post_id The ID of the post being saved.
 */
function ec_save_artist_settings_meta( $post_id ) {
    // Check if nonce is set and valid.
    if ( ! isset( $_POST['ec_artist_settings_nonce'] ) || ! wp_verify_nonce( $_POST['ec_artist_settings_nonce'], 'ec_save_artist_settings_meta' ) ) {
        return;
    }

    // Only act on artist profiles.
    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
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

    ec_write_artist_settings_meta( $post_id );
}
// Wire the metabox handler to the classic wp-admin save_post flow.
add_action( 'save_post_artist_profile', 'ec_save_artist_settings_meta', 10, 1 );

/**
 * Centralized save handler for artist settings meta.
 *
 * Runs on the front-end centralized save flow (ec_artist_profile_save). That
 * flow has already authorized the request, so there is no metabox nonce here;
 * instead we enforce the manage-artist capability before writing. The value is
 * read directly from the submitted payload (same field name the metabox uses).
 *
 * @param int $artist_id The ID of the artist profile being saved.
 */
function ec_save_artist_settings_meta_centralized( $artist_id ) {
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return;
    }

    // Enforce capability — the centralized save already validated its own nonce,
    // but the writer itself must still confirm the current user can manage this artist.
    $can_manage = function_exists( 'ec_can_manage_artist' )
        ? ec_can_manage_artist( get_current_user_id(), $artist_id )
        : current_user_can( 'edit_post', $artist_id );

    if ( ! $can_manage ) {
        return;
    }

    ec_write_artist_settings_meta( $artist_id );
}

// Hook into the centralized front-end save system.
add_action( 'ec_artist_profile_save', 'ec_save_artist_settings_meta_centralized', 10, 1 );