<?php
/**
 * REST routes for artist subscribers management
 *
 * GET /wp-json/extrachill/v1/artists/{id}/subscribers - Fetch paginated subscribers
 * GET /wp-json/extrachill/v1/artists/{id}/subscribers/export - Fetch all subscribers for CSV export
 *
 * Delegates to abilities:
 * - extrachill/artist-list-subscribers
 * - extrachill/artist-export-subscribers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_subscribers_routes' );

function extrachill_api_register_artist_subscribers_routes() {
	// Fetch paginated subscribers
	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/subscribers', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_get_artist_subscribers_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'       => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
		),
	) );

	// Export all subscribers for CSV
	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/subscribers/export', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_export_artist_subscribers_handler',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'               => array(
				'required'          => true,
				'type'              => 'integer',
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
 * Handles fetching paginated artist subscribers via ability.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_get_artist_subscribers_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/artist-list-subscribers' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$input = array( 'id' => $request->get_param( 'id' ) );

	$page = $request->get_param( 'page' );
	if ( $page !== null ) {
		$input['page'] = $page;
	}

	$per_page = $request->get_param( 'per_page' );
	if ( $per_page !== null ) {
		$input['per_page'] = $per_page;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handles exporting all artist subscribers for CSV generation via ability.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_export_artist_subscribers_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/artist-export-subscribers' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$input = array( 'id' => $request->get_param( 'id' ) );

	$include_exported = $request->get_param( 'include_exported' );
	if ( $include_exported !== null ) {
		$input['include_exported'] = $include_exported;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
