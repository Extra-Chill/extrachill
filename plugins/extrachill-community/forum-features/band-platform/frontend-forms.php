<?php
/**
 * Handles frontend form submissions for Band Platform features.
 */

// Helper function to get the manage band profiles page URL
function bp_get_manage_band_page_url() {
    // Use the slug provided by the user
    return home_url( '/manage-band-profiles/' ); 
}

/**
 * Processes the submission of the 'Create Band Profile' form.
 *
 * Hooked to template_redirect to catch the submission before the page loads.
 */
function bp_handle_create_band_profile_submission() {
    // Check if our form was submitted
    if ( ! isset( $_POST['bp_create_band_profile_nonce'] ) ) {
        return; // Not our submission
    }

    $redirect_base_url = bp_get_manage_band_page_url();

    // Verify the nonce
    if ( ! wp_verify_nonce( $_POST['bp_create_band_profile_nonce'], 'bp_create_band_profile_action' ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'nonce_failure', $redirect_base_url ) );
        exit;
    }

    // Check user permission
    if ( ! current_user_can( 'create_band_profiles' ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'permission_denied_create', $redirect_base_url ) );
        exit;
    }

    // --- Sanitize and Collect Form Data ---
    $errors = array(); // We'll keep this for potential future use (e.g., passing back field-specific errors)
    $band_data = array();
    $meta_data = array();

    // Title (required)
    $band_data['post_title'] = isset( $_POST['band_title'] ) ? sanitize_text_field( $_POST['band_title'] ) : '';
    if ( empty( $band_data['post_title'] ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'title_required', $redirect_base_url ) );
        exit;
    }

    // --- Check for Duplicate Title ---
    // Check if a band profile with the same title already exists
    $existing_band = get_page_by_title( $band_data['post_title'], OBJECT, 'band_profile' );
    if ( $existing_band ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'duplicate_title', $redirect_base_url ) );
        exit;
    }

    // Bio (Content)
    $band_data['post_content'] = isset( $_POST['band_bio'] ) ? wp_kses_post( $_POST['band_bio'] ) : ''; // Use wp_kses_post for content

    // Genre
    $meta_data['_genre'] = isset( $_POST['genre'] ) ? sanitize_text_field( $_POST['genre'] ) : '';

    // Local Scene (using _local_city for consistency)
    $meta_data['_local_city'] = isset( $_POST['local_city'] ) ? sanitize_text_field( $_POST['local_city'] ) : '';

    // Default forum setting: Allow public topic creation
    $meta_data['_allow_public_topic_creation'] = '1';

    // Store file data temporarily if it exists, to process after post creation
    $temp_featured_image_file = null;
    if ( isset( $_FILES['featured_image'] ) && !empty( $_FILES['featured_image']['tmp_name'] ) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK ) {
        $temp_featured_image_file = $_FILES['featured_image'];
    }
    $temp_prefill_avatar_id = null;
    if ( !$temp_featured_image_file && !isset( $_POST['band_id'] ) && isset( $_POST['prefill_user_avatar_id'] ) && !empty( $_POST['prefill_user_avatar_id'] ) ) {
        $temp_prefill_avatar_id = absint( $_POST['prefill_user_avatar_id'] );
    }
    $temp_header_image_file = null;
    if ( isset( $_FILES['band_header_image'] ) && $_FILES['band_header_image']['error'] == UPLOAD_ERR_OK ) {
        $temp_header_image_file = $_FILES['band_header_image'];
    }

    // --- Create the Post ---
    $band_data['post_type']   = 'band_profile';
    $band_data['post_status'] = 'publish'; // Or 'pending' if moderation is needed
    $band_data['post_author'] = get_current_user_id();

    $new_band_id = wp_insert_post( $band_data, true ); // Pass true to return WP_Error on failure

    if ( is_wp_error( $new_band_id ) ) {
        // Handle post creation error
        // For more specific errors, you could pass $new_band_id->get_error_code()
        wp_safe_redirect( add_query_arg( 'bp_error', 'creation_failed', $redirect_base_url ) );
        exit;
    }

    // --- Save Meta Data ---
    foreach ( $meta_data as $key => $value ) {
        if ( ! empty( $value ) ) {
            update_post_meta( $new_band_id, $key, $value );
        }
    }
    
    // --- Handle Featured Image Upload (after post creation) ---
    if ( $temp_featured_image_file ) {
        // Sideload the image and set as featured image
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        // media_handle_sideload() expects an array like a $_FILES element
        // Pass $new_band_id so the attachment is associated with the post
        $featured_image_attach_id = media_handle_sideload( $temp_featured_image_file, $new_band_id );

        if ( ! is_wp_error( $featured_image_attach_id ) ) {
            set_post_thumbnail( $new_band_id, $featured_image_attach_id );
        } else {
            // error_log("Error sideloading featured image for new band $new_band_id: " . $featured_image_attach_id->get_error_message());
        }
    } elseif ( $temp_prefill_avatar_id && wp_attachment_is_image( $temp_prefill_avatar_id ) ) {
        set_post_thumbnail( $new_band_id, $temp_prefill_avatar_id );
    }

    // --- Handle Band Header Image Upload (after post creation) ---
    if ( $temp_header_image_file ) {
        if ( ! function_exists( 'media_handle_sideload' ) ) { // media_handle_upload also works and is common
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
        }
        // Using media_handle_sideload for consistency, though media_handle_upload could be used if passing the file key 'band_header_image'
        // For media_handle_sideload, we pass the actual file array.
        $band_header_image_attach_id = media_handle_sideload( $temp_header_image_file, $new_band_id ); 

        if ( ! is_wp_error( $band_header_image_attach_id ) ) {
            update_post_meta( $new_band_id, '_band_profile_header_image_id', $band_header_image_attach_id );
        } else {
            // error_log("Error sideloading band header image for new band $new_band_id: " . $band_header_image_attach_id->get_error_message());
            // Optionally, redirect with a specific error for header image if critical
            // wp_safe_redirect( add_query_arg( 'bp_error', 'header_image_upload_failed', $redirect_base_url ) );
            // exit;
        }
    }

    // --- Link Creator as Member --- 
    bp_add_band_membership( get_current_user_id(), $new_band_id );
    
    // --- Trigger Forum Creation ---
    // The save_post_band_profile hook should fire automatically after wp_insert_post 
    // if the status is publish, creating the forum.

    // --- Get the ID of the link page that should have been created by the save_post_band_profile hook ---
    $new_link_page_id = get_post_meta( $new_band_id, '_extrch_link_page_id', true );

    // --- Redirect to the manage band profile page with success flags ---
    $manage_page_url = bp_get_manage_band_page_url();
    $query_args = array(
        'bp_success' => 'created',
        'new_band_id' => $new_band_id
    );
    if ( $new_link_page_id ) {
        $query_args['new_link_page_id'] = $new_link_page_id;
    }
    // Ensure the user is redirected to the newly created band's edit view within the manage page
    $redirect_url = add_query_arg( 'band_id', $new_band_id, $manage_page_url ); 
    $redirect_url = add_query_arg( $query_args, $redirect_url );

    wp_safe_redirect( $redirect_url );
    exit;

}
add_action( 'template_redirect', 'bp_handle_create_band_profile_submission' ); 

