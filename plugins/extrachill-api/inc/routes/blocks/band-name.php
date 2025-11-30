<?php
/**
 * REST route: POST /wp-json/extrachill/v1/blocks/band-name
 *
 * Band name generator endpoint for ExtraChill Blocks.
 * Delegates to business logic in extrachill-blocks plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_blocks_band_name_route');

function extrachill_api_register_blocks_band_name_route() {
	register_rest_route('extrachill/v1', '/blocks/band-name', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_blocks_band_name_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'input' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'genre' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'number_of_words' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'first_the' => array(
				'type' => 'boolean',
			),
			'and_the' => array(
				'type' => 'boolean',
			),
		),
	));
}

function extrachill_api_blocks_band_name_handler($request) {
	$input = $request->get_param('input');
	$genre = $request->get_param('genre');
	$number_of_words = $request->get_param('number_of_words');
	$first_the = $request->get_param('first_the');
	$and_the = $request->get_param('and_the');

	if (empty($input)) {
		return new WP_Error(
			'invalid_input',
			'Please enter your name or word',
			array('status' => 400)
		);
	}

	if (!function_exists('extrachill_blocks_generate_band_name')) {
		return new WP_Error(
			'function_missing',
			'Band name generator function not available. Please ensure extrachill-blocks plugin is activated.',
			array('status' => 500)
		);
	}

	$generated_name = extrachill_blocks_generate_band_name($input, $genre, $number_of_words, $first_the, $and_the);

	return rest_ensure_response(array(
		'name' => $generated_name
	));
}
