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

    // Process Dynamic Links
    $sanitized_links = array();
    if ( isset( $_POST['band_links'] ) && is_array( $_POST['band_links'] ) ) {
        $supported_link_types = function_exists('bp_get_supported_social_link_types') ? array_keys(bp_get_supported_social_link_types()) : array('website', 'custom'); // Fallback if function missing

        foreach ( $_POST['band_links'] as $link_item ) {
            if ( ! empty( $link_item['url'] ) && ! empty( $link_item['type_key'] ) ) {
                $type_key = sanitize_key( $link_item['type_key'] );
                $url = esc_url_raw( trim( $link_item['url'] ) );
                $custom_label = isset( $link_item['custom_label'] ) ? sanitize_text_field( $link_item['custom_label'] ) : '';

                // Validate type key and URL
                if ( in_array( $type_key, $supported_link_types ) && ! empty( $url ) ) {
                    $link_entry = array(
                        'type_key' => $type_key,
                        'url' => $url,
                    );
                    // Only include custom label if the type is custom and label is not empty
                    if ( $type_key === 'custom' && ! empty( $custom_label ) ) {
                        $link_entry['custom_label'] = $custom_label;
                    }
                    $sanitized_links[] = $link_entry;
                }
            }
        }
    }
    // Store the sanitized links array in the single meta field
    $meta_data['_band_profile_dynamic_links'] = $sanitized_links;

    // Default forum setting: Allow public topic creation
    $meta_data['_allow_public_topic_creation'] = '1';

    // --- Handle Featured Image Upload ---
    $featured_image_id = 0; // Initialize
    if ( isset( $_FILES['featured_image'] ) && $_FILES['featured_image']['error'] == UPLOAD_ERR_OK ) {
        // Check file type and size if needed here before processing

        // WordPress environment is required for media_handle_upload
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
        }

        // The second parameter (0) indicates that the attachment is not attached to any post yet.
        // media_handle_upload will move the file and create an attachment post.
        $featured_image_id = media_handle_upload( 'featured_image', 0 ); 

        if ( is_wp_error( $featured_image_id ) ) {
            // Handle upload error - Redirect back with a generic upload error
            // For more specific errors, you could pass $featured_image_id->get_error_code()
            wp_safe_redirect( add_query_arg( 'bp_error', 'image_upload_failed', $redirect_base_url ) );
            exit;
        }
    }

    // --- Handle Band Header Image Upload ---
    $band_header_image_id = 0; // Initialize
    if ( isset( $_FILES['band_header_image'] ) && $_FILES['band_header_image']['error'] == UPLOAD_ERR_OK ) {
        if ( ! function_exists( 'media_handle_upload' ) ) { // Redundant check if already included for featured_image, but safe.
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
        }
        $band_header_image_id = media_handle_upload( 'band_header_image', 0 );
        if ( is_wp_error( $band_header_image_id ) ) {
            // Using a more specific error code for header image failure
            wp_safe_redirect( add_query_arg( 'bp_error', 'header_image_upload_failed', $redirect_base_url ) );
            exit;
        }
    }

    // If errors were collected previously (though we're redirecting on first error now)
    // This block is currently unreachable but kept for structure if needed later
    // if ( ! empty( $errors ) ) {
    //     // Example redirect (needs session/transient handling for $errors):
    //     // wp_redirect( add_query_arg( 'bp_errors', urlencode( json_encode( $errors ) ), $redirect_base_url ) );
    //     // exit;
    // }

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
    
    // --- Set Featured Image (if uploaded) --- 
    if ( $featured_image_id > 0 ) {
        set_post_thumbnail( $new_band_id, $featured_image_id );
    }

    // --- Set Band Header Image (if uploaded) ---
    if ( $band_header_image_id > 0 ) {
        update_post_meta( $new_band_id, '_band_profile_header_image_id', $band_header_image_id );
    }

    // --- Link Creator as Member --- 
    bp_add_band_membership( get_current_user_id(), $new_band_id );
    
    // --- Trigger Forum Creation ---
    // The save_post_band_profile hook should fire automatically after wp_insert_post 
    // if the status is publish, creating the forum.

    // --- Redirect to the new profile with success flag ---
    $redirect_url = get_permalink( $new_band_id );
    // Add success query arg
    wp_safe_redirect( add_query_arg( 'bp_success', 'created', $redirect_url ) );
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
    if ( ! current_user_can( 'edit_post', $band_id ) ) {
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

    // --- Process SOCIAL LINKS ---
    if ( isset( $_POST['band_profile_social_links_json'] ) ) {
        $social_links_json = wp_unslash( $_POST['band_profile_social_links_json'] );
        $social_links_array = json_decode( $social_links_json, true );
        $sanitized_social_links = array();

        if ( is_array( $social_links_array ) ) {
            // Define expected social types, can be fetched dynamically or defined here
            // For simplicity, we'll assume a function bp_get_valid_social_link_types() might exist or use a basic check.
            // $valid_social_types = function_exists('bp_get_valid_social_link_types') ? bp_get_valid_social_link_types() : ['instagram', 'twitter', 'facebook', 'youtube', 'tiktok', 'soundcloud', 'bandcamp', 'spotify', 'applemusic', 'website', 'email'];

            foreach ( $social_links_array as $link_item ) {
                if ( ! empty( $link_item['type'] ) && isset( $link_item['url'] ) ) { // URL can be empty if user is typing
                    $type = sanitize_key( $link_item['type'] );
                    $url = ($type === 'email') ? sanitize_email( trim( $link_item['url'] ) ) : esc_url_raw( trim( $link_item['url'] ) );
                    
                    // Basic validation: type should be somewhat reasonable, URL might be empty if user cleared it
                    // if (in_array($type, $valid_social_types)) { // More robust validation if needed
                    if (!empty($type)) { // Simpler check for now
                        $sanitized_social_links[] = array(
                            'type' => $type,
                            'url'  => $url,
                        );
                    }
                }
            }
        }
        // Always update, even if empty, to allow clearing all social links.
        update_post_meta( $band_id, '_band_profile_social_links', $sanitized_social_links );
    }
    // --- End SOCIAL LINKS ---

    // Genre
    $update_meta_data['_genre'] = isset( $_POST['genre'] ) ? sanitize_text_field( $_POST['genre'] ) : '';

    // Local Scene (City)
    $update_meta_data['_local_city'] = isset( $_POST['local_city'] ) ? sanitize_text_field( $_POST['local_city'] ) : '';

    // Process Dynamic Links
    $sanitized_links = array(); // Re-initialize for edit handler
    if ( isset( $_POST['band_links'] ) && is_array( $_POST['band_links'] ) ) {
        $supported_link_types = function_exists('bp_get_supported_social_link_types') ? array_keys(bp_get_supported_social_link_types()) : array('website', 'custom'); // Fallback

        foreach ( $_POST['band_links'] as $link_item ) {
            if ( ! empty( $link_item['url'] ) && ! empty( $link_item['type_key'] ) ) {
                $type_key = sanitize_key( $link_item['type_key'] );
                $url = esc_url_raw( trim( $link_item['url'] ) );
                $custom_label = isset( $link_item['custom_label'] ) ? sanitize_text_field( $link_item['custom_label'] ) : '';

                // Validate type key and URL
                if ( in_array( $type_key, $supported_link_types ) && ! empty( $url ) ) {
                    $link_entry = array(
                        'type_key' => $type_key,
                        'url' => $url,
                    );
                    // Only include custom label if the type is custom and label is not empty
                    if ( $type_key === 'custom' && ! empty( $custom_label ) ) {
                        $link_entry['custom_label'] = $custom_label;
                    }
                    $sanitized_links[] = $link_entry;
                }
            }
        }
    }
    // Store the sanitized links array in the single meta field
    $update_meta_data['_band_profile_dynamic_links'] = $sanitized_links;

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
            // Important: Check if the key is the dynamic links key; empty array should be saved, not deleted.
            if ($key === '_band_profile_dynamic_links') {
                update_post_meta( $band_id, $key, array() ); // Save empty array if submitted empty
            } else {
                delete_post_meta( $band_id, $key );
            }
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