<?php
/**
 * Password Reset Handler
 *
 * Handles password reset request and new password submission via admin-post.php.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter lostpassword_url to custom reset page.
 *
 * @param string $lostpassword_url Default lost password URL
 * @param string $redirect         Redirect destination
 * @return string Modified lost password URL
 */
function ec_custom_lostpassword_url( $lostpassword_url, $redirect ) {
	return ec_get_site_url( 'community' ) . '/reset-password/';
}
add_filter( 'lostpassword_url', 'ec_custom_lostpassword_url', 10, 2 );

/**
 * Handle password reset request form submission.
 */
function ec_handle_password_reset_request() {
	$redirect = EC_Redirect_Handler::from_post( 'ec_password_reset' );

	$redirect->verify_nonce( 'ec_password_reset_nonce', 'ec_password_reset_request' );

	$user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

	if ( empty( $user_login ) ) {
		$redirect->error( __( 'Please enter your email address.', 'extrachill-users' ) );
	}

	$user = get_user_by( 'email', $user_login );

	if ( ! $user ) {
		$redirect->success( __( 'If an account exists with that email, you will receive a password reset link.', 'extrachill-users' ) );
	}

	$reset_key = get_password_reset_key( $user );

	if ( is_wp_error( $reset_key ) ) {
		$redirect->error( __( 'Unable to generate reset key. Please try again.', 'extrachill-users' ) );
	}

	$sent = ec_send_password_reset_email( $user, $reset_key );

	if ( $sent ) {
		$redirect->success( __( 'Password reset email sent! Check your inbox.', 'extrachill-users' ) );
	} else {
		$redirect->error( __( 'Failed to send reset email. Please try again.', 'extrachill-users' ) );
	}
}
add_action( 'admin_post_nopriv_ec_password_reset_request', 'ec_handle_password_reset_request' );
add_action( 'admin_post_ec_password_reset_request', 'ec_handle_password_reset_request' );

/**
 * Handle password reset form submission (setting new password).
 */
function ec_handle_reset_password() {
	$redirect = EC_Redirect_Handler::from_post( 'ec_password_reset' );

	$redirect->verify_nonce( 'ec_reset_password_nonce', 'ec_reset_password' );

	$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
	$pass1 = isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '';
	$pass2 = isset( $_POST['pass2'] ) ? wp_unslash( $_POST['pass2'] ) : '';

	if ( $pass1 !== $pass2 ) {
		$redirect->error(
			__( 'Passwords do not match.', 'extrachill-users' ),
			array(
				'action' => 'reset',
				'key'    => $key,
				'login'  => $login,
			)
		);
	}

	if ( strlen( $pass1 ) < 8 ) {
		$redirect->error(
			__( 'Password must be at least 8 characters.', 'extrachill-users' ),
			array(
				'action' => 'reset',
				'key'    => $key,
				'login'  => $login,
			)
		);
	}

	$user = check_password_reset_key( $key, $login );

	if ( is_wp_error( $user ) ) {
		$redirect->error( __( 'Invalid or expired reset link. Please request a new one.', 'extrachill-users' ) );
	}

	reset_password( $user, $pass1 );
	wp_set_auth_cookie( $user->ID, true );

	wp_safe_redirect( home_url() );
	exit;
}
add_action( 'admin_post_nopriv_ec_reset_password', 'ec_handle_reset_password' );
add_action( 'admin_post_ec_reset_password', 'ec_handle_reset_password' );

/**
 * Send password reset email.
 *
 * @param WP_User $user      User object
 * @param string  $reset_key Reset key
 * @return bool Whether email was sent successfully
 */
function ec_send_password_reset_email( $user, $reset_key ) {
	$reset_url = add_query_arg(
		array(
			'action' => 'reset',
			'key'    => $reset_key,
			'login'  => rawurlencode( $user->user_login ),
		),
		ec_get_site_url( 'community' ) . '/reset-password/'
	);

	$subject  = __( 'Password Reset Request - Extra Chill', 'extrachill-users' );
	$message  = '<html><body>';
	$message .= '<p>' . sprintf( __( 'Hello <strong>%s</strong>,', 'extrachill-users' ), esc_html( $user->display_name ) ) . '</p>';
	$message .= '<p>' . __( 'Someone requested a password reset for your Extra Chill account.', 'extrachill-users' ) . '</p>';
	$message .= '<p>' . __( 'If this was you, click the link below to reset your password:', 'extrachill-users' ) . '</p>';
	$message .= '<p><a href="' . esc_url( $reset_url ) . '">' . __( 'Reset Your Password', 'extrachill-users' ) . '</a></p>';
	$message .= '<p>' . __( 'This link will expire in 24 hours.', 'extrachill-users' ) . '</p>';
	$message .= '<p>' . __( 'If you didn\'t request this, you can safely ignore this email.', 'extrachill-users' ) . '</p>';
	$message .= '<p>' . __( 'Much love,', 'extrachill-users' ) . '<br>' . __( 'Extra Chill', 'extrachill-users' ) . '</p>';
	$message .= '</body></html>';

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: Extra Chill <' . get_option( 'admin_email' ) . '>',
	);

	return wp_mail( $user->user_email, $subject, $message, $headers );
}
