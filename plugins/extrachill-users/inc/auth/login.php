<?php
/**
 * Login Handler
 *
 * Handles login error redirects via EC_Redirect_Handler and blocks direct wp-login.php access.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle failed login attempts by setting error transient and redirecting.
 *
 * @param string $username Username attempted
 */
function extrachill_handle_login_failed( $username ) {
	$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

	if ( empty( $referrer ) || false !== strpos( $referrer, 'wp-login' ) || false !== strpos( $referrer, 'wp-admin' ) ) {
		return;
	}

	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$fragment   = isset( $_POST['source_fragment'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fragment'] ) ) : 'tab-login';

	if ( empty( $source_url ) ) {
		$source_url = home_url( '/login/' );
	}

	$redirect = new EC_Redirect_Handler( $source_url, $fragment, 'ec_login' );
	$redirect->error( __( 'Invalid username or password. Please try again.', 'extrachill-users' ) );
}
add_action( 'wp_login_failed', 'extrachill_handle_login_failed' );

/**
 * Intercept authentication errors and redirect with transient message.
 *
 * @param WP_User|WP_Error $user     User object or error
 * @param string           $username Username
 * @param string           $password Password
 * @return WP_User|WP_Error
 */
function extrachill_intercept_auth_error( $user, $username, $password ) {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		return $user;
	}

	if ( ! isset( $_POST['log'] ) || ! isset( $_POST['pwd'] ) ) {
		return $user;
	}

	if ( ! is_wp_error( $user ) ) {
		return $user;
	}

	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$fragment   = isset( $_POST['source_fragment'] ) ? sanitize_text_field( wp_unslash( $_POST['source_fragment'] ) ) : 'tab-login';

	if ( empty( $source_url ) ) {
		$source_url = home_url( '/login/' );
	}

	$redirect = new EC_Redirect_Handler( $source_url, $fragment, 'ec_login' );
	$redirect->error( __( 'Invalid username or password. Please try again.', 'extrachill-users' ) );

	return $user;
}
add_filter( 'authenticate', 'extrachill_intercept_auth_error', 99, 3 );

/**
 * Redirect direct wp-login.php access to custom login page.
 */
function extrachill_redirect_wp_login_access() {
	if ( false === strpos( strtolower( $_SERVER['REQUEST_URI'] ), '/wp-login.php' ) ) {
		return;
	}

	if ( is_user_logged_in() ) {
		return;
	}

	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	wp_safe_redirect( home_url( '/login/' ) );
	exit;
}
add_action( 'init', 'extrachill_redirect_wp_login_access' );
