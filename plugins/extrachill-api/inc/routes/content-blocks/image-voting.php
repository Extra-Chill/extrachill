<?php
/**
 * REST route: GET /wp-json/extrachill/v1/content-blocks/image-voting/vote-count/{post_id}/{instance_id}
 *
 * Thin REST wrapper for the extrachill/image-voting-list ability.
 *
 * Canonical vote-count lookup logic lives in the
 * extrachill/image-voting-list ability (extrachill-content-blocks plugin).
 * This route validates input at the HTTP boundary and delegates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_content_blocks_image_voting_route' );

function extrachill_api_register_content_blocks_image_voting_route() {
	register_rest_route( 'extrachill/v1', '/content-blocks/image-voting/vote-count/(?P<post_id>\d+)/(?P<instance_id>[a-zA-Z0-9\-]+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_content_blocks_image_voting_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'post_id'     => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'instance_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}

function extrachill_api_content_blocks_image_voting_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/image-voting-list' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_not_found',
			'Image voting unavailable. Please ensure extrachill-content-blocks plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( array(
		'post_id'     => $request->get_param( 'post_id' ),
		'instance_id' => $request->get_param( 'instance_id' ),
	) );

	if ( is_wp_error( $result ) ) {
		// The list ability returns a bare 'post_not_found' WP_Error without an
		// HTTP status; preserve the route's original 404 for that case.
		if ( 'post_not_found' === $result->get_error_code() ) {
			return new WP_Error( 'post_not_found', 'Post not found', array( 'status' => 404 ) );
		}
		return $result;
	}

	return rest_ensure_response( $result );
}
