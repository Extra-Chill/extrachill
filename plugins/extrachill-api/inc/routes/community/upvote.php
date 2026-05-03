<?php
/**
 * REST route: POST /wp-json/extrachill/v1/community/upvote
 *
 * Community upvote endpoint for forum topics and replies.
 * Delegates to business logic in extrachill-community plugin.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_community_upvote_route');

function extrachill_api_register_community_upvote_route() {
	register_rest_route('extrachill/v1', '/community/upvote', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_community_upvote_handler',
		'permission_callback' => function() {
			return is_user_logged_in();
		},
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function($param) {
					return is_numeric($param) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'type' => array(
				'required'          => true,
				'type'              => 'string',
				'enum'              => array('topic', 'reply'),
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	));
}

function extrachill_api_community_upvote_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/community-upvote' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'community-upvote ability not available.', array( 'status' => 503 ) );
	}

	$result = $ability->execute(
		array(
			'post_id' => (int) $request->get_param( 'post_id' ),
			'type'    => (string) $request->get_param( 'type' ),
			'user_id' => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
