<?php
/**
 * REST routes: Comment editor
 *
 * Thin REST wrappers around comment editor abilities registered by
 * extrachill-multisite. Each handler is wp_get_ability(...)->execute(...) and
 * nothing else.
 *
 * Endpoints:
 * - GET  /wp-json/extrachill/v1/comments/<id>/editor
 * - POST /wp-json/extrachill/v1/comments/<id>/editor
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_comments_editor_routes' );

function extrachill_api_register_comments_editor_routes() {

	register_rest_route(
		'extrachill/v1',
		'/comments/(?P<id>[\d]+)/editor',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_comments_editor_get_handler',
				'permission_callback' => 'extrachill_api_comments_editor_read_permission',
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
				'callback'            => 'extrachill_api_comments_editor_update_handler',
				'permission_callback' => 'extrachill_api_comments_editor_write_permission',
				'args'                => array(
					'content' => array(
						'required' => true,
						'type'     => 'string',
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

function extrachill_api_comments_editor_read_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'You must be logged in to load comments for editing.', array( 'status' => 401 ) );
	}
	return true;
}

function extrachill_api_comments_editor_write_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'You must be logged in to edit comments.', array( 'status' => 401 ) );
	}
	return true;
}

function extrachill_api_comments_editor_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/comment-get-for-editor' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'comment-get-for-editor ability not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'comment_id' => (int) $request->get_param( 'id' ),
			'blog_id'    => (int) $request->get_param( 'blog_id' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_comments_editor_update_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/comment-update' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'comment-update ability not available.', array( 'status' => 503 ) );
	}

	$content = $request->get_param( 'content' );
	$content = is_string( $content ) ? wp_unslash( $content ) : '';

	$result = $ability->execute(
		array(
			'comment_id' => (int) $request->get_param( 'id' ),
			'content'    => $content,
			'blog_id'    => (int) $request->get_param( 'blog_id' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
