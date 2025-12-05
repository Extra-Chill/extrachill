<?php
/**
 * Handles frontend form submissions for Artist Platform features.
 */


/**
 * Processes the submission of the 'Create Artist Profile' form.
 *
 * Hooked to template_redirect to catch the submission before the page loads.
 */
function bp_handle_create_artist_profile_submission() {
    // Check if our form was submitted
    if ( ! isset( $_POST['bp_create_artist_profile_nonce'] ) ) {
        return; // Not our submission
    }

    $manage_page = get_page_by_path('manage-artist-profiles');
    $redirect_base_url = $manage_page ? get_permalink($manage_page) : home_url('/manage-artist-profiles/');

    // Verify the nonce
    if ( ! wp_verify_nonce( $_POST['bp_create_artist_profile_nonce'], 'bp_create_artist_profile_action' ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'nonce_failure', $redirect_base_url ) );
        exit;
    }

    // Check user permission
    if ( ! ec_can_create_artist_profiles( get_current_user_id() ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'permission_denied_create', $redirect_base_url ) );
        exit;
    }

    // Prepare save data using centralized system
    $save_data = ec_prepare_artist_profile_save_data( $_POST );
    
    // Title validation (required)
    if ( empty( $save_data['post_title'] ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'title_required', $redirect_base_url ) );
        exit;
    }

    // --- Check for Duplicate Title ---
    $existing_artists_query = new WP_Query(array(
        'post_type'      => 'artist_profile',
        'post_status'    => array('publish', 'pending', 'draft', 'future'),
        'posts_per_page' => 1,
        'title'          => $save_data['post_title'],
        'fields'         => 'ids'
    ));
    $existing_artist = $existing_artists_query->posts ? true : false;

    if ( $existing_artist ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'duplicate_title', $redirect_base_url ) );
        exit;
    }

    // Handle prefill avatar logic for featured image
    $files_data = $_FILES;
    if ( ! empty( $_FILES['featured_image']['tmp_name'] ) ) {
        // Featured image file provided
    } elseif ( isset( $_POST['prefill_user_avatar_id'] ) && ! empty( $_POST['prefill_user_avatar_id'] ) ) {
        $prefill_avatar_id = absint( $_POST['prefill_user_avatar_id'] );
        if ( $prefill_avatar_id && wp_attachment_is_image( $prefill_avatar_id ) ) {
            // Create a temporary placeholder to trigger the prefill logic in our file handler
            $files_data['prefill_avatar_id'] = $prefill_avatar_id;
        }
    }

    // --- Create the Post ---
    $artist_data = array(
        'post_type'   => 'artist_profile',
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
        'post_title'  => $save_data['post_title'],
        'post_content' => $save_data['post_content'] ?? ''
    );

    $new_artist_id = wp_insert_post( $artist_data, true );

    if ( is_wp_error( $new_artist_id ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'creation_failed', $redirect_base_url ) );
        exit;
    }

    // Use centralized save system for meta data and file uploads
    $result = ec_handle_artist_profile_save( $new_artist_id, $save_data, $files_data );
    
    if ( is_wp_error( $result ) ) {
        // Clean up the created post on save failure
        wp_delete_post( $new_artist_id, true );
        wp_safe_redirect( add_query_arg( 'bp_error', 'save_failed', $redirect_base_url ) );
        exit;
    }

    // --- Link Creator as Member --- 
    bp_add_artist_membership( get_current_user_id(), $new_artist_id );

    // --- Build action URLs for success notice ---
    $artist_profile_url = get_permalink( $new_artist_id );
    $manage_link_page = get_page_by_path( 'manage-link-page' );
    $link_page_url = $manage_link_page 
        ? add_query_arg( 'artist_id', $new_artist_id, get_permalink( $manage_link_page ) )
        : null;

    // Build actions array for notice
    $actions = array(
        array( 'label' => __( 'View Artist Profile', 'extrachill-artist-platform' ), 'url' => $artist_profile_url ),
    );
    if ( $link_page_url ) {
        $actions[] = array( 'label' => __( 'Manage Link Page', 'extrachill-artist-platform' ), 'url' => $link_page_url );
    }

    extrachill_set_notice(
        __( 'Artist profile created successfully!', 'extrachill-artist-platform' ),
        'success',
        array( 'actions' => $actions )
    );

    // --- Redirect to the manage artist profile page ---
    $manage_page = get_page_by_path('manage-artist-profiles');
    $manage_page_url = $manage_page ? get_permalink($manage_page) : home_url('/manage-artist-profiles/');
    wp_safe_redirect( add_query_arg( 'artist_id', $new_artist_id, $manage_page_url ) );
    exit;

}
add_action( 'template_redirect', 'bp_handle_create_artist_profile_submission' ); 

