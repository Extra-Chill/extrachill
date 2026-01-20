<?php
/**
 * Logout Functionality
 *
 * Custom logout handler with nonce verification using EC_Redirect_Handler
 * for consistent redirect behavior.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter logout URL for custom redirect.
 *
 * @param string $logout_url Default logout URL
 * @param string $redirect   Redirect destination
 * @return string Modified logout URL
 */
function extrachill_custom_logout_url( $logout_url, $redirect ) {
	$current_url = set_url_scheme(
		( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . strtok( $_SERVER['REQUEST_URI'], '?' )
	);
	$logout_url  = add_query_arg( 'custom_logout', '1', $current_url );
	$logout_url  = wp_nonce_url( $logout_url, 'custom-logout-action', 'logout_nonce' );
	return $logout_url;
}
add_filter( 'logout_url', 'extrachill_custom_logout_url', 10, 2 );

/**
 * Handle custom logout with nonce verification.
 */
function extrachill_handle_custom_logout() {
	if ( ! isset( $_GET['custom_logout'] ) || '1' !== $_GET['custom_logout'] ) {
		return;
	}

	$nonce = isset( $_GET['logout_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['logout_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'custom-logout-action' ) ) {
		return;
	}

	wp_logout();

	$redirect = new EC_Redirect_Handler( home_url(), '', 'ec_logout' );
	$redirect->redirect_to( home_url() );
}
add_action( 'init', 'extrachill_handle_custom_logout' );
