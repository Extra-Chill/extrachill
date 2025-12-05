<?php
/**
 * REST route: DELETE /wp-json/extrachill/v1/chat/history
 *
 * Clear the current user's chat history.
 * Delegates to business logic in extrachill-chat plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_chat_history_route' );

function extrachill_api_register_chat_history_route() {
	register_rest_route( 'extrachill/v1', '/chat/history', array(
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'extrachill_api_chat_history_handler',
		'permission_callback' => function () {
			return is_user_logged_in();
		},
	) );
}

function extrachill_api_chat_history_handler( $request ) {
	$user_id = get_current_user_id();

	if ( ! function_exists( 'ec_chat_get_or_create_chat' ) ) {
		return new WP_Error(
			'function_missing',
			'Chat functions not available. Please ensure extrachill-chat plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$chat_post_id = ec_chat_get_or_create_chat( $user_id );

	if ( is_wp_error( $chat_post_id ) ) {
		error_log( 'ExtraChill Chat Clear History Error: ' . $chat_post_id->get_error_message() );
		return new WP_Error(
			'chat_history_error',
			'Sorry, I encountered an error clearing chat history.',
			array( 'status' => 500 )
		);
	}

	$cleared = ec_chat_clear_history( $chat_post_id );

	if ( ! $cleared ) {
		return new WP_Error(
			'clear_failed',
			'Failed to clear chat history.',
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array(
		'message' => 'Chat history cleared successfully.',
	) );
}
