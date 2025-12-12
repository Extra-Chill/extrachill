<?php
/**
 * REST route: POST /wp-json/extrachill/v1/blog/image-voting/vote
 *
 * Image voting endpoint for ExtraChill Blog.
 * Delegates to business logic in extrachill-blog plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_blog_image_voting_vote_route');

function extrachill_api_register_blog_image_voting_vote_route() {
	register_rest_route('extrachill/v1', '/blog/image-voting/vote', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_blog_image_voting_vote_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function($param) {
					return is_numeric($param) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'instance_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'email_address' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => 'is_email',
				'sanitize_callback' => 'sanitize_email',
			),
		),
	));
}

function extrachill_api_blog_image_voting_vote_handler($request) {
	$post_id = $request->get_param('post_id');
	$instance_id = $request->get_param('instance_id');
	$email_address = $request->get_param('email_address');

	if (!function_exists('extrachill_blog_process_image_vote')) {
		return new WP_Error(
			'function_missing',
			'Image voting function not available. Please ensure extrachill-blog plugin is activated.',
			array('status' => 500)
		);
	}

	$result = extrachill_blog_process_image_vote($post_id, $instance_id, $email_address);

	if ($result['success']) {
		return rest_ensure_response(array(
			'message' => $result['message'],
			'vote_count' => $result['vote_count']
		));
	} else {
		return new WP_Error(
			isset($result['code']) ? $result['code'] : 'vote_failed',
			$result['message'],
			array('status' => 400)
		);
	}
}
