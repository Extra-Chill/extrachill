<?php
/**
 * REST route: POST /wp-json/extrachill/v1/chat/message
 *
 * Send a message to the AI chat and receive a response.
 * Delegates to business logic in extrachill-chat plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_chat_message_route' );

function extrachill_api_register_chat_message_route() {
	register_rest_route( 'extrachill/v1', '/chat/message', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_chat_message_handler',
		'permission_callback' => function () {
			return is_user_logged_in();
		},
		'args'                => array(
			'message' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return sanitize_textarea_field( wp_unslash( $value ) );
				},
				'validate_callback' => function ( $value ) {
					return ! empty( trim( $value ) );
				},
			),
		),
	) );
}

function extrachill_api_chat_message_handler( $request ) {
	$user_message = $request->get_param( 'message' );
	$user_id      = get_current_user_id();

	if ( ! function_exists( 'ec_chat_get_or_create_chat' ) ) {
		return new WP_Error(
			'function_missing',
			'Chat functions not available. Please ensure extrachill-chat plugin is activated.',
			array( 'status' => 500 )
		);
	}

	$chat_post_id = ec_chat_get_or_create_chat( $user_id );

	if ( is_wp_error( $chat_post_id ) ) {
		error_log( 'ExtraChill Chat History Error: ' . $chat_post_id->get_error_message() );
		return new WP_Error(
			'chat_history_error',
			'Sorry, I encountered an error with chat history. Please try again.',
			array( 'status' => 500 )
		);
	}

	$ai_response = ec_chat_send_ai_message( $user_message, $chat_post_id );

	if ( is_wp_error( $ai_response ) ) {
		error_log( 'ExtraChill Chat AI Error: ' . $ai_response->get_error_message() );
		return new WP_Error(
			'ai_error',
			'Sorry, I encountered an error processing your message. Please try again.',
			array( 'status' => 500 )
		);
	}

	$response_content = $ai_response['content'];
	$tool_calls       = $ai_response['tool_calls'] ?? array();
	$messages         = $ai_response['messages'] ?? array();

	if ( ! empty( $messages ) ) {
		ec_chat_save_conversation( $chat_post_id, $messages );
	}

	return rest_ensure_response( array(
		'message'    => $response_content,
		'tool_calls' => $tool_calls,
		'timestamp'  => current_time( 'mysql' ),
	) );
}
