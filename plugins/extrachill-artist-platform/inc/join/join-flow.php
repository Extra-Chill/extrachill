<?php
/**
 * Join Flow - Simplified Join Flow System
 *
 * Handles join flow operations:
 * - Modal rendering via template action hook
 * - Redirect logic for login/registration via from_join parameter
 *
 * Flow:
 * - New users registering via join flow → /create-artist/
 * - Existing users with no artists → /create-artist/
 * - Existing users with artists → /manage-link-page/
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render join flow modal via community plugin action hook
 *
 * Only renders when from_join parameter is present to prevent
 * unstyled modal HTML from appearing on regular login pages.
 */
function ec_render_join_flow_modal() {
	if ( ! isset( $_GET['from_join'] ) || $_GET['from_join'] !== 'true' ) {
		return;
	}
	require EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/join/templates/join-flow-modal.php';
}
add_action( 'extrachill_below_login_register_form', 'ec_render_join_flow_modal' );

/**
 * Detects if the current request came from the join flow
 *
 * @return bool True if from_join parameter is present, false otherwise
 */
function ec_is_join_flow_request() {
	if ( isset( $_REQUEST['from_join'] ) && $_REQUEST['from_join'] === 'true' ) {
		return true;
	}

	if ( isset( $_REQUEST['redirect_to'] ) ) {
		$redirect_to_parts = wp_parse_url( $_REQUEST['redirect_to'] );
		if ( isset( $redirect_to_parts['query'] ) ) {
			parse_str( $redirect_to_parts['query'], $query_params );
			if ( isset( $query_params['from_join'] ) && $query_params['from_join'] === 'true' ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Get the appropriate destination for a join flow user
 *
 * @param int $user_id The user ID
 * @return array Destination with url, message, and type keys
 */
function ec_get_join_flow_destination( $user_id ) {
	$user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );

	if ( ! empty( $user_artist_ids ) && is_array( $user_artist_ids ) ) {
		return array(
			'url'     => home_url( '/manage-link-page/' ),
			'message' => __( 'Welcome back! Manage your link page below.', 'extrachill-artist-platform' ),
			'type'    => 'success',
		);
	}

	return array(
		'url'     => home_url( '/create-artist/' ),
		'message' => __( 'Create an artist profile to get started with your link page.', 'extrachill-artist-platform' ),
		'type'    => 'info',
	);
}

/**
 * Handle logged-in user landing on login page with from_join parameter
 *
 * Redirects already-logged-in users who land on the login page with from_join=true
 * to either their link page management or artist profile creation.
 */
function ec_join_flow_login_page_redirect() {
	if ( ! is_user_logged_in() || ! is_page( 'login' ) ) {
		return;
	}

	if ( ! isset( $_GET['from_join'] ) || $_GET['from_join'] !== 'true' ) {
		return;
	}

	$dest = ec_get_join_flow_destination( get_current_user_id() );
	extrachill_set_notice( $dest['message'], $dest['type'] );
	wp_redirect( $dest['url'] );
	exit;
}
add_action( 'template_redirect', 'ec_join_flow_login_page_redirect' );

/**
 * Handle post-login redirect for join flow users
 *
 * Intercepts the login redirect to route users who log in via join flow
 * to either their link page management or artist profile creation.
 *
 * @param string  $redirect_to           Default redirect URL
 * @param string  $requested_redirect_to Requested redirect URL
 * @param WP_User $user                  User object
 * @return string Modified redirect URL
 */
function ec_join_flow_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! isset( $user->ID ) || $user->ID <= 0 ) {
		return $redirect_to;
	}

	if ( ! ec_is_join_flow_request() ) {
		return $redirect_to;
	}

	$dest = ec_get_join_flow_destination( $user->ID );
	extrachill_set_notice( $dest['message'], $dest['type'] );
	return $dest['url'];
}
add_filter( 'login_redirect', 'ec_join_flow_login_redirect', 10, 3 );

/**
 * Handle post-registration redirect for join flow users
 *
 * Routes new users who register via join flow to the create-artist page.
 *
 * @param string       $redirect_to Default redirect URL
 * @param int|WP_Error $user_id     User ID if successful, WP_Error otherwise
 * @return string Modified redirect URL for join flow users, original URL otherwise
 */
function ec_join_flow_registration_redirect( $redirect_to, $user_id ) {
	if ( ! $user_id || is_wp_error( $user_id ) ) {
		return $redirect_to;
	}

	if ( ! ec_is_join_flow_request() ) {
		return $redirect_to;
	}

	extrachill_set_notice(
		__( 'Welcome! Create your artist profile to get started.', 'extrachill-artist-platform' ),
		'success'
	);

	return home_url( '/create-artist/' );
}
add_filter( 'registration_redirect', 'ec_join_flow_registration_redirect', 10, 2 );