/**
 * Processes the submission of the 'Edit Band Profile' form.
 *
 * Hooked to template_redirect to catch the submission before the page loads.
 */
function bp_handle_edit_band_profile_submission() {
    // Check if our edit form was submitted
    if ( ! isset( $_POST['bp_edit_band_profile_nonce'] ) ) {
        return; // Not our submission
    }

    // Get the Band ID being edited (from hidden input)
    $band_id = isset( $_POST['band_id'] ) ? absint( $_POST['band_id'] ) : 0;

    // Determine the redirect URL for errors (back to the edit page)
    $redirect_base_url = bp_get_manage_band_page_url(); // Base URL
    $error_redirect_url = $redirect_base_url; // Default if no band_id
    if ( $band_id > 0 ) {
         $error_redirect_url = add_query_arg( 'band_id', $band_id, $redirect_base_url );
    }

    // Verify the nonce
    if ( ! wp_verify_nonce( $_POST['bp_edit_band_profile_nonce'], 'bp_edit_band_profile_action' ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'nonce_failure', $error_redirect_url ) );
        exit;
    }

    if ( ! $band_id || get_post_type( $band_id ) !== 'band_profile' ) {
         wp_safe_redirect( add_query_arg( 'bp_error', 'invalid_band_id', $redirect_base_url ) ); // Redirect to base if ID is bad
         exit;
    }

    // Check user permission to edit *this specific post*
    if ( ! current_user_can( 'manage_band_members', $band_id ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'permission_denied_edit', $error_redirect_url ) );
        exit;
    }

    // --- Sanitize and Collect Form Data ---
    $errors = array();
    $update_band_data = array(
        'ID' => $band_id, // Must include ID for wp_update_post
    );
    $update_meta_data = array();

    // Title (required)
    $update_band_data['post_title'] = isset( $_POST['band_title'] ) ? sanitize_text_field( $_POST['band_title'] ) : '';
    if ( empty( $update_band_data['post_title'] ) ) {
        wp_safe_redirect( add_query_arg( 'bp_error', 'title_required', $error_redirect_url ) );
        exit;
    }
    
    // --- Check for Duplicate Title (only if title changed) ---
    $current_title = get_the_title($band_id);
    if ($update_band_data['post_title'] !== $current_title) {
        $existing_band = get_page_by_title( $update_band_data['post_title'], OBJECT, 'band_profile' );
        // Check if an existing band profile with the new title exists AND it's not the current one we are editing
        if ( $existing_band && $existing_band->ID !== $band_id ) {
            wp_safe_redirect( add_query_arg( 'bp_error', 'duplicate_title', $error_redirect_url ) );
            exit;
        }
    }

    // Bio (Content)
    $update_band_data['post_content'] = isset( $_POST['band_bio'] ) ? wp_kses_post( wp_unslash( $_POST['band_bio'] ) ) : ''; // Use wp_kses_post for content

    // Genre
    $update_meta_data['_genre'] = isset( $_POST['genre'] ) ? sanitize_text_field( $_POST['genre'] ) : '';

    // Local Scene (City)
    $update_meta_data['_local_city'] = isset( $_POST['local_city'] ) ? sanitize_text_field( $_POST['local_city'] ) : '';

    // Forum Settings - Restrict Public Topic Creation
    // If the 'restrict_public_topics' checkbox is checked (value '1'), it means we should restrict public creation,
    // so _allow_public_topic_creation should be '0'.
    // If the checkbox is NOT checked, it means we should allow public creation (default behavior),
    // so _allow_public_topic_creation should be '1'.
    $update_meta_data['_allow_public_topic_creation'] = isset( $_POST['restrict_public_topics'] ) ? '0' : '1';

    // --- Handle Featured Image Update/Removal --- 
    $new_featured_image_id = 0;
    $remove_featured_image = isset( $_POST['remove_featured_image'] ) && $_POST['remove_featured_image'] === '1';

    // Check if a new featured image was uploaded
    if ( isset( $_FILES['featured_image'] ) && $_FILES['featured_image']['error'] == UPLOAD_ERR_OK ) {
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
        }
        $new_featured_image_id = media_handle_upload( 'featured_image', $band_id ); // Pass band ID for attachment parent

        if ( is_wp_error( $new_featured_image_id ) ) {
            wp_safe_redirect( add_query_arg( 'bp_error', 'image_upload_failed', $error_redirect_url ) );
            exit;
        }
    }

    // --- Handle Band Header Image Update --- 
    $new_band_header_image_id = 0;
    if ( isset( $_FILES['band_header_image'] ) && $_FILES['band_header_image']['error'] == UPLOAD_ERR_OK ) {
        if ( ! function_exists( 'media_handle_upload' ) ) { // Safe check
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
        }
        $new_band_header_image_id = media_handle_upload( 'band_header_image', $band_id );
        if ( is_wp_error( $new_band_header_image_id ) ) {
            wp_safe_redirect( add_query_arg( 'bp_error', 'header_image_upload_failed', $error_redirect_url ) );
            exit;
        }
    }

    // If errors were collected previously (unreachable with current logic)
    // if ( ! empty( $errors ) ) {
    //     wp_safe_redirect( add_query_arg( 'bp_error', 'validation_failed', $error_redirect_url ) ); // Example
    //     exit;
    // }

    // --- Update the Post --- 
    $updated_post_id = wp_update_post( $update_band_data, true );

    if ( is_wp_error( $updated_post_id ) ) {
        // Handle post update error
         wp_safe_redirect( add_query_arg( 'bp_error', 'update_failed', $error_redirect_url ) );
         exit;
    }

    // --- Update Meta Data --- 
    foreach ( $update_meta_data as $key => $value ) {
        if ( ! empty( $value ) ) {
            update_post_meta( $band_id, $key, $value );
        } else {
            // Delete meta if the field was submitted empty
            delete_post_meta( $band_id, $key );
        }
    }
    
    // --- Set/Remove Featured Image ---
    if ( $new_featured_image_id > 0 ) {
        // New image was uploaded. Get the ID of the old thumbnail before setting the new one.
        $old_thumbnail_id = get_post_thumbnail_id( $band_id );

        // Set the new image as the thumbnail.
        set_post_thumbnail( $band_id, $new_featured_image_id );

        // If there was an old thumbnail, and it's different from the new one, delete the old one.
        if ( $old_thumbnail_id && $old_thumbnail_id != $new_featured_image_id ) {
            wp_delete_attachment( $old_thumbnail_id, true ); // true to force delete, bypass trash
        }
    } 
    // Note: The elseif ( $remove_featured_image ) block is now effectively obsolete as we removed the checkbox.
    // If a user uploads no new image, the existing image simply remains.
    // If the intention was to allow *removal* without replacement, that feature is now gone.

    // --- Set/Update Band Header Image ---
    if ( $new_band_header_image_id > 0 ) {
        $old_header_image_id = get_post_meta( $band_id, '_band_profile_header_image_id', true );
        update_post_meta( $band_id, '_band_profile_header_image_id', $new_band_header_image_id );
        if ( $old_header_image_id && $old_header_image_id != $new_band_header_image_id ) {
            wp_delete_attachment( $old_header_image_id, true );
        }
    }

    // --- Handle Member Management --- 
    $current_user_id = get_current_user_id();
    $members_meta_changed_flag = false; // Flag to indicate if any member-related meta was changed

    // Process Removals (Existing Linked Members)
    if ( isset( $_POST['remove_member_ids'] ) && ! empty( $_POST['remove_member_ids'] ) ) {
        $ids_to_remove_str = sanitize_text_field( $_POST['remove_member_ids'] );
        $user_ids_to_remove = array_filter( array_map( 'absint', explode( ',', $ids_to_remove_str ) ) );
        
        $removed_count = 0;
        foreach ( $user_ids_to_remove as $user_id_to_remove ) {
            // Basic validation: Must be a valid ID and not the current user (though JS should prevent self-removal marking)
            if ( $user_id_to_remove > 0 && $user_id_to_remove !== $current_user_id ) { 
                if ( bp_remove_band_membership( $user_id_to_remove, $band_id ) ) {
                    $removed_count++;
                    $members_meta_changed_flag = true;
                }
            }
        }
        if ( $removed_count > 0 ) {
            // $member_change_status variable is not used further, can be removed or kept for logging
            // $member_change_status[\'removed_linked\'] = $removed_count;
        }
    }

    // --- Redirect to the manage band profile page --- 
    $manage_page_url = bp_get_manage_band_page_url();
    $query_args = ['bp_success' => 'updated'];

    // Always include band_id in the redirect to ensure the user returns to editing the same band profile
    if ( $band_id > 0 ) {
        $query_args['band_id'] = $band_id;
    }

    if ( $members_meta_changed_flag ) { 
        $query_args['members_changed'] = '1'; 
    }

    wp_safe_redirect( add_query_arg( $query_args, $manage_page_url ) ); 
    exit;

}
add_action( 'template_redirect', 'bp_handle_edit_band_profile_submission' ); 