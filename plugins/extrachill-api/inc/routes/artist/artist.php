<?php
/**
 * Artist REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id} - Retrieve core artist data
 * PUT /wp-json/extrachill/v1/artists/{id} - Update core artist data (partial updates supported)
 *
 * Core artist data includes name, bio, images, and link_page_id.
 * Images are managed via the /media endpoint, not here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_routes' );

function extrachill_api_register_artist_routes() {
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
			'Cannot manage this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * GET handler - retrieve core artist data
 */
function extrachill_api_artist_get_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );

	return rest_ensure_response( extrachill_api_build_artist_response( $artist_id ) );
}

/**
 * PUT handler - update core artist data (partial updates)
 */
function extrachill_api_artist_put_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$body      = $request->get_json_params();

	if ( empty( $body ) ) {
		return new WP_Error(
			'empty_body',
			'Request body is empty.',
			array( 'status' => 400 )
		);
	}

	$post_data = array( 'ID' => $artist_id );
	$has_updates = false;

	// Update name (post_title)
	if ( isset( $body['name'] ) ) {
		$post_data['post_title'] = sanitize_text_field( wp_unslash( $body['name'] ) );
		$has_updates = true;
	}

	// Update bio (post_content)
	if ( isset( $body['bio'] ) ) {
		$post_data['post_content'] = wp_kses_post( wp_unslash( $body['bio'] ) );
		$has_updates = true;
	}

	// Perform post update if we have changes
	if ( $has_updates ) {
		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'update_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}
	}

	return rest_ensure_response( extrachill_api_build_artist_response( $artist_id ) );
}

/**
 * Build artist response data
 */
function extrachill_api_build_artist_response( $artist_id ) {
	$artist = get_post( $artist_id );

	// Profile image
	$profile_image_id  = get_post_thumbnail_id( $artist_id );
	$profile_image_url = $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'medium' ) : null;

	// Header image
	$header_image_id  = get_post_meta( $artist_id, '_artist_profile_header_image_id', true );
	$header_image_url = $header_image_id ? wp_get_attachment_image_url( (int) $header_image_id, 'large' ) : null;

	// Link page ID
	$link_page_id = null;
	if ( function_exists( 'ec_get_link_page_for_artist' ) ) {
		$link_page_id = ec_get_link_page_for_artist( $artist_id );
	}

	return array(
		'id'                => (int) $artist_id,
		'name'              => $artist->post_title,
		'slug'              => $artist->post_name,
		'bio'               => $artist->post_content,
		'profile_image_id'  => $profile_image_id ? (int) $profile_image_id : null,
		'profile_image_url' => $profile_image_url,
		'header_image_id'   => $header_image_id ? (int) $header_image_id : null,
		'header_image_url'  => $header_image_url,
		'link_page_id'      => $link_page_id ? (int) $link_page_id : null,
	);
}
