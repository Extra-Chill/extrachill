<?php
/**
 * REST route: POST /wp-json/extrachill/v1/community/upvote
 *
 * Community upvote endpoint for forum topics and replies.
 * Delegates to business logic in extrachill-community plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_community_upvote_route');

function extrachill_api_register_community_upvote_route() {
	register_rest_route('extrachill/v1', '/community/upvote', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_community_upvote_handler',
		'permission_callback' => function() {
			return is_user_logged_in();
		},
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function($param) {
					return is_numeric($param) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'type' => array(
				'required'          => true,
				'type'              => 'string',
				'enum'              => array('topic', 'reply'),
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	));
}

function extrachill_api_community_upvote_handler($request) {
	$post_id = $request->get_param('post_id');
	$type = $request->get_param('type');
	$user_id = get_current_user_id();

	if (!function_exists('extrachill_process_upvote')) {
		return new WP_Error(
			'function_missing',
			'Upvote function not available. Please ensure extrachill-community plugin is activated.',
			array('status' => 500)
		);
	}

	$result = extrachill_process_upvote($post_id, $type, $user_id);

	if ($result['success']) {
		return rest_ensure_response(array(
			'success' => true,
			'message' => $result['message'],
			'new_count' => $result['new_count'],
			'upvoted' => $result['upvoted']
		));
	} else {
		return new WP_Error(
			'upvote_failed',
			$result['message'],
			array('status' => 400)
		);
	}
}
