<?php
/**
 * Artist Analytics REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id}/analytics - Retrieve link page analytics
 *
 * Replaces legacy /analytics/link-page endpoint with artist-centric routing.
 * Automatically resolves link page from artist ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_analytics_route' );

function extrachill_api_register_artist_analytics_route() {
	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/analytics', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_artist_analytics_handler',
		'permission_callback' => 'extrachill_api_artist_analytics_permission_check',
		'args'                => array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'date_range' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 30,
				'sanitize_callback' => 'absint',
			),
		),
	) );
}

/**
 * Permission check for artist analytics endpoint
 */
function extrachill_api_artist_analytics_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
		);
	}

	$artist_id = $request->get_param( 'id' );

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			'Artist not found.',
			array( 'status' => 404 )
		);
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Artist platform not active.',
			array( 'status' => 500 )
		);
	}

	if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'rest_forbidden',
			'Cannot access analytics for this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * GET handler - retrieve artist link page analytics
 */
function extrachill_api_artist_analytics_handler( WP_REST_Request $request ) {
	$artist_id  = $request->get_param( 'id' );
	$date_range = $request->get_param( 'date_range' );

	if ( ! function_exists( 'ec_get_link_page_for_artist' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Artist platform not active.',
			array( 'status' => 500 )
		);
	}

	$link_page_id = ec_get_link_page_for_artist( $artist_id );

	if ( ! $link_page_id ) {
		return new WP_Error(
			'no_link_page',
			'No link page exists for this artist.',
			array( 'status' => 404 )
		);
	}

	/**
	 * Retrieve link page analytics via filter hook.
	 *
	 * @param mixed $result       Previous filter result (null if no handler).
	 * @param int   $link_page_id The link page post ID.
	 * @param int   $date_range   Number of days to query.
	 */
	$result = apply_filters( 'extrachill_get_link_page_analytics', null, $link_page_id, $date_range );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new WP_Error(
			'analytics_unavailable',
			'Analytics data could not be retrieved.',
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( $result );
}
