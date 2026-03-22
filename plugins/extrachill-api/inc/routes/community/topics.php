<?php
/**
 * REST routes: Community topics
 *
 * Endpoints:
 * - GET  /wp-json/extrachill/v1/community/topics
 * - POST /wp-json/extrachill/v1/community/topics
 * - GET  /wp-json/extrachill/v1/community/topics/<id>
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_topics_routes' );

function extrachill_api_register_community_topics_routes() {

	// List / Create topics.
	register_rest_route(
		'extrachill/v1',
		'/community/topics',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_community_topics_list_handler',
				'permission_callback' => '__return_true',
				'args'                => array(
					'forum_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'per_page' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 20,
					),
					'page' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
					),
					'orderby' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'date',
						'enum'     => array( 'date', 'modified', 'title' ),
					),
					'order' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'DESC',
						'enum'     => array( 'ASC', 'DESC' ),
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_community_topics_create_handler',
				'permission_callback' => 'extrachill_api_community_topics_write_permission',
				'args'                => array(
					'forum_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'title' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			),
		)
	);

	// Get single topic.
	register_rest_route(
		'extrachill/v1',
		'/community/topics/(?P<id>[\d]+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_community_topics_get_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'include_replies' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => true,
				),
				'replies_per_page' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 30,
				),
				'replies_page' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 1,
				),
			),
		)
	);
}

function extrachill_api_community_topics_write_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'You must be logged in to create topics.', array( 'status' => 401 ) );
	}
	return true;
}

function extrachill_api_community_topics_list_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-list-topics' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-list-topics ability not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'forum_id' => (int) $request->get_param( 'forum_id' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'page'     => (int) $request->get_param( 'page' ),
			'orderby'  => (string) $request->get_param( 'orderby' ),
			'order'    => (string) $request->get_param( 'order' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_community_topics_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-get-topic' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-get-topic ability not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'topic_id'        => (int) $request->get_param( 'id' ),
			'include_replies' => (bool) $request->get_param( 'include_replies' ),
			'replies_per_page' => (int) $request->get_param( 'replies_per_page' ),
			'replies_page'    => (int) $request->get_param( 'replies_page' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_community_topics_create_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-create-topic' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-create-topic ability not available.', array( 'status' => 503 ) );
	}

	$content = $request->get_param( 'content' );
	$content = is_string( $content ) ? wp_unslash( $content ) : '';

	$result = $ability->execute(
		array(
			'forum_id' => (int) $request->get_param( 'forum_id' ),
			'title'    => (string) $request->get_param( 'title' ),
			'content'  => $content,
			'user_id'  => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
