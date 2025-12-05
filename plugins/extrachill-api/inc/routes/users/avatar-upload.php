<?php
/**
 * REST route: POST /wp-json/extrachill/v1/users/avatar
 *
 * Avatar upload endpoint for custom user avatars.
 * Delegates to business logic in extrachill-users plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_avatar_upload_route');

function extrachill_api_register_avatar_upload_route() {
	register_rest_route('extrachill/v1', '/users/avatar', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_avatar_upload_handler',
		'permission_callback' => 'extrachill_api_avatar_upload_permission',
	));
}

function extrachill_api_avatar_upload_permission($request) {
	return is_user_logged_in();
}

function extrachill_api_avatar_upload_handler($request) {
	$user_id = get_current_user_id();

	if (!function_exists('extrachill_process_avatar_upload')) {
		return new WP_Error(
			'function_missing',
			'Avatar upload function not available. Please ensure extrachill-users plugin is activated.',
			array('status' => 500)
		);
	}

	$result = extrachill_process_avatar_upload($user_id, $request->get_file_params());

	if (is_wp_error($result)) {
		return new WP_Error(
			$result->get_error_code(),
			$result->get_error_message(),
			array('status' => 400)
		);
	}

	return rest_ensure_response( array(
		'url'           => $result['url'],
		'attachment_id' => $result['attachment_id'],
	) );
}
