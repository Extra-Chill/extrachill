<?php
/**
 * AJAX Background Image Upload Handler
 * 
 * Handles AJAX uploads for background images in the link page management interface.
 * The main form processing is now handled by ec_admin_post_save_link_page() in save.php.
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler for background image uploads
 * 
 * Called by background.js when users upload background images.
 * Handles file validation, upload, and cleanup of old images.
 */
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
    
    // Use centralized data for cleanup operations (single source of truth)
    $data = ec_get_link_page_data($artist_id, $link_page_id);
    $old_bg_image_id = $data['settings']['background_image_id'] ?? '';
    
    // Upload the file
    $new_bg_image_id = media_handle_upload('link_page_background_image_upload', $link_page_id);
    
    if (is_wp_error($new_bg_image_id)) {
        wp_send_json_error('Upload failed: ' . $new_bg_image_id->get_error_message());
        return;
    }
    
    if (is_numeric($new_bg_image_id)) {
        // Update the meta with new image ID
        update_post_meta($link_page_id, '_link_page_background_image_id', $new_bg_image_id);
        
        // Delete old image using action system
        if ($old_bg_image_id && $old_bg_image_id != $new_bg_image_id) {
            do_action('ec_delete_old_bg_image', $old_bg_image_id);
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
// Register AJAX handlers
add_action('wp_ajax_extrch_upload_background_image_ajax', 'extrch_upload_background_image_ajax');
add_action('wp_ajax_nopriv_extrch_upload_background_image_ajax', 'extrch_upload_background_image_ajax');