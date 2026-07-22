<?php
/**
 * Core Multisite Signup Policy
 *
 * Closes the public wp-signup.php surface without changing the network
 * registration setting used by Extra Chill's branded registration flows.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the canonical branded registration URL for the network.
 *
 * @return string Registration URL.
 */
function extrachill_users_get_registration_url() {
	return network_home_url( '/login/', 'https' ) . '#tab-register';
}

/**
 * Close WordPress core's multisite signup endpoint.
 *
 * Core redirects subsite wp-signup.php requests to the main-site endpoint
 * before firing this hook. Safe requests therefore share one canonical
 * destination, while submissions stop before core validates or creates users.
 */
function extrachill_users_close_core_signup_surface() {
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	if ( in_array( $request_method, array( 'GET', 'HEAD' ), true ) ) {
		wp_safe_redirect( extrachill_users_get_registration_url() );
		exit;
	}

	wp_die(
		esc_html__( 'Direct signup submissions are not allowed.', 'extrachill-users' ),
		'',
		array( 'response' => 403 )
	);
}
add_action( 'before_signup_header', 'extrachill_users_close_core_signup_surface', 0 );
