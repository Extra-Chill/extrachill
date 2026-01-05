<?php
/**
 * Artist Access Management REST API Endpoints
 *
 * Handles artist platform access approval and rejection via REST API.
 * Replaces legacy admin-ajax handlers with modern REST endpoints.
 *
 * @endpoint GET  /wp-json/extrachill/v1/admin/artist-access/{user_id}/approve (email one-click)
 * @endpoint POST /wp-json/extrachill/v1/admin/artist-access/{user_id}/approve (admin tools button)
 * @endpoint POST /wp-json/extrachill/v1/admin/artist-access/{user_id}/reject  (admin tools button)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_access_routes' );

/**
 * Registers artist access management endpoints.
 */
function extrachill_api_register_artist_access_routes() {
	// GET: List all pending requests
	register_rest_route(
		'extrachill/v1',
		'/admin/artist-access',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_artist_access_requests',
			'permission_callback' => 'extrachill_api_artist_access_admin_check',
		)
	);

	// GET: One-click email approval (redirects to admin tools)
	register_rest_route(
		'extrachill/v1',
		'/admin/artist-access/(?P<user_id>\d+)/approve',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_artist_access_email_approve',
			'permission_callback' => 'extrachill_api_artist_access_admin_check',
			'args'                => array(
				'user_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'type'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'token'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// POST: Admin tools button approval (returns JSON)
	register_rest_route(
		'extrachill/v1',
		'/admin/artist-access/(?P<user_id>\d+)/approve',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_artist_access_approve',
			'permission_callback' => 'extrachill_api_artist_access_admin_check',
			'args'                => array(
				'user_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'type'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						return in_array( $value, array( 'artist', 'professional' ), true );
					},
				),
			),
		)
	);

	// POST: Admin tools button rejection (returns JSON)
	register_rest_route(
		'extrachill/v1',
		'/admin/artist-access/(?P<user_id>\d+)/reject',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_artist_access_reject',
			'permission_callback' => 'extrachill_api_artist_access_admin_check',
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
 * Permission check for artist access endpoints.
 *
 * @return bool|WP_Error True if authorized, WP_Error otherwise.
 */
function extrachill_api_artist_access_admin_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be logged in as an administrator.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Gets all pending artist access requests.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with request list or error.
 */
function extrachill_api_get_artist_access_requests( $request ) {
	$args = array(
		'blog_id'    => 0, // Network-wide
		'meta_key'   => 'artist_access_request',
		'fields'     => 'all',
		'orderby'    => 'registered',
		'order'      => 'DESC',
	);

	$user_query = new WP_User_Query( $args );
	$users      = $user_query->get_results();

	$requests = array();
	foreach ( $users as $user ) {
		$request_data = get_user_meta( $user->ID, 'artist_access_request', true );
		if ( empty( $request_data ) || ! is_array( $request_data ) ) {
			continue;
		}

		$requests[] = array(
			'user_id'      => $user->ID,
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'type'         => isset( $request_data['type'] ) ? $request_data['type'] : 'artist',
			'requested_at' => isset( $request_data['timestamp'] ) ? $request_data['timestamp'] : 0,
		);
	}

	return rest_ensure_response(
		array(
			'requests' => $requests,
		)
	);
}

/**
 * Generates HMAC token for email approval links.
 *
 * @param int    $user_id     User ID.
 * @param string $access_type Access type (artist or professional).
 * @param int    $timestamp   Request timestamp.
 * @return string Base64-encoded token.
 */
function extrachill_api_generate_artist_access_token( $user_id, $access_type, $timestamp ) {
	$payload   = $user_id . '|' . $access_type . '|' . $timestamp;
	$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	return base64_encode( $payload . '.' . $signature );
}

/**
 * Validates HMAC token from email approval links.
 *
 * @param string $token   The token to validate.
 * @param int    $user_id Expected user ID.
 * @return array|false Parsed token data or false if invalid.
 */
function extrachill_api_validate_artist_access_token( $token, $user_id ) {
	$decoded = base64_decode( $token, true );
	if ( ! $decoded ) {
		return false;
	}

	$parts = explode( '.', $decoded, 2 );
	if ( count( $parts ) !== 2 ) {
		return false;
	}

	list( $payload, $provided_signature ) = $parts;

	$expected_signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	if ( ! hash_equals( $expected_signature, $provided_signature ) ) {
		return false;
	}

	$payload_parts = explode( '|', $payload, 3 );
	if ( count( $payload_parts ) !== 3 ) {
		return false;
	}

	list( $token_user_id, $token_type, $token_timestamp ) = $payload_parts;

	if ( absint( $token_user_id ) !== $user_id ) {
		return false;
	}

	return array(
		'user_id'   => absint( $token_user_id ),
		'type'      => $token_type,
		'timestamp' => absint( $token_timestamp ),
	);
}

/**
 * Handles one-click email approval (GET request with redirect).
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response or redirect.
 */
function extrachill_api_artist_access_email_approve( $request ) {
	$user_id = $request->get_param( 'user_id' );
	$type    = $request->get_param( 'type' );
	$token   = $request->get_param( 'token' );

	$token_data = extrachill_api_validate_artist_access_token( $token, $user_id );
	if ( ! $token_data ) {
		wp_die( 'Invalid or expired approval link. Please use the admin tools page to approve this request.' );
	}

	if ( $token_data['type'] !== $type ) {
		wp_die( 'Invalid request parameters.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		wp_die( 'User not found.' );
	}

	$has_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
	$has_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';

	if ( $has_artist || $has_professional ) {
		wp_safe_redirect( admin_url( 'tools.php?page=extrachill-admin-tools#artist-access-requests&already_approved=1' ) );
		exit;
	}

	$pending_request = get_user_meta( $user_id, 'artist_access_request', true );
	if ( empty( $pending_request ) || ! is_array( $pending_request ) ) {
		wp_die( 'No pending request found for this user.' );
	}

	$meta_key = $type === 'artist' ? 'user_is_artist' : 'user_is_professional';
	update_user_meta( $user_id, $meta_key, '1' );

	delete_user_meta( $user_id, 'artist_access_request' );

	if ( function_exists( 'ec_send_artist_access_approval_email' ) ) {
		ec_send_artist_access_approval_email( $user );
	}

	wp_safe_redirect( admin_url( 'tools.php?page=extrachill-admin-tools#artist-access-requests&approved=1' ) );
	exit;
}

/**
 * Handles admin tools button approval (POST request with JSON response).
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with result or error.
 */
function extrachill_api_artist_access_approve( $request ) {
	$user_id = $request->get_param( 'user_id' );
	$type    = $request->get_param( 'type' );

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	$meta_key = $type === 'artist' ? 'user_is_artist' : 'user_is_professional';
	update_user_meta( $user_id, $meta_key, '1' );

	delete_user_meta( $user_id, 'artist_access_request' );

	if ( function_exists( 'ec_send_artist_access_approval_email' ) ) {
		ec_send_artist_access_approval_email( $user );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => 'User approved successfully',
		)
	);
}

/**
 * Handles admin tools button rejection (POST request with JSON response).
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with result or error.
 */
function extrachill_api_artist_access_reject( $request ) {
	$user_id = $request->get_param( 'user_id' );

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	delete_user_meta( $user_id, 'artist_access_request' );

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => 'Request rejected',
		)
	);
}
