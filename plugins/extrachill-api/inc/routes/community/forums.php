<?php
/**
 * REST routes: Community forums
 *
 * Endpoints:
 * - GET /wp-json/extrachill/v1/community/forums
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_forums_routes' );

function extrachill_api_register_community_forums_routes() {
	register_rest_route(
		'extrachill/v1',
		'/community/forums',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_community_forums_list_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'homepage_only' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
		)
	);
}

function extrachill_api_community_forums_list_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-list-forums' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-list-forums ability not available.', array( 'status' => 503 ) );
	}

	$input = array();
	if ( $request->get_param( 'homepage_only' ) ) {
		$input['homepage_only'] = true;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
