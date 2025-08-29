<?php
/**
 * Handles saving of artist link page links and social links from the frontend management form.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the saving of all link page data from the frontend management form.
 */
function extrch_handle_save_link_page_data() {
    
    
    
    if (
        !isset($_POST['bp_save_link_page']) ||
        !isset($_POST['bp_save_link_page_nonce']) ||
        !wp_verify_nonce($_POST['bp_save_link_page_nonce'], 'bp_save_link_page_action')
    ) {
        return; // Nonce check failed or form not submitted.
    }


    $link_page_id = 0;
    $artist_id = 0; // Initialize artist_id

    if (isset($_GET['artist_id'])) {
        $artist_id = absint($_GET['artist_id']);
        $link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
    } elseif (isset($_GET['artist_id'])) {
        // Backward compatibility for old artist_id parameter
        $artist_id = absint($_GET['artist_id']);
        $link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
        
        // Additional debugging for new link pages
        if (!$link_page_id) {
        } else {
            $link_page_post_type = get_post_type($link_page_id);
            $link_page_status = get_post_status($link_page_id);
        }
    } else {
    }

    if (!$link_page_id || get_post_type($link_page_id) !== 'artist_link_page') {
        // Fallback: try to get from hidden field or bail
        if (isset($_POST['link_page_id'])) {
            $link_page_id = absint($_POST['link_page_id']);
            
            // If we got link_page_id from POST, try to get associated artist_id if not already set
            if ( !$artist_id && $link_page_id ) {
                $associated_artist_id = get_post_meta($link_page_id, '_associated_artist_profile_id', true);
                if ($associated_artist_id) {
                    $artist_id = absint($associated_artist_id);
                } else {
                }
            }
        } else {
        }
    }

    if (!$link_page_id) {
        wp_die(__('Could not determine Link Page ID.', 'extrachill-artist-platform'));
    }

    // CRITICAL SECURITY: Validate user permissions for this artist
    if (!empty($artist_id) && !ec_can_manage_artist(get_current_user_id(), $artist_id)) {
        wp_die(__('Permission denied: You do not have access to manage this artist.', 'extrachill-artist-platform'));
    }

    // Additional validation: ensure user is logged in for any save operation
    if (!is_user_logged_in()) {
        wp_die(__('Permission denied: You must be logged in to save changes.', 'extrachill-artist-platform'));
    }

    // Prepare save data using centralized system
    $save_data = ec_prepare_link_page_save_data( $_POST );
    // Handle link expiration cleanup if enabled
    if ( isset( $save_data['links'] ) && $save_data['link_expiration_enabled'] === '1' ) {
        $now = current_time('timestamp');
        foreach ( $save_data['links'] as $section_idx => &$section ) {
            if ( isset( $section['links'] ) && is_array( $section['links'] ) ) {
                foreach ( $section['links'] as $link_idx => $link ) {
                    if ( ! empty( $link['expires_at'] ) ) {
                        $expires = strtotime( $link['expires_at'] );
                        if ( $expires !== false && $expires <= $now ) {
                            unset( $section['links'][$link_idx] );
                        }
                    }
                }
                if ( isset( $section['links'] ) ) {
                    $section['links'] = array_values( $section['links'] );
                }
            }
        }
        $save_data['links'] = array_values( array_filter( $save_data['links'], function( $section ) {
            return ! empty( $section['links'] );
        } ) );
    } elseif ( isset( $save_data['links'] ) && $save_data['link_expiration_enabled'] === '0' ) {
        // Remove expiration data if feature disabled
        foreach ( $save_data['links'] as &$section ) {
            if ( isset( $section['links'] ) && is_array( $section['links'] ) ) {
                foreach ( $section['links'] as &$link ) {
                    unset( $link['expires_at'] );
                }
            }
        }
    }

    // Save social links using existing manager
    if ( isset( $_POST['artist_profile_social_links_json'] ) && ! empty( $artist_id ) ) {
        $social_links_json = wp_unslash( $_POST['artist_profile_social_links_json'] );
        $social_manager = extrachill_artist_platform_social_links();
        $save_result = $social_manager->save_from_json( $artist_id, $social_links_json );
        
        if ( is_wp_error( $save_result ) ) {
            error_log( '[LinkPageSave PHP] Failed to save social links: ' . $save_result->get_error_message() );
        }
    }

    // Use centralized save system
    $result = ec_handle_link_page_save( $link_page_id, $save_data, $_FILES );
    
    if ( is_wp_error( $result ) ) {
        wp_die( $result->get_error_message() );
    }



    // --- Featured Link Customization (Title, Desc, Thumbnail) --- 
    // This will now primarily handle the thumbnail and text fields.
    if (function_exists('extrch_save_featured_link_settings')) {
        extrch_save_featured_link_settings($link_page_id, $_POST, $_FILES);
    }

    // NOTE: Data sync is now handled automatically by the ec_link_page_save hook
    // No need for manual sync operations here


    // --- Redirect back with success ---
    $redirect_args = array('artist_id' => $artist_id, 'bp_link_page_updated' => '1');
    
    $link_page_manage = get_page_by_path('manage-link-page');
    $base_url = $link_page_manage ? get_permalink($link_page_manage) : home_url('/manage-link-page/');
    
    $redirect_url = add_query_arg($redirect_args, $base_url);
    
    if (!empty($_POST['tab'])) {
        $tab = sanitize_key($_POST['tab']);
        $redirect_url .= '#' . $tab;
    } elseif (!empty($_GET['tab'])) {
        $tab = sanitize_key($_GET['tab']);
        $redirect_url .= '#' . $tab;
    }
    
    
    // Check if the manage page actually exists
    $manage_page = get_page_by_path('manage-link-page');
    
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_extrch_handle_save_link_page_data', 'extrch_handle_save_link_page_data');
add_action('admin_post_nopriv_extrch_handle_save_link_page_data', 'extrch_handle_save_link_page_data');

// NEW: Handle form submission on init hook for current page submissions (no wp-admin access needed)
function extrch_handle_link_page_form_init() {
    // Only process if this is a POST request with our specific action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['extrch_action']) && 
        $_POST['extrch_action'] === 'save_link_page_data' &&
        isset($_POST['bp_save_link_page_nonce'])) {
        
        error_log('[LinkPageSave INIT] Form submission detected via init hook');
        
        // Call the same handler function 
        extrch_handle_save_link_page_data();
    }
}
add_action('init', 'extrch_handle_link_page_form_init');

