<?php
/**
 * Artist Access Management REST API Endpoints
 *
 * Handles artist platform access approval and rejection via REST API.
 * Replaces legacy admin-ajax handlers with modern REST endpoints.
 *
 * Email approvals are handled by Data Machine pending actions, not this API.
 *
 * @endpoint GET  /wp-json/extrachill/v1/admin/artist-access (pending requests)
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
 * Wraps the extrachill/list-artist-access-requests ability from extrachill-users.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with request list or error.
 */
function extrachill_api_get_artist_access_requests( $request ) {
	unset( $request );
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
		return $result;
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
		return $result;
	}

	return rest_ensure_response( $result );
}
