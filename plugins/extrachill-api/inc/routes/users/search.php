<?php
/**
 * User Search REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/users/search - Search users by term
 *
 * Contexts:
 * - mentions (default): Logged-in only, lightweight response for @mentions autocomplete
 * - admin: Admin-only, full user data for relationship management
 * - artist-capable: Users who can create artist profiles (for roster invites)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_search_routes' );

function extrachill_api_register_user_search_routes() {
	register_rest_route( 'extrachill/v1', '/users/search', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_user_search_handler',
		'permission_callback' => 'extrachill_api_user_search_permission_check',
		'args'                => array(
			'term'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'context' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'mentions',
				'enum'              => array( 'mentions', 'admin', 'artist-capable' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'exclude_artist_id' => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );
}

	/**
	 * Permission check for user search endpoint
	 *
	 * - mentions context: Requires logged-in user
	 * - admin context: Requires manage_options capability
	 * - artist-capable context: Requires logged-in user who can create artist profiles
	 */
	function extrachill_api_user_search_permission_check( WP_REST_Request $request ) {
		$context = $request->get_param( 'context' );

		if ( $context === 'mentions' && ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				'Must be logged in.',
				array( 'status' => 401 )
			);
		}

		if ( $context === 'admin' ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'rest_forbidden',
					'Admin access required.',
					array( 'status' => 403 )
				);
			}
		}

		if ( $context === 'artist-capable' ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error(
					'rest_forbidden',
					'Must be logged in.',
					array( 'status' => 401 )
				);
			}

			if ( ! function_exists( 'ec_can_create_artist_profiles' ) || ! ec_can_create_artist_profiles( get_current_user_id() ) ) {
				return new WP_Error(
					'rest_forbidden',
					'Cannot manage artist profiles.',
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

/**
 * Search handler - find users by term
 */
function extrachill_api_user_search_handler( WP_REST_Request $request ) {
	$term    = $request->get_param( 'term' );
	$context = $request->get_param( 'context' );

	if ( empty( $term ) ) {
		return new WP_Error(
			'missing_search_term',
			'Search term is required.',
			array( 'status' => 400 )
		);
	}

	// Require minimum 2 characters for admin and artist-capable contexts
	if ( in_array( $context, array( 'admin', 'artist-capable' ), true ) && strlen( $term ) < 2 ) {
		return rest_ensure_response( array() );
	}

	// Handle artist-capable context separately due to meta filtering
	if ( $context === 'artist-capable' ) {
		return extrachill_api_search_artist_capable_users( $request );
	}

	$search_columns = array( 'user_login', 'user_nicename' );
	$number         = 10;

	// Admin context searches more columns and returns more results
	if ( $context === 'admin' ) {
		$search_columns = array( 'user_login', 'user_email', 'display_name' );
		$number         = 20;
	}

	$users_query = new WP_User_Query( array(
		'search'         => '*' . esc_attr( $term ) . '*',
		'search_columns' => $search_columns,
		'number'         => $number,
		'orderby'        => 'display_name',
		'order'          => 'ASC',
	) );

	$users_data = array();

	foreach ( $users_query->get_results() as $user ) {
		if ( $context === 'admin' ) {
			$users_data[] = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			);
		} else {
			// Mentions context - lightweight response
			$profile_url = function_exists( 'extrachill_get_user_profile_url' )
				? extrachill_get_user_profile_url( $user->ID, $user->user_email )
				: '';

			$users_data[] = array(
				'id'          => $user->ID,
				'username'    => $user->user_login,
				'slug'        => $user->user_nicename,
				'avatar_url'  => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
				'profile_url' => $profile_url,
			);
		}
	}

	return rest_ensure_response( $users_data );
}

/**
 * Search for users who can create artist profiles
 *
 * Filters results to users with:
 * - user_is_artist meta = '1' OR
 * - user_is_professional meta = '1' OR
 * - Team member status (ec_is_team_member)
 *
 * Excludes users who are already roster members of the specified artist.
 */
function extrachill_api_search_artist_capable_users( WP_REST_Request $request ) {
	$term              = $request->get_param( 'term' );
	$exclude_artist_id = $request->get_param( 'exclude_artist_id' );

	// Get existing roster member IDs to exclude
	$exclude_user_ids = array();
	if ( $exclude_artist_id && function_exists( 'ec_get_linked_members' ) ) {
		$linked_members = ec_get_linked_members( $exclude_artist_id );
		if ( is_array( $linked_members ) ) {
			foreach ( $linked_members as $member ) {
				$exclude_user_ids[] = (int) $member->ID;
			}
		}
	}

	// Search users by term
	$users_query = new WP_User_Query( array(
		'search'         => '*' . esc_attr( $term ) . '*',
		'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
		'number'         => 50,
		'orderby'        => 'display_name',
		'order'          => 'ASC',
	) );

	$users_data = array();
	$count      = 0;
	$limit      = 10;

	foreach ( $users_query->get_results() as $user ) {
		if ( $count >= $limit ) {
			break;
		}

		// Skip if already a roster member
		if ( in_array( $user->ID, $exclude_user_ids, true ) ) {
			continue;
		}

		// Check if user can create artist profiles
		$is_artist       = get_user_meta( $user->ID, 'user_is_artist', true ) === '1';
		$is_professional = get_user_meta( $user->ID, 'user_is_professional', true ) === '1';
		$is_team_member  = function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user->ID );

		if ( ! $is_artist && ! $is_professional && ! $is_team_member ) {
			continue;
		}

		// Build profile URL
		$profile_url = function_exists( 'extrachill_get_user_profile_url' )
			? extrachill_get_user_profile_url( $user->ID, $user->user_email )
			: '';

		$users_data[] = array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			'profile_url'  => $profile_url,
		);

		$count++;
	}

	return rest_ensure_response( $users_data );
}
