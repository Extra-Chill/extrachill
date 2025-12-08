<?php
/**
 * Join Flow - Complete Join Flow System
 *
 * Handles all join flow operations:
 * - Modal rendering via template action hook
 * - User registration → artist profile creation → link page creation
 * - Existing user login redirects
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render join flow modal via community plugin action hook
 */
function ec_render_join_flow_modal() {
	require EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/join/templates/join-flow-modal.php';
}
add_action( 'extrachill_below_login_register_form', 'ec_render_join_flow_modal' );

/**
 * Detects if the current user registration came from the join flow
 *
 * @return bool True if join flow registration, false otherwise
 */
function ec_is_join_flow_registration() {
	return isset( $_POST['from_join'] ) && $_POST['from_join'] === 'true';
}

/**
 * Main join flow handler - processes user registration for join flow users
 *
 * Creates artist profile and link page automatically when users register via join flow.
 * Hooked to 'user_register' action.
 *
 * @param int $user_id The ID of the newly registered user
 */
function ec_handle_join_flow_user_registration( $user_id ) {
	// Only handle join flow registrations
	if ( ! ec_is_join_flow_registration() ) {
		return;
	}

	// Validate user exists
	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return;
	}

	// Create artist profile using the user's display name or username
	$artist_title = ! empty( $user->display_name ) ? $user->display_name : $user->user_login;

	$artist_data = array(
		'post_type'   => 'artist_profile',
		'post_status' => 'publish',
		'post_author' => $user_id,
		'post_title'  => sanitize_text_field( $artist_title ),
		'post_content' => '', // Empty bio initially
	);

	$artist_id = wp_insert_post( $artist_data, true );

	if ( is_wp_error( $artist_id ) || ! $artist_id ) {
		error_log( '[Join Flow] Failed to create artist profile for user ID: ' . $user_id );
		return;
	}

	// Link user as member of their own artist profile
	if ( function_exists( 'bp_add_artist_membership' ) ) {
		bp_add_artist_membership( $user_id, $artist_id );
	}

	// Forum is automatically created via save_post_artist_profile hook

	// Create link page using centralized creation system
	$link_page_result = ec_create_link_page( $artist_id );

	if ( is_wp_error( $link_page_result ) ) {
		error_log( '[Join Flow] Link page creation failed for artist ID: ' . $artist_id . ', Error: ' . $link_page_result->get_error_message() );
		$link_page_id = 0;
	} else {
		$link_page_id = $link_page_result;
		error_log( '[Join Flow] Successfully created artist profile and link page for user ID: ' . $user_id . ', Artist ID: ' . $artist_id . ', Link Page ID: ' . $link_page_id );
	}

	// Store join flow data for post-registration redirect
	set_transient( 'join_flow_completion_' . $user_id, array(
		'artist_id' => $artist_id,
		'link_page_id' => $link_page_id,
		'completed_at' => time()
	), HOUR_IN_SECONDS );
}
add_action( 'user_register', 'ec_handle_join_flow_user_registration', 10, 1 );

/**
 * Get the appropriate redirect URL after join flow completion
 *
 * @param int $user_id The user ID
 * @param int $artist_id The created artist profile ID
 * @return string The redirect URL
 */
function ec_get_join_flow_redirect_url( $user_id, $artist_id ) {
	$link_page = get_page_by_path( 'manage-link-page' );
	$manage_link_page_url = $link_page ? get_permalink( $link_page ) : home_url( '/manage-link-page/' );

	$redirect_url = add_query_arg( array(
		'from_join' => 'true'
	), $manage_link_page_url );

	/**
	 * Filters the redirect URL after join flow completion
	 *
	 * @param string $redirect_url The default redirect URL
	 * @param int    $user_id      The user ID
	 * @param int    $artist_id    The created artist profile ID
	 */
	return apply_filters( 'ec_join_flow_redirect_url', $redirect_url, $user_id, $artist_id );
}

/**
 * Validates join flow registration requirements
 *
 * Currently a placeholder for future validation needs.
 * Theme handles checkbox validation via JavaScript.
 *
 * @param WP_Error $errors               Registration error object
 * @param string   $sanitized_user_login User login after sanitization
 * @param string   $user_email           User email
 * @return WP_Error Modified errors object
 */
function ec_validate_join_flow_requirements( $errors, $sanitized_user_login, $user_email ) {
	// Join flow validation is currently handled by theme JavaScript
	// This function is available for future server-side validation needs

	if ( ec_is_join_flow_registration() ) {
		// Future: Add any server-side validation for join flow registrations

		/**
		 * Allows plugins to add join flow validation errors
		 *
		 * @param WP_Error $errors               Current registration errors
		 * @param string   $sanitized_user_login Sanitized user login
		 * @param string   $user_email           User email
		 */
		$errors = apply_filters( 'ec_join_flow_validation_errors', $errors, $sanitized_user_login, $user_email );
	}

	return $errors;
}
add_filter( 'registration_errors', 'ec_validate_join_flow_requirements', 10, 3 );

/**
 * Get join flow completion data for a user
 *
 * @param int $user_id The user ID
 * @return array|false Join flow completion data or false if not found
 */
function ec_get_join_flow_completion_data( $user_id ) {
	return get_transient( 'join_flow_completion_' . $user_id );
}

/**
 * Clear join flow completion data for a user
 *
 * @param int $user_id The user ID
 */
function ec_clear_join_flow_completion_data( $user_id ) {
	delete_transient( 'join_flow_completion_' . $user_id );
}

