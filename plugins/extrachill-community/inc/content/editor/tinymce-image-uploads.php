<?php

$thumb_names = array(
	// EDIT_HERE
	'1536x1536',
	'2048x2048'
	// STOP_EDITING
);

add_filter( 'big_image_size_threshold', '__return_false' );

add_filter(
	'intermediate_image_sizes_advanced',
	function( $sizes ) use ( $thumb_names ) {
		foreach ( $thumb_names as $thumb_name ) {
			unset( $sizes[$thumb_name] );
		}
		return $sizes;
	}
);

add_action(
	'init',
	function() use ( $thumb_names ) {
		foreach( $thumb_names as $thumb_name ) {
			remove_image_size( $thumb_name );
		}
	}
);

// Asset enqueue moved to inc/core/assets.php for centralized management

function register_custom_tinymce_plugin($plugin_array) {
    $plugin_array['local_upload_plugin'] = EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/tinymce-image-upload.js'; // Path to your JS file
    return $plugin_array;
}
add_filter('mce_external_plugins', 'register_custom_tinymce_plugin');

function add_custom_tinymce_button($buttons) {
    // 'image_upload' is the ID of the button, must match the ID used in your JS
    array_push($buttons, 'image_upload');
    return $buttons;
}
add_filter('mce_buttons', 'add_custom_tinymce_button'); // Use 'mce_buttons_2' to add to the second row

function handle_tinymce_image_upload() {
    // Check the nonce for security
    check_ajax_referer('handle_tinymce_image_upload_nonce', 'nonce');

    // Verify the user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'You must be logged in to upload files.'));
        wp_die();
    }

    // Handle the image upload
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    $uploadedfile = $_FILES['image'];
    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        // File is uploaded successfully. Now insert it into the WordPress Media Library.
        $filename = $movefile['file'];

        // Prepare an array of post data for the attachment.
        $attachment = array(
            'guid' => $movefile['url'],
            'post_mime_type' => $movefile['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert the attachment.
        $attach_id = wp_insert_attachment($attachment, $filename);

        // Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Generate the metadata for the attachment and update the database record.
        $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
        wp_update_attachment_metadata($attach_id, $attach_data);

        wp_send_json_success(array('url' => wp_get_attachment_url($attach_id)));
    } else {
        // Return error message if upload failed
        wp_send_json_error(array('message' => $movefile['error']));
    }

    wp_die(); // This is required to terminate immediately and return a proper response
}

add_action('wp_ajax_handle_tinymce_image_upload', 'handle_tinymce_image_upload');


