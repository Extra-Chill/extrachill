<?php
/**
 * REST route: POST /wp-json/extrachill/v1/community/upload-image
 *
 * Community image upload endpoint for TinyMCE editor.
 * Delegates to business logic in extrachill-community plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_community_upload_image_route');

function extrachill_api_register_community_upload_image_route() {
	register_rest_route('extrachill/v1', '/community/upload-image', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_community_upload_image_handler',
		'permission_callback' => function() {
			return is_user_logged_in();
		},
	));
}

function extrachill_api_community_upload_image_handler($request) {
	$user_id = get_current_user_id();

	if (!function_exists('extrachill_process_tinymce_image_upload')) {
		return new WP_Error(
			'function_missing',
			'Image upload function not available. Please ensure extrachill-community plugin is activated.',
			array('status' => 500)
		);
	}

	// Get files from $_FILES (WordPress REST API doesn't parse multipart/form-data into $request)
	if (!isset($_FILES['image'])) {
		return new WP_Error(
			'no_file',
			'No file uploaded.',
			array('status' => 400)
		);
	}

	$result = extrachill_process_tinymce_image_upload($_FILES['image'], $user_id);

	if ($result['success']) {
		return rest_ensure_response(array(
			'success' => true,
			'url' => $result['url']
		));
	} else {
		return new WP_Error(
			'upload_failed',
			$result['message'],
			array('status' => 400)
		);
	}
}