/**
 * Handle post-registration redirect for join flow users
 *
 * Intercepts the default registration redirect and routes join flow users
 * to the manage-link-page with their newly created artist profile.
 * Hooked to 'registration_redirect' filter.
 *
 * @param string        $redirect_to Default redirect URL
 * @param int|WP_Error  $user_id     User ID if successful, WP_Error otherwise
 * @return string Modified redirect URL for join flow users, original URL otherwise
 */
function ec_handle_join_flow_registration_redirect( $redirect_to, $user_id ) {
	if ( ! $user_id || is_wp_error( $user_id ) ) {
		return $redirect_to;
	}

	// Check if this user has join flow completion data
	$completion_data = ec_get_join_flow_completion_data( $user_id );

	if ( ! $completion_data || empty( $completion_data['artist_id'] ) ) {
		// Not a join flow registration, return original redirect
		return $redirect_to;
	}

	// Get the redirect URL for join flow users
	$join_flow_redirect = ec_get_join_flow_redirect_url( $user_id, $completion_data['artist_id'] );

	// Clear the transient data after successful redirect
	ec_clear_join_flow_completion_data( $user_id );

	// Return the join flow redirect URL
	return $join_flow_redirect;
}
add_filter( 'registration_redirect', 'ec_handle_join_flow_registration_redirect', 10, 2 );

/**
 * Handle existing user page load redirect when visiting login page with from_join parameter
 *
 * Redirects already-logged-in users who land on the login page with from_join=true
 * to either their link page management or artist profile creation.
 */
function ec_join_flow_login_page_redirect() {
	if ( ! is_user_logged_in() || ! is_page( 'login' ) ) {
		return;
	}

	$from_join = isset( $_GET['from_join'] ) && $_GET['from_join'] === 'true';

	if ( ! $from_join ) {
		return;
	}

	$user_id = get_current_user_id();
	$user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );

	if ( ! empty( $user_artist_ids ) && is_array( $user_artist_ids ) ) {
		$most_recent_artist_query = new WP_Query( array(
			'post_type'      => 'artist_profile',
			'post__in'       => $user_artist_ids,
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids'
		) );

		if ( $most_recent_artist_query->have_posts() ) {
			$most_recent_artist_id = $most_recent_artist_query->posts[0];
			$link_page_manage_page = get_page_by_path( 'manage-link-page' );

			if ( $link_page_manage_page ) {
				extrachill_set_notice(
					__( 'Welcome back! Manage your link page below.', 'extrachill-artist-platform' ),
					'success'
				);
				wp_redirect( get_permalink( $link_page_manage_page ) );
				exit;
			}
		}
		wp_reset_postdata();
	} else {
		$manage_artist_page = get_page_by_path( 'manage-artist-profiles' );
		if ( $manage_artist_page ) {
			extrachill_set_notice(
				__( 'Create an artist profile to get started with your link page.', 'extrachill-artist-platform' ),
				'info'
			);
			$target_url = add_query_arg(
				array( 'from_join' => 'true' ),
				get_permalink( $manage_artist_page )
			);
			wp_redirect( $target_url );
			exit;
		}
	}
}
add_action( 'template_redirect', 'ec_join_flow_login_page_redirect' );

/**
 * Handle existing user login form submission redirect
 *
 * Intercepts the login redirect to route existing users who log in via join flow
 * to either their link page management or artist profile creation.
 *
 * @param string $redirect_to Default redirect URL
 * @param string $requested_redirect_to Requested redirect URL
 * @param WP_User $user User object
 * @return string Modified redirect URL
 */
function ec_join_flow_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! isset( $user->ID ) || $user->ID <= 0 ) {
		return $redirect_to;
	}

	$from_join = false;

	if ( isset( $_REQUEST['from_join'] ) && $_REQUEST['from_join'] === 'true' ) {
		$from_join = true;
	} elseif ( isset( $_REQUEST['redirect_to'] ) ) {
		$redirect_to_parts = parse_url( $_REQUEST['redirect_to'] );
		if ( isset( $redirect_to_parts['query'] ) ) {
			parse_str( $redirect_to_parts['query'], $query_params );
			if ( isset( $query_params['from_join'] ) && $query_params['from_join'] === 'true' ) {
				$from_join = true;
			}
		}
	}

	if ( ! $from_join ) {
		return $redirect_to;
	}

	$user_id = $user->ID;
	$user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );

	if ( ! empty( $user_artist_ids ) && is_array( $user_artist_ids ) ) {
		$most_recent_artist_query = new WP_Query( array(
			'post_type'      => 'artist_profile',
			'post__in'       => $user_artist_ids,
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids'
		) );

		if ( $most_recent_artist_query->have_posts() ) {
			$most_recent_artist_id = $most_recent_artist_query->posts[0];
			$link_page_manage_page = get_page_by_path( 'manage-link-page' );

			if ( $link_page_manage_page ) {
				extrachill_set_notice(
					__( 'Welcome back! Manage your link page below.', 'extrachill-artist-platform' ),
					'success'
				);
				return get_permalink( $link_page_manage_page );
			}
		}
		wp_reset_postdata();
	} else {
		$manage_artist_page = get_page_by_path( 'manage-artist-profiles' );
		if ( $manage_artist_page ) {
			extrachill_set_notice(
				__( 'Create an artist profile to get started with your link page.', 'extrachill-artist-platform' ),
				'info'
			);
			$target_url = add_query_arg(
				array( 'from_join' => 'true' ),
				get_permalink( $manage_artist_page )
			);
			return $target_url;
		}
	}

	return $redirect_to;
}
add_filter( 'login_redirect', 'ec_join_flow_login_redirect', 10, 3 );