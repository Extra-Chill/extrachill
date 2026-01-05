<?php
/**
 * User REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/users/{id} - Retrieve user profile data
 *
 * Permission model:
 * - Own profile or admin: Full data access
 * - Other logged-in users: Limited public fields
 * - Not logged in: 401 error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_routes' );

function extrachill_api_register_user_routes() {
	register_rest_route( 'extrachill/v1', '/users/(?P<id>\d+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_user_get_handler',
		'permission_callback' => 'extrachill_api_user_permission_check',
		'args'                => array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );
}

/**
 * Permission check for user endpoint
 */
function extrachill_api_user_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
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
 * GET handler - retrieve user profile data
 */
function extrachill_api_user_get_handler( WP_REST_Request $request ) {
	$user_id      = $request->get_param( 'id' );
	$current_user = get_current_user_id();
	$is_own       = $current_user === $user_id;
	$is_admin     = current_user_can( 'manage_options' );

	$user = get_userdata( $user_id );

	$response = extrachill_api_build_user_response( $user, $is_own || $is_admin );

	return rest_ensure_response( $response );
}

/**
 * Build user response data
 *
 * @param WP_User $user      User object
 * @param bool    $full_data Whether to include all fields or just public fields
 * @return array
 */
function extrachill_api_build_user_response( $user, $full_data = false ) {
	$user_id = $user->ID;

	// Avatar URL
	$avatar_url = get_avatar_url( $user_id, array( 'size' => 96 ) );

	// Profile URL (uses extrachill-users canonical function if available)
	$profile_url = function_exists( 'ec_get_user_profile_url' )
		? ec_get_user_profile_url( $user_id, $user->user_email )
		: get_author_posts_url( $user_id );

	// Team member status
	$is_team_member = function_exists( 'ec_is_team_member' )
		? ec_is_team_member( $user_id )
		: false;

	// Last active timestamp
	$last_active = get_user_meta( $user_id, 'last_active', true );

	// Public fields (available to all logged-in users)
	$response = array(
		'id'             => $user_id,
		'display_name'   => $user->display_name,
		'username'       => $user->user_login,
		'slug'           => $user->user_nicename,
		'avatar_url'     => $avatar_url,
		'profile_url'    => $profile_url,
		'is_team_member' => $is_team_member,
		'last_active'    => $last_active ? (int) $last_active : null,
	);

	// Extended fields (own profile or admin only)
	if ( $full_data ) {
		// Lifetime membership
		$membership_data       = get_user_meta( $user_id, 'extrachill_lifetime_membership', true );
		$is_lifetime_member    = ! empty( $membership_data );

		// Artist/professional status
		$is_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
		$is_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';

		// Can create artists
		$can_create_artists = function_exists( 'ec_can_create_artist_profiles' )
			? ec_can_create_artist_profiles( $user_id )
			: false;

		// Artist count
		$artist_count = 0;
		if ( function_exists( 'ec_get_artists_for_user' ) ) {
			$artists      = ec_get_artists_for_user( $user_id );
			$artist_count = count( $artists );
		}

		$response['email']               = $user->user_email;
		$response['is_lifetime_member']  = $is_lifetime_member;
		$response['is_artist']           = $is_artist;
		$response['is_professional']     = $is_professional;
		$response['can_create_artists']  = $can_create_artists;
		$response['artist_count']        = $artist_count;
		$response['registered']          = mysql2date( 'c', $user->user_registered );
	}

	return $response;
}
