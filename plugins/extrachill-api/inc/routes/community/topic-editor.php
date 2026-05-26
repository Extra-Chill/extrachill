<?php
/**
 * REST routes: Community topic editor
 *
 * Thin REST wrappers around editor abilities registered by extrachill-community.
 * Each handler is wp_get_ability(...)->execute(...) and nothing else — domain
 * logic, permission checks, sanitization, and writes all live in the ability.
 *
 * Endpoints:
 * - GET  /wp-json/extrachill/v1/community/topics/<id>/editor
 * - POST /wp-json/extrachill/v1/community/topics/<id>/editor
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_topic_editor_routes' );

function extrachill_api_register_community_topic_editor_routes() {

	register_rest_route(
		'extrachill/v1',
		'/community/topics/(?P<id>[\d]+)/editor',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_community_topic_editor_get_handler',
				'permission_callback' => 'extrachill_api_community_topic_editor_read_permission',
				'args'                => array(
					'blog_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'extrachill_api_community_topic_editor_update_handler',
				'permission_callback' => 'extrachill_api_community_topic_editor_write_permission',
				'args'                => array(
					'title' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'content' => array(
						'required' => true,
						'type'     => 'string',
					),
					'format' => array(
						'required' => false,
						'type'     => 'string',
						'enum'     => array( 'html', 'markdown' ),
						'default'  => 'html',
					),
					'blog_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);
}

function extrachill_api_community_topic_editor_read_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'You must be logged in to load topics for editing.', array( 'status' => 401 ) );
	}
	return true;
}

function extrachill_api_community_topic_editor_write_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'You must be logged in to edit topics.', array( 'status' => 401 ) );
	}
	return true;
}

function extrachill_api_community_topic_editor_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-get-topic-for-editor' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-get-topic-for-editor ability not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'topic_id' => (int) $request->get_param( 'id' ),
			'blog_id'  => (int) $request->get_param( 'blog_id' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_community_topic_editor_update_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/community-update-topic' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-update-topic ability not available.', array( 'status' => 503 ) );
	}

	$content = $request->get_param( 'content' );
	$content = is_string( $content ) ? wp_unslash( $content ) : '';

	$input = array(
		'topic_id' => (int) $request->get_param( 'id' ),
		'content'  => $content,
		'format'   => (string) $request->get_param( 'format' ),
		'blog_id'  => (int) $request->get_param( 'blog_id' ),
		'user_id'  => get_current_user_id(),
	);

	$title = $request->get_param( 'title' );
	if ( null !== $title ) {
		$input['title'] = is_string( $title ) ? wp_unslash( $title ) : '';
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
