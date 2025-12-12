<?php
/**
 * REST route: POST /wp-json/extrachill/v1/blog/rapper-name
 *
 * Rapper name generator endpoint for ExtraChill Blog.
 * Delegates to business logic in extrachill-blog plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_blog_rapper_name_route');

function extrachill_api_register_blog_rapper_name_route() {
	register_rest_route('extrachill/v1', '/blog/rapper-name', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_blog_rapper_name_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'input' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'gender' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'style' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'number_of_words' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	));
}

function extrachill_api_blog_rapper_name_handler($request) {
	$input = $request->get_param('input');
	$gender = $request->get_param('gender');
	$style = $request->get_param('style');
	$number_of_words = $request->get_param('number_of_words');

	if (empty($input)) {
		return new WP_Error(
			'invalid_input',
			'Please enter your name',
			array('status' => 400)
		);
	}

	if (!function_exists('extrachill_blog_generate_rapper_name')) {
		return new WP_Error(
			'function_missing',
			'Rapper name generator function not available. Please ensure extrachill-blog plugin is activated.',
			array('status' => 500)
		);
	}

	$generated_name = extrachill_blog_generate_rapper_name($input, $style, $gender, $number_of_words);

	return rest_ensure_response(array(
		'name' => $generated_name
	));
}