// Add AJAX handler for background image uploads (for large files)
function extrch_upload_background_image_ajax() {
    // Check nonce - use the same nonce as the main form
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bp_save_link_page_action')) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    // Check if user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error('User not logged in');
        return;
    }
    
    // Check if file was uploaded
    if (empty($_FILES['link_page_background_image_upload']['tmp_name'])) {
        wp_send_json_error('No file uploaded');
        return;
    }
    
    $link_page_id = isset($_POST['link_page_id']) ? absint($_POST['link_page_id']) : 0;
    
    // If no link page ID provided, try to get it from user's artists
    if (!$link_page_id) {
        $current_user_id = get_current_user_id();
        $user_artist_ids = get_user_meta($current_user_id, '_artist_profile_ids', true);
        if (is_array($user_artist_ids) && !empty($user_artist_ids)) {
            // Get the most recent artist's link page
            $artist_id = $user_artist_ids[0];
            $link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
        }
    }
    
    if (!$link_page_id || get_post_type($link_page_id) !== 'artist_link_page') {
        wp_send_json_error('Invalid link page ID');
        return;
    }
    
    // Check if user can edit this link page
    $associated_artist_id = get_post_meta($link_page_id, '_associated_artist_profile_id', true);
    if ($associated_artist_id) {
        $current_user_id = get_current_user_id();
        $user_artist_ids = get_user_meta($current_user_id, '_artist_profile_ids', true);
        if (!is_array($user_artist_ids) || !in_array($associated_artist_id, $user_artist_ids)) {
            wp_send_json_error('Permission denied');
            return;
        }
    }
    
    // Check file size (5MB limit)
    $max_file_size = 5 * 1024 * 1024; // 5MB in bytes
    if ($_FILES['link_page_background_image_upload']['size'] > $max_file_size) {
        wp_send_json_error('File is too large. Maximum size is 5MB.');
        return;
    }
    
    // Include required WordPress functions
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    // Get old background image ID to delete it later
    $old_bg_image_id = get_post_meta($link_page_id, '_link_page_background_image_id', true);
    
    // Upload the file
    $new_bg_image_id = media_handle_upload('link_page_background_image_upload', $link_page_id);
    
    if (is_wp_error($new_bg_image_id)) {
        wp_send_json_error('Upload failed: ' . $new_bg_image_id->get_error_message());
        return;
    }
    
    if (is_numeric($new_bg_image_id)) {
        // Update the meta with new image ID
        update_post_meta($link_page_id, '_link_page_background_image_id', $new_bg_image_id);
        
        // Delete old image if it exists and is different
        if ($old_bg_image_id && $old_bg_image_id != $new_bg_image_id) {
            wp_delete_attachment($old_bg_image_id, true);
        }
        
        // Get the image URL
        $image_url = wp_get_attachment_url($new_bg_image_id);
        
        if ($image_url) {
            wp_send_json_success(array(
                'url' => $image_url,
                'attachment_id' => $new_bg_image_id
            ));
        } else {
            wp_send_json_error('Failed to get image URL');
        }
    } else {
        wp_send_json_error('Upload failed');
    }
}
add_action('wp_ajax_extrch_upload_background_image_ajax', 'extrch_upload_background_image_ajax');
add_action('wp_ajax_nopriv_extrch_upload_background_image_ajax', 'extrch_upload_background_image_ajax');

// The redundant init hook and its logic are now removed.