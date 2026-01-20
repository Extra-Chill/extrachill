<?php
/**
 * Login Handler
 *
 * Handles login error redirects via EC_Redirect_Handler and blocks direct wp-login.php access.
 * Includes rate limiting to prevent brute force attacks.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the transient key for login attempts.
 *
 * @param string $username Username being attempted.
 * @return string Transient key.
 */
function ec_get_login_attempt_key( $username ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return 'ec_login_attempts_' . md5( $ip . strtolower( (string) $username ) );
}

/**
 * Check if login is blocked due to too many failed attempts.
 *
 * @param string $username Username being attempted.
 * @return bool True if blocked.
 */
function ec_is_login_blocked( $username ) {
	if ( empty( $username ) ) {
		return false;
	}
	$key      = ec_get_login_attempt_key( $username );
	$attempts = get_transient( $key );
	return $attempts && $attempts >= 5;
}

/**
 * Record a failed login attempt.
 *
 * @param string $username Username that failed.
 */
function ec_record_failed_login( $username ) {
	if ( empty( $username ) ) {
		return;
	}
	$key      = ec_get_login_attempt_key( $username );
	$attempts = get_transient( $key );
	$attempts = $attempts ? $attempts + 1 : 1;
	set_transient( $key, $attempts, 15 * MINUTE_IN_SECONDS );
}

/**
 * Clear login attempts after successful login.
 *
 * @param string $username Username that succeeded.
 */
function ec_clear_login_attempts( $username ) {
	if ( empty( $username ) ) {
		return;
	}
	delete_transient( ec_get_login_attempt_key( $username ) );
}

/**
 * Block authentication if too many failed attempts.
 *
 * @param WP_User|WP_Error|null $user     User object, error, or null.
 * @param string                $username Username being attempted.
 * @return WP_User|WP_Error|null User or error.
 */
function ec_rate_limit_login( $user, $username ) {
	if ( empty( $username ) ) {
		return $user;
	}

	if ( ec_is_login_blocked( $username ) ) {
		return new WP_Error(
			'ec_login_blocked',
			__( 'Too many failed login attempts. Please try again in 15 minutes.', 'extrachill-users' )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'ec_rate_limit_login', 20, 2 );

/**
 * Clear login attempts on successful login.
 *
 * @param string  $user_login Username.
 * @param WP_User $user       User object.
 */
function ec_clear_attempts_on_login( $user_login, $user ) {
	if ( empty( $user_login ) || ! $user ) {
		return;
	}
	ec_clear_login_attempts( $user_login );
}
add_action( 'wp_login', 'ec_clear_attempts_on_login', 10, 2 );

/**
 * Handle failed login attempts by setting error transient and redirecting.
 *
 * @param string $username Username attempted
 */
function extrachill_handle_login_failed( $username ) {
	ec_record_failed_login( $username );
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

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
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return $user;
	}

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
