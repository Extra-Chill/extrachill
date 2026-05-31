<?php
/**
 * REST routes: Community notifications
 *
 * Endpoints:
 * - GET  /wp-json/extrachill/v1/community/notifications
 * - POST /wp-json/extrachill/v1/community/notifications/mark-read
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_notifications_routes' );

function extrachill_api_register_community_notifications_routes() {

	// List notifications.
	register_rest_route(
		'extrachill/v1',
		'/community/notifications',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_community_notifications_list_handler',
			'permission_callback' => 'extrachill_api_community_notifications_permission',
			'args'                => array(
				'unread' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
				// Backward-compatible: existing clients send "limit"; mapped to per_page below.
				'limit'  => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 50,
				),
				'page'   => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 1,
				),
			),
		)
	);

	// Mark all notifications as read.
	register_rest_route(
		'extrachill/v1',
		'/community/notifications/mark-read',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_community_notifications_mark_read_handler',
			'permission_callback' => 'extrachill_api_community_notifications_permission',
		)
	);
}

function extrachill_api_community_notifications_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
	}
	return true;
}

function extrachill_api_community_notifications_list_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-notifications' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'get-notifications ability not available.', array( 'status' => 503 ) );
	}

	$input = array(
		'user_id' => get_current_user_id(),
	);

	if ( $request->get_param( 'unread' ) ) {
		$input['unread'] = true;
	}

	// Map the legacy "limit" route arg to the substrate ability's "per_page".
	$limit = (int) $request->get_param( 'limit' );
	if ( $limit > 0 ) {
		$input['per_page'] = $limit;
	}

	$page = (int) $request->get_param( 'page' );
	if ( $page > 0 ) {
		$input['page'] = $page;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

function extrachill_api_community_notifications_mark_read_handler() {
	$ability = wp_get_ability( 'extrachill/mark-notifications-read' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'mark-notifications-read ability not available.', array( 'status' => 503 ) );
	}

	// No notification_id => marks ALL unread, preserving the original mark-all behavior.
	$result = $ability->execute(
		array(
			'user_id' => get_current_user_id(),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
