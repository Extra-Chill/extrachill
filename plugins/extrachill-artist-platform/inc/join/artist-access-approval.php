<?php
/**
 * Artist Access Approval Handler
 *
 * Handles the redirect flow when users click the approval link in their email.
 * Routes approved users to create-artist page with success notice.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle logged-in users landing on login page with access_approved=true
 *
 * Redirects already-logged-in users who click the approval email link
 * directly to their create-artist page with success notice.
 */
function ec_artist_access_approval_page_redirect() {
	if ( ! is_user_logged_in() || ! is_page( 'login' ) ) {
		return;
	}

	$access_approved = isset( $_GET['access_approved'] ) && $_GET['access_approved'] === 'true';

	if ( ! $access_approved ) {
		return;
	}

	$create_artist_page = get_page_by_path( 'create-artist' );
	if ( $create_artist_page ) {
		extrachill_set_notice(
			__( 'Your artist platform access has been approved! Create your first artist profile below.', 'extrachill-artist-platform' ),
			'success'
		);
		wp_redirect( get_permalink( $create_artist_page ) );
		exit;
	}
}
add_action( 'template_redirect', 'ec_artist_access_approval_page_redirect' );

/**
 * Handle login form submission redirect for approved users
 *
 * Intercepts the login redirect when user logs in via approval email link.
 * Routes them to create-artist page with success notice.
 *
 * @param string  $redirect_to           Default redirect URL
 * @param string  $requested_redirect_to Requested redirect URL
 * @param WP_User $user                  User object
 * @return string Modified redirect URL
 */
function ec_artist_access_approval_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! isset( $user->ID ) || $user->ID <= 0 ) {
		return $redirect_to;
	}

	$access_approved = false;

	if ( isset( $_REQUEST['access_approved'] ) && $_REQUEST['access_approved'] === 'true' ) {
		$access_approved = true;
	} elseif ( isset( $_REQUEST['redirect_to'] ) ) {
		$redirect_to_parts = wp_parse_url( $_REQUEST['redirect_to'] );
		if ( isset( $redirect_to_parts['query'] ) ) {
			parse_str( $redirect_to_parts['query'], $query_params );
			if ( isset( $query_params['access_approved'] ) && $query_params['access_approved'] === 'true' ) {
				$access_approved = true;
			}
		}
	}

	if ( ! $access_approved ) {
		return $redirect_to;
	}

	$create_artist_page = get_page_by_path( 'create-artist' );
	if ( $create_artist_page ) {
		extrachill_set_notice(
			__( 'Your artist platform access has been approved! Create your first artist profile below.', 'extrachill-artist-platform' ),
			'success'
		);
		return get_permalink( $create_artist_page );
	}

	return $redirect_to;
}
add_filter( 'login_redirect', 'ec_artist_access_approval_login_redirect', 10, 3 );
