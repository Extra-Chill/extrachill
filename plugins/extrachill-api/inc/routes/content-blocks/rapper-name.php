<?php
/**
 * REST route: POST /wp-json/extrachill/v1/content-blocks/rapper-name
 *
 * Thin REST wrapper for the extrachill/generate-rapper-name ability.
 *
 * Canonical rapper-name generation logic lives in the
 * extrachill/generate-rapper-name ability (extrachill-content-blocks plugin).
 * This route validates input at the HTTP boundary and delegates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_content_blocks_rapper_name_route' );

function extrachill_api_register_content_blocks_rapper_name_route() {
	register_rest_route( 'extrachill/v1', '/content-blocks/rapper-name', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_content_blocks_rapper_name_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'input'           => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'gender'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'style'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'number_of_words' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );
}

function extrachill_api_content_blocks_rapper_name_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/generate-rapper-name' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_not_found',
			'Rapper name generator unavailable. Please ensure extrachill-content-blocks plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( array(
		'input'           => $request->get_param( 'input' ),
		'style'           => $request->get_param( 'style' ),
		'gender'          => $request->get_param( 'gender' ),
		'number_of_words' => $request->get_param( 'number_of_words' ),
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
