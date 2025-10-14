<?php
/**
 * Password Reset Block Server-Side Render
 *
 * @package ExtraChillUsers
 */

// Determine which form to show based on URL parameters
$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'request';
$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

if ( $action === 'reset' && ! empty( $key ) && ! empty( $login ) ) {
	// Show reset password form (user clicked email link)
	echo function_exists( 'ec_render_reset_password_form' )
		? ec_render_reset_password_form( $login, $key )
		: '';
} else {
	// Show request reset form (user needs to enter email)
	echo function_exists( 'ec_render_password_reset_request_form' )
		? ec_render_password_reset_request_form()
		: '';
}
