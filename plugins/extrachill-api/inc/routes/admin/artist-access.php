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
	// Uses HMAC token auth instead of cookie auth because email links
	// are plain GET requests without a WP nonce — the REST API won't
	// trust the cookie, so current_user_can() always returns false.
	register_rest_route(
		'extrachill/v1',
		'/admin/artist-access/(?P<user_id>\d+)/approve',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_artist_access_email_approve',
			'permission_callback' => 'extrachill_api_artist_access_email_check',
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
 * Permission check for one-click email approval links.
 *
 * Validates the HMAC token from the email instead of relying on cookie auth.
 * Cookie auth fails for email links because GET requests to the REST API
 * don't include a WP nonce, so WordPress treats them as unauthenticated.
 *
 * @param WP_REST_Request $request The REST request.
 * @return bool|WP_Error True if token is valid, WP_Error otherwise.
 */
function extrachill_api_artist_access_email_check( $request ) {
	$user_id = $request->get_param( 'user_id' );
	$token   = $request->get_param( 'token' );

	if ( empty( $token ) || empty( $user_id ) ) {
		return new WP_Error(
			'rest_forbidden',
			'Missing approval token.',
			array( 'status' => 403 )
		);
	}

	$token_data = extrachill_api_validate_artist_access_token( $token, absint( $user_id ) );
	if ( ! $token_data ) {
		return new WP_Error(
			'rest_forbidden',
			'Invalid or expired approval link.',
			array( 'status' => 403 )
		);
	}

	return true;
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
 * Wraps the extrachill/list-artist-access-requests ability from extrachill-users.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with request list or error.
 */
function extrachill_api_get_artist_access_requests( $request ) {
	$ability = wp_get_ability( 'extrachill/list-artist-access-requests' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
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
 * Wraps the extrachill/approve-artist-access ability from extrachill-users.
 * Token validation happens in the permission callback (extrachill_api_artist_access_email_check).
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response or redirect.
 */
function extrachill_api_artist_access_email_approve( $request ) {
	$user_id = $request->get_param( 'user_id' );
	$type    = $request->get_param( 'type' );

	$ability = wp_get_ability( 'extrachill/approve-artist-access' );
	if ( ! $ability ) {
		wp_die( 'Artist access system unavailable. Please use the admin tools page.' );
	}

	$result = $ability->execute(
		array(
			'user_id' => absint( $user_id ),
			'type'    => $type,
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ) );
	}

	if ( ! empty( $result['skipped'] ) ) {
		wp_safe_redirect( admin_url( 'tools.php?page=extrachill-admin-tools#artist-access-requests&already_approved=1' ) );
		exit;
	}

	wp_safe_redirect( admin_url( 'tools.php?page=extrachill-admin-tools#artist-access-requests&approved=1' ) );
	exit;
}

/**
 * Handles admin tools button approval (POST request with JSON response).
 *
 * Wraps the extrachill/approve-artist-access ability from extrachill-users.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with result or error.
 */
function extrachill_api_artist_access_approve( $request ) {
	$ability = wp_get_ability( 'extrachill/approve-artist-access' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id' => absint( $request->get_param( 'user_id' ) ),
			'type'    => sanitize_text_field( $request->get_param( 'type' ) ),
		)
	);

	if ( is_wp_error( $result ) ) {
		$status = 'user_not_found' === $result->get_error_code() ? 404 : 400;
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
	}

	return rest_ensure_response( $result );
}

/**
 * Handles admin tools button rejection (POST request with JSON response).
 *
 * Wraps the extrachill/reject-artist-access ability from extrachill-users.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with result or error.
 */
function extrachill_api_artist_access_reject( $request ) {
	$ability = wp_get_ability( 'extrachill/reject-artist-access' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'user_id' => absint( $request->get_param( 'user_id' ) ),
		)
	);

	if ( is_wp_error( $result ) ) {
		$status = 'user_not_found' === $result->get_error_code() ? 404 : 400;
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
	}

	return rest_ensure_response( $result );
}
