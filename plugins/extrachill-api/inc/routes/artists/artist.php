<?php
/**
 * Artist REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id} - Retrieve core artist data
 * POST /wp-json/extrachill/v1/artists - Create a new artist profile
 * PUT /wp-json/extrachill/v1/artists/{id} - Update core artist data (partial updates supported)
 *
 * Delegates to abilities:
 * - extrachill/get-artist-data
 * - extrachill/create-artist
 * - extrachill/update-artist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_routes' );

function extrachill_api_register_artist_routes() {
	register_rest_route( 'extrachill/v1', '/artists', array(
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_artist_post_handler',
			'permission_callback' => 'extrachill_api_artist_create_permission_check',
			'args'                => array(
				'name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'bio' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
				'local_city' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'genre' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		),
	) );

	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_artist_get_handler',
			'permission_callback' => 'extrachill_api_artist_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'extrachill_api_artist_put_handler',
			'permission_callback' => 'extrachill_api_artist_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
	) );
}

/**
 * Permission check for artist creation
 */
function extrachill_api_artist_create_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
		);
	}

	if ( ! function_exists( 'ec_can_create_artist_profiles' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Artist platform not active.',
			array( 'status' => 500 )
		);
	}

	if ( ! ec_can_create_artist_profiles( get_current_user_id() ) ) {
		return new WP_Error(
			'rest_forbidden',
			'Cannot create artist profiles.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Permission check for artist endpoints
 */
function extrachill_api_artist_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
		);
	}

	$artist_id = $request->get_param( 'id' );

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error(
			'dependency_missing',
			'Multisite not configured.',
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $artist_blog_id );
	$post_type = get_post_type( $artist_id );
	restore_current_blog();

	if ( $post_type !== 'artist_profile' ) {
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
			'Cannot manage this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * GET handler - retrieve core artist data via ability.
 */
function extrachill_api_artist_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-artist-data' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Artist data ability not available.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'artist_id' => $request->get_param( 'id' ) ) );

	if ( is_wp_error( $result ) ) {
		$status = $result->get_error_code() === 'invalid_artist' ? 404 : 500;
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
	}

	return rest_ensure_response( $result );
}

/**
 * POST handler - create artist via ability.
 */
function extrachill_api_artist_post_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/create-artist' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Create artist ability not available.', array( 'status' => 500 ) );
	}

	$input = array( 'name' => $request->get_param( 'name' ) );

	$optional = array( 'bio', 'local_city', 'genre' );
	foreach ( $optional as $field ) {
		$value = $request->get_param( $field );
		if ( $value !== null ) {
			$input[ $field ] = $value;
		}
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		$status = $result->get_error_code() === 'invalid_artist_name' ? 400 : 500;
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
	}

	return rest_ensure_response( $result );
}

/**
 * PUT handler - update core artist data via ability.
 */
function extrachill_api_artist_put_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/update-artist' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_missing', 'Update artist ability not available.', array( 'status' => 500 ) );
	}

	$body = $request->get_json_params();

	if ( empty( $body ) ) {
		return new WP_Error( 'empty_body', 'Request body is empty.', array( 'status' => 400 ) );
	}

	$input = array( 'artist_id' => $request->get_param( 'id' ) );

	$fields = array( 'name', 'bio', 'local_city', 'genre', 'profile_image_id', 'header_image_id' );
	foreach ( $fields as $field ) {
		if ( array_key_exists( $field, $body ) ) {
			$input[ $field ] = $body[ $field ];
		}
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		$status = $result->get_error_code() === 'invalid_artist' ? 404 : 500;
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
	}

	return rest_ensure_response( $result );
}
