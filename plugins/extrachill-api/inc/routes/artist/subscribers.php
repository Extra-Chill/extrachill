<?php
/**
 * REST routes for artist subscribers management
 *
 * GET /wp-json/extrachill/v1/artist/subscribers - Fetch paginated subscribers
 * GET /wp-json/extrachill/v1/artist/subscribers/export - Fetch all subscribers for CSV export
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_subscribers_routes' );

function extrachill_api_register_artist_subscribers_routes() {
	// Fetch paginated subscribers
	register_rest_route( 'extrachill/v1', '/artist/subscribers', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_get_artist_subscribers_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'artist_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'page'      => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
		),
	) );

	// Export all subscribers for CSV
	register_rest_route( 'extrachill/v1', '/artist/subscribers/export', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_export_artist_subscribers_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'artist_id'        => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'include_exported' => array(
				'required'          => false,
				'type'              => 'boolean',
				'default'           => false,
			),
		),
	) );
}

/**
 * Handles fetching paginated artist subscribers
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_get_artist_subscribers_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'artist_id' );
	$page      = max( 1, $request->get_param( 'page' ) );
	$per_page  = max( 1, min( 100, $request->get_param( 'per_page' ) ) );

	// Validate artist exists and is correct post type
	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	// Check permission
	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to view subscribers for this artist.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Fetch subscribers via filter hook
	 *
	 * @param mixed $result    Previous filter result (null if no handler has run).
	 * @param int   $artist_id The artist profile post ID.
	 * @param array $args      Query arguments (page, per_page).
	 */
	$args = array(
		'page'     => $page,
		'per_page' => $per_page,
	);
	$result = apply_filters( 'extrachill_get_artist_subscribers', null, $artist_id, $args );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || ! isset( $result['subscribers'] ) ) {
		return new WP_Error(
			'fetch_failed',
			__( 'Could not fetch subscriber data.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( $result );
}

/**
 * Handles exporting all artist subscribers for CSV generation
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_export_artist_subscribers_handler( WP_REST_Request $request ) {
	$artist_id        = $request->get_param( 'artist_id' );
	$include_exported = $request->get_param( 'include_exported' );

	// Validate artist exists and is correct post type
	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	// Check permission
	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to export subscribers for this artist.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Export subscribers via filter hook
	 *
	 * @param mixed $result           Previous filter result (null if no handler has run).
	 * @param int   $artist_id        The artist profile post ID.
	 * @param bool  $include_exported Whether to include already exported subscribers.
	 */
	$result = apply_filters( 'extrachill_export_artist_subscribers', null, $artist_id, $include_exported );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || ! isset( $result['subscribers'] ) ) {
		return new WP_Error(
			'export_failed',
			__( 'Could not export subscriber data.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( $result );
}
