<?php
/**
 * Browser handoff handler.
 *
 * Sets WordPress auth cookies in a real browser using a one-time token,
 * then redirects to the requested page.
 *
 * Triggered by query argument: ?ec_browser_handoff=<token>
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'extrachill_users_handle_browser_handoff' );

/**
 * Handle browser handoff requests.
 */
function extrachill_users_handle_browser_handoff() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$token = isset( $_GET['ec_browser_handoff'] ) ? sanitize_text_field( wp_unslash( $_GET['ec_browser_handoff'] ) ) : '';
	if ( '' === $token ) {
		return;
	}

	if ( ! function_exists( 'extrachill_users_consume_browser_handoff_token' ) ) {
		status_header( 500 );
		exit;
	}

	$payload = extrachill_users_consume_browser_handoff_token( $token );
	if ( is_wp_error( $payload ) ) {
		$status = 400;
		$data   = $payload->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}

		status_header( $status );
		exit;
	}

	$user_id      = (int) $payload['user_id'];
	$redirect_url = (string) $payload['redirect_url'];

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		status_header( 401 );
		exit;
	}

	$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );
	if ( ! is_string( $redirect_host ) || '' === $redirect_host ) {
		status_header( 400 );
		exit;
	}

	$redirect_host = strtolower( $redirect_host );
	if ( false !== strpos( $redirect_host, 'extrachill.link' ) ) {
		status_header( 400 );
		exit;
	}

	$is_valid_host = ( 'extrachill.com' === $redirect_host || substr( $redirect_host, -strlen( '.extrachill.com' ) ) === '.extrachill.com' );
	if ( ! $is_valid_host ) {
		status_header( 400 );
		exit;
	}

	wp_set_current_user( $user_id, $user->user_login );
	wp_set_auth_cookie( $user_id, false );
	do_action( 'wp_login', $user->user_login, $user );

	wp_safe_redirect( $redirect_url );
	exit;
}
