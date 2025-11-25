<?php
/**
 * TinyMCE Image Upload Handler
 *
 * Disables large thumbnail generation and provides image upload endpoint.
 *
 * @package ExtraChillCommunity
 */

$thumb_names = array( '1536x1536', '2048x2048' );

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

function register_custom_tinymce_plugin($plugin_array) {
	$plugin_array['local_upload_plugin'] = EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/tinymce-image-upload.js';
	return $plugin_array;
}
add_filter('mce_external_plugins', 'register_custom_tinymce_plugin');

function add_custom_tinymce_button($buttons) {
	array_push($buttons, 'image_upload');
	return $buttons;
}
add_filter('mce_buttons', 'add_custom_tinymce_button');

/**
 * Process TinyMCE image upload (business logic)
 *
 * @param array $file $_FILES array for the uploaded image
 * @param int $user_id User ID performing the upload
 * @return array Response array with success status, url or message
 */
function extrachill_process_tinymce_image_upload($file, $user_id) {
	if (!$user_id) {
		return array('success' => false, 'message' => 'You must be logged in to upload files.');
	}

	if (!function_exists('wp_handle_upload')) {
		require_once(ABSPATH . 'wp-admin/includes/file.php');
	}

	$upload_overrides = array('test_form' => false);
	$movefile = wp_handle_upload($file, $upload_overrides);

	if ($movefile && !isset($movefile['error'])) {
		$filename = $movefile['file'];

		$attachment = array(
			'guid' => $movefile['url'],
			'post_mime_type' => $movefile['type'],
			'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
			'post_content' => '',
			'post_status' => 'inherit'
		);

		$attach_id = wp_insert_attachment($attachment, $filename);

		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$attach_data = wp_generate_attachment_metadata($attach_id, $filename);
		wp_update_attachment_metadata($attach_id, $attach_data);

		return array('success' => true, 'url' => wp_get_attachment_url($attach_id));
	} else {
		return array('success' => false, 'message' => $movefile['error']);
	}
}

/**
 * Admin-ajax handler for TinyMCE image upload (thin routing layer)
 */
function handle_tinymce_image_upload() {
	check_ajax_referer('handle_tinymce_image_upload_nonce', 'nonce');

	if (!is_user_logged_in()) {
		wp_send_json_error(array('message' => 'You must be logged in to upload files.'));
		wp_die();
	}

	if (!isset($_FILES['image'])) {
		wp_send_json_error(array('message' => 'No file uploaded.'));
		wp_die();
	}

	$result = extrachill_process_tinymce_image_upload($_FILES['image'], get_current_user_id());

	if ($result['success']) {
		wp_send_json_success(array('url' => $result['url']));
	} else {
		wp_send_json_error(array('message' => $result['message']));
	}

	wp_die();
}
