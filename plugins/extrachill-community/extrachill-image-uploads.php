<?php
function handle_forum_image_upload() {
    // Verify nonce and user permission
    if (!check_ajax_referer('image_upload', '_ajax_nonce', false)) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'), 403);
        return;
    }

    if (!is_user_logged_in() || !current_user_can('participate')) {
        wp_send_json_error(array('message' => 'You are not authorized to upload images.'), 401);
        return;
    }

    // Check if the image file is provided
    if (isset($_FILES['forum_image']) && $_FILES['forum_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['forum_image'];
        $file_type = wp_check_filetype($file['name']);

        // Validate the file type
        if (strpos($file_type['type'], 'image') === false) {
            wp_send_json_error(array('message' => 'The uploaded file is not a valid image.'), 400);
            return;
        }

        // Handle file upload
        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($file, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            // Create attachment post for the uploaded file
            $attachment = array(
                'post_mime_type' => $movefile['type'],
                'post_title'     => sanitize_file_name($file['name']),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );

            $attachment_id = wp_insert_attachment($attachment, $movefile['file']);

            // Generate attachment metadata and update it
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $movefile['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);

            // Retrieve the URL of the uploaded image
            $image_url = wp_get_attachment_url($attachment_id);

            // Return success response with the image URL
            wp_send_json_success(array(
                'message'       => 'Image uploaded successfully',
                'attachment_id' => $attachment_id,
                'url'           => $image_url
            ));
            return;
        } else {
            // Handle upload error
            wp_send_json_error(array('message' => $movefile['error']), 500);
            return;
        }
    }

    wp_send_json_error(array('message' => 'No file was uploaded.'), 400);
}

add_action('wp_ajax_handle_forum_image_upload', 'handle_forum_image_upload');
add_action('wp_ajax_nopriv_handle_forum_image_upload', 'handle_forum_image_upload');
