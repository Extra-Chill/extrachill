<?php
/**
 * User Artist Relationships REST API Endpoints
 *
 * GET /wp-json/extrachill/v1/users/{id}/artists - List user's managed artists
 * POST /wp-json/extrachill/v1/users/{id}/artists - Add artist relationship (admin only)
 * DELETE /wp-json/extrachill/v1/users/{id}/artists/{artist_id} - Remove relationship (admin only)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_artists_routes' );

function extrachill_api_register_user_artists_routes() {
	// GET and POST for user artists list
	register_rest_route(
		'extrachill/v1',
		'/users/(?P<id>\d+)/artists',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_user_artists_get_handler',
				'permission_callback' => 'extrachill_api_user_artists_read_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_user_artists_post_handler',
				'permission_callback' => 'extrachill_api_user_artists_admin_permission_check',
				'args'                => array(
					'id'        => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'artist_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);

	// DELETE for specific artist relationship
	register_rest_route(
		'extrachill/v1',
		'/users/(?P<id>\d+)/artists/(?P<artist_id>\d+)',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'extrachill_api_user_artists_delete_handler',
			'permission_callback' => 'extrachill_api_user_artists_admin_permission_check',
			'args'                => array(
				'id'        => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Permission check for reading user artists (own profile or admin)
 */
function extrachill_api_user_artists_read_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
		);
	}

	$user_id      = $request->get_param( 'id' );
	$current_user = get_current_user_id();

	// Allow own profile or admin
	if ( $current_user !== $user_id && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'Cannot view other users\' artists.',
			array( 'status' => 403 )
		);
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	return true;
}

/**
 * Permission check for modifying user artists (admin only)
 */
function extrachill_api_user_artists_admin_permission_check( WP_REST_Request $request ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'Admin access required.',
			array( 'status' => 403 )
		);
	}

	$user_id = $request->get_param( 'id' );
	$user    = get_userdata( $user_id );

	if ( ! $user ) {
		return new WP_Error(
			'user_not_found',
			'User not found.',
			array( 'status' => 404 )
		);
	}

	return true;
}

/**
 * GET handler - list user's managed artists
 */
function extrachill_api_user_artists_get_handler( WP_REST_Request $request ) {
	$user_id = $request->get_param( 'id' );

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Users plugin not active.',
			array( 'status' => 500 )
		);
	}

	$artist_ids = ec_get_artists_for_user( $user_id );
	$artists    = array();

	foreach ( $artist_ids as $artist_id ) {
		$identity = ec_get_artist_identity( array( 'profile_id' => (int) $artist_id ) );
		if ( null === $identity ) {
			continue;
		}

		// Null without a featured image; false when the image no longer resolves.
		$profile_image_url = 0 < $identity['thumbnail_id']
			? ( '' !== $identity['thumbnail_url'] ? $identity['thumbnail_url'] : false )
			: null;

		$artists[] = array(
			'id'                => (int) $artist_id,
			'name'              => $identity['name'],
			'slug'              => $identity['slug'],
			'profile_image_url' => $profile_image_url,
		);
	}

	return rest_ensure_response( $artists );
}

/**
 * POST handler - add artist relationship
 */
function extrachill_api_user_artists_post_handler( WP_REST_Request $request ) {
	$user_id   = $request->get_param( 'id' );
	$artist_id = $request->get_param( 'artist_id' );

	if ( ! $artist_id ) {
		return new WP_Error(
			'missing_artist_id',
			'artist_id is required.',
			array( 'status' => 400 )
		);
	}

	// Verify artist exists, in any post status.
	$artist_exists = null !== ec_get_artist_identity( array( 'profile_id' => (int) $artist_id ) );

	if ( ! $artist_exists ) {
		return new WP_Error(
			'invalid_artist',
			'Artist not found.',
			array( 'status' => 404 )
		);
	}

	if ( ! function_exists( 'ec_add_artist_membership' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Artist platform not active.',
			array( 'status' => 500 )
		);
	}

	$result = ec_add_artist_membership( $user_id, $artist_id );

	if ( ! $result ) {
		return new WP_Error(
			'relationship_failed',
			'Failed to add relationship or already exists.',
			array( 'status' => 400 )
		);
	}

	return rest_ensure_response(
		array(
			'success'   => true,
			'message'   => 'Artist relationship added.',
			'user_id'   => $user_id,
			'artist_id' => $artist_id,
		)
	);
}

/**
 * DELETE handler - remove artist relationship
 */
function extrachill_api_user_artists_delete_handler( WP_REST_Request $request ) {
	$user_id   = $request->get_param( 'id' );
	$artist_id = $request->get_param( 'artist_id' );

	if ( ! function_exists( 'ec_remove_artist_membership' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Artist platform not active.',
			array( 'status' => 500 )
		);
	}

	$result = ec_remove_artist_membership( $user_id, $artist_id );

	if ( ! $result ) {
		return new WP_Error(
			'relationship_failed',
			'Failed to remove relationship.',
			array( 'status' => 400 )
		);
	}

	return rest_ensure_response(
		array(
			'success'   => true,
			'message'   => 'Artist relationship removed.',
			'user_id'   => $user_id,
			'artist_id' => $artist_id,
		)
	);
}
