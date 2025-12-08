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
 * POST handler - create artist
 */
function extrachill_api_artist_post_handler( WP_REST_Request $request ) {
	$current_user = get_current_user_id();
	$name         = $request->get_param( 'name' );
	$bio          = $request->get_param( 'bio' );
	$local_city   = $request->get_param( 'local_city' );
	$genre        = $request->get_param( 'genre' );

	$post_data = array(
		'post_title'   => sanitize_text_field( wp_unslash( $name ) ),
		'post_content' => $bio ? wp_kses_post( wp_unslash( $bio ) ) : '',
		'post_type'    => 'artist_profile',
		'post_status'  => 'publish',
		'post_author'  => $current_user,
	);

	$artist_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $artist_id ) ) {
		return new WP_Error(
			'create_failed',
			$artist_id->get_error_message(),
			array( 'status' => 500 )
		);
	}

	if ( $local_city !== null ) {
		$city_value = sanitize_text_field( wp_unslash( $local_city ) );
		if ( $city_value !== '' ) {
			update_post_meta( $artist_id, '_local_city', $city_value );
		}
	}

	if ( $genre !== null ) {
		$genre_value = sanitize_text_field( wp_unslash( $genre ) );
		if ( $genre_value !== '' ) {
			update_post_meta( $artist_id, '_genre', $genre_value );
		}
	}

	// Link user to artist
	if ( function_exists( 'bp_add_artist_membership' ) ) {
		bp_add_artist_membership( $current_user, $artist_id );
	}

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

	// Update local city
	if ( array_key_exists( 'local_city', $body ) ) {
		$local_city = sanitize_text_field( wp_unslash( $body['local_city'] ) );
		if ( $local_city === '' ) {
			delete_post_meta( $artist_id, '_local_city' );
		} else {
			update_post_meta( $artist_id, '_local_city', $local_city );
		}
	}

	// Update genre
	if ( array_key_exists( 'genre', $body ) ) {
		$genre = sanitize_text_field( wp_unslash( $body['genre'] ) );
		if ( $genre === '' ) {
			delete_post_meta( $artist_id, '_genre' );
		} else {
			update_post_meta( $artist_id, '_genre', $genre );
		}
	}

	// Update profile image association
	if ( array_key_exists( 'profile_image_id', $body ) ) {
		$profile_image_id = absint( $body['profile_image_id'] );
		if ( $profile_image_id > 0 ) {
			set_post_thumbnail( $artist_id, $profile_image_id );
		} else {
			delete_post_thumbnail( $artist_id );
		}
	}

	// Update header image association
	if ( array_key_exists( 'header_image_id', $body ) ) {
		$header_image_id = absint( $body['header_image_id'] );
		if ( $header_image_id > 0 ) {
			update_post_meta( $artist_id, '_artist_profile_header_image_id', $header_image_id );
		} else {
			delete_post_meta( $artist_id, '_artist_profile_header_image_id' );
		}
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

	$local_city = get_post_meta( $artist_id, '_local_city', true );
	$genre      = get_post_meta( $artist_id, '_genre', true );

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
		'local_city'        => $local_city !== '' ? $local_city : null,
		'genre'             => $genre !== '' ? $genre : null,
		'profile_image_id'  => $profile_image_id ? (int) $profile_image_id : null,
		'profile_image_url' => $profile_image_url,
		'header_image_id'   => $header_image_id ? (int) $header_image_id : null,
		'header_image_url'  => $header_image_url,
		'link_page_id'      => $link_page_id ? (int) $link_page_id : null,
	);
}
