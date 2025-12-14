<?php
/**
 * REST route: GET /wp-json/extrachill/v1/activity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_activity_routes' );

function extrachill_api_register_activity_routes() {
	register_rest_route( 'extrachill/v1', '/activity', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_activity_get_handler',
		'permission_callback' => 'extrachill_api_activity_permission_check',
		'args'                => array(
			'cursor'     => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'limit'      => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'blog_id'    => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'actor_id'   => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'visibility' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'public',
				'enum'              => array( 'private', 'public' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'types'      => array(
				'required' => false,
				'type'     => 'array',
				'items'    => array(
					'type' => 'string',
				),
			),
		),
	) );
}

function extrachill_api_activity_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'Must be logged in.', array( 'status' => 401 ) );
	}

	$visibility = sanitize_text_field( (string) $request->get_param( 'visibility' ) );
	if ( '' === $visibility ) {
		$visibility = 'public';
	}

	if ( 'private' === $visibility && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'rest_forbidden', 'Admin access required.', array( 'status' => 403 ) );
	}

	return true;
}

function extrachill_api_activity_get_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_api_activity_query' ) ) {
		return new WP_Error( 'missing_activity', 'Activity system not loaded.', array( 'status' => 500 ) );
	}

	$args = array(
		'cursor'     => $request->get_param( 'cursor' ),
		'limit'      => $request->get_param( 'limit' ),
		'blog_id'    => $request->get_param( 'blog_id' ),
		'actor_id'   => $request->get_param( 'actor_id' ),
		'visibility' => $request->get_param( 'visibility' ),
		'types'      => $request->get_param( 'types' ),
	);

	$result = extrachill_api_activity_query( $args );
	return rest_ensure_response( $result );
}
