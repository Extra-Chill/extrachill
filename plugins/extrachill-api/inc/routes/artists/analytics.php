<?php
/**
 * Artist Analytics REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id}/analytics - Retrieve link page analytics
 *
 * Delegates to ability:
 * - extrachill/artist-get-analytics
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
 * GET handler - retrieve artist link page analytics via ability.
 */
function extrachill_api_artist_analytics_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/artist-get-analytics' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$input = array( 'id' => $request->get_param( 'id' ) );

	$date_range = $request->get_param( 'date_range' );
	if ( $date_range !== null ) {
		$input['date_range'] = $date_range;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
