<?php
/**
 * REST routes: Community replies
 *
 * Endpoints:
 * - GET  /wp-json/extrachill/v1/community/replies
 * - POST /wp-json/extrachill/v1/community/replies
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_replies_routes' );

function extrachill_api_register_community_replies_routes() {

	register_rest_route(
		'extrachill/v1',
		'/community/replies',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_community_replies_list_handler',
				'permission_callback' => '__return_true',
				'args'                => array(
					'topic_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 30,
					),
					'page' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_community_replies_create_handler',
				'permission_callback' => 'extrachill_api_community_replies_write_permission',
				'args'                => array(
					'topic_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'content' => array(
						'required' => true,
						'type'     => 'string',
					),
					'reply_to' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);
}

function extrachill_api_community_replies_write_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'You must be logged in to post replies.', array( 'status' => 401 ) );
	}
	return true;
}

function extrachill_api_community_replies_list_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-list-replies' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-list-replies ability not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'topic_id' => (int) $request->get_param( 'topic_id' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'page'     => (int) $request->get_param( 'page' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_community_replies_create_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-create-reply' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-create-reply ability not available.', array( 'status' => 503 ) );
	}

	$content = $request->get_param( 'content' );
	$content = is_string( $content ) ? wp_unslash( $content ) : '';

	$result = $ability->execute(
		array(
			'topic_id' => (int) $request->get_param( 'topic_id' ),
			'content'  => $content,
			'reply_to' => (int) $request->get_param( 'reply_to' ),
			'user_id'  => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
