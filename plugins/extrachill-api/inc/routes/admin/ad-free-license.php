<?php
/**
 * Ad-Free License Management REST API Endpoints
 *
 * Provides REST endpoints for granting and revoking ad-free licenses.
 * Requires manage_options capability (network administrators only).
 *
 * @endpoint POST /wp-json/extrachill/v1/admin/ad-free-license/grant
 * @endpoint DELETE /wp-json/extrachill/v1/admin/ad-free-license/{user_id}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_ad_free_license_routes' );

/**
 * Registers ad-free license management endpoints.
 */
function extrachill_api_register_ad_free_license_routes() {
	register_rest_route(
		'extrachill/v1',
		'/admin/ad-free-license/grant',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_grant_ad_free_license',
			'permission_callback' => 'extrachill_api_ad_free_admin_permission_check',
			'args'                => array(
				'user_identifier' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Username or email address of user to grant license to.',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/ad-free-license/(?P<user_id>\d+)',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'extrachill_api_revoke_ad_free_license',
			'permission_callback' => 'extrachill_api_ad_free_admin_permission_check',
			'args'                => array(
				'user_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Permission check for ad-free license management endpoints.
 *
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function extrachill_api_ad_free_admin_permission_check() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage ad-free licenses.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Grants an ad-free license to a user.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with grant confirmation or error.
 */
function extrachill_api_grant_ad_free_license( $request ) {
	$identifier = $request->get_param( 'user_identifier' );

	if ( empty( $identifier ) ) {
		return new WP_Error(
			'missing_identifier',
			'User identifier is required.',
			array( 'status' => 400 )
		);
	}

	$user = get_user_by( 'login', $identifier );
	if ( ! $user ) {
		$user = get_user_by( 'email', $identifier );
	}

	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	$existing = get_user_meta( $user->ID, 'extrachill_ad_free_purchased', true );
	if ( $existing ) {
		return new WP_Error(
			'license_exists',
			'User already has ad-free license.',
			array( 'status' => 409 )
		);
	}

	$license_data = array(
		'purchased' => current_time( 'mysql' ),
		'order_id'  => null,
		'username'  => $user->user_login,
	);

	update_user_meta( $user->ID, 'extrachill_ad_free_purchased', $license_data );

	return rest_ensure_response(
		array(
			'message'  => "Ad-free license granted to {$user->user_login}",
			'user_id'  => $user->ID,
			'username' => $user->user_login,
			'email'    => $user->user_email,
		)
	);
}

/**
 * Revokes an ad-free license from a user.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with revoke confirmation or error.
 */
function extrachill_api_revoke_ad_free_license( $request ) {
	$user_id = $request->get_param( 'user_id' );

	if ( ! $user_id ) {
		return new WP_Error(
			'missing_user_id',
			'User ID is required.',
			array( 'status' => 400 )
		);
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	$existing = get_user_meta( $user_id, 'extrachill_ad_free_purchased', true );
	if ( ! $existing ) {
		return new WP_Error(
			'no_license',
			'User does not have ad-free license.',
			array( 'status' => 404 )
		);
	}

	delete_user_meta( $user_id, 'extrachill_ad_free_purchased' );

	return rest_ensure_response(
		array(
			'message'  => "Ad-free license revoked for {$user->user_login}",
			'user_id'  => $user_id,
			'username' => $user->user_login,
		)
	);
}