/**
 * Processes the submission of the 'Edit Artist Profile' form.
 *
 * Hooked to template_redirect to catch the submission before the page loads.
 */
function bp_handle_edit_artist_profile_submission() {
    // Check if our edit form was submitted
    if ( ! isset( $_POST['bp_edit_artist_profile_nonce'] ) ) {
        return; // Not our submission
    }

    // Get the Artist ID being edited (from hidden input)
    $artist_id = absint($_POST['artist_id']);

    // Determine the redirect URL for errors (back to the edit page)
    $manage_page = get_page_by_path('manage-artist-profiles');
    $redirect_base_url = $manage_page ? get_permalink($manage_page) : home_url('/manage-artist-profiles/'); // Base URL
    $error_redirect_url = $redirect_base_url; // Default if no artist_id
    if ( $artist_id > 0 ) {
         $error_redirect_url = add_query_arg( 'artist_id', $artist_id, $redirect_base_url );
    }

    // Verify the nonce
    if ( ! wp_verify_nonce( $_POST['bp_edit_artist_profile_nonce'], 'bp_edit_artist_profile_action' ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'nonce_failure', $error_redirect_url ) );
        exit;
    }

    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
         wp_safe_redirect( add_query_arg( 'bp_error', 'invalid_artist_id', $redirect_base_url ) ); // Redirect to base if ID is bad
         exit;
    }

    // Check user permission to edit *this specific post*
    if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'permission_denied_edit', $error_redirect_url ) );
        exit;
    }

    // Prepare save data using centralized system
    $save_data = ec_prepare_artist_profile_save_data( $_POST );
    
    // Title validation (required)
    if ( empty( $save_data['post_title'] ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'title_required', $error_redirect_url ) );
        exit;
    }

    // --- Check for Duplicate Title (only if title changed) ---
    $current_title = get_the_title( $artist_id );
    if ( $save_data['post_title'] !== $current_title ) {
        $existing_artists_query = new WP_Query(array(
            'post_type'      => 'artist_profile',
            'post_status'    => array('publish', 'pending', 'draft', 'future'),
            'posts_per_page' => 1,
            'title'          => $save_data['post_title'],
            'fields'         => 'ids'
        ));
        $existing_artist = $existing_artists_query->posts ? true : false;

        if ( $existing_artist ) {
            wp_safe_redirect( add_query_arg( 'bp_error', 'duplicate_title', $error_redirect_url ) );
            exit;
        }
    }

    // Use centralized save system for all updates
    $result = ec_handle_artist_profile_save( $artist_id, $save_data, $_FILES );
    
    if ( is_wp_error( $result ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'update_failed', $error_redirect_url ) );
        exit;
    }

    // Check if member management was performed
    $members_meta_changed_flag = isset( $save_data['remove_member_ids'] ) && ! empty( $save_data['remove_member_ids'] );

    // Set success notice
    extrachill_set_notice( __( 'Artist profile updated successfully!', 'extrachill-artist-platform' ), 'success' );

    // --- Redirect to the manage artist profile page --- 
    $manage_page = get_page_by_path('manage-artist-profiles');
    $manage_page_url = $manage_page ? get_permalink($manage_page) : home_url('/manage-artist-profiles/');
    
    $redirect_url = $artist_id > 0 
        ? add_query_arg( 'artist_id', $artist_id, $manage_page_url )
        : $manage_page_url;

    if ( $members_meta_changed_flag ) { 
        $redirect_url = add_query_arg( 'members_changed', '1', $redirect_url ); 
    }

    wp_safe_redirect( $redirect_url ); 
    exit;

}
add_action( 'template_redirect', 'bp_handle_edit_artist_profile_submission' ); 