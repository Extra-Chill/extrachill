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
// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress lostpassword_url filter signature.
function ec_custom_lostpassword_url( $lostpassword_url, $redirect ) {
	return ec_get_site_url( 'community' ) . '/reset-password/';
}
add_filter( 'lostpassword_url', 'ec_custom_lostpassword_url', 10, 2 );

/**
 * Get the transient key for password reset attempts.
 *
 * Keyed on requester IP only (not on submitted user_login) so attackers
 * cannot bypass the limit by varying the input.
 *
 * @return string Transient key.
 */
function ec_get_password_reset_attempt_key() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return 'ec_password_reset_attempts_' . md5( $ip );
}

/**
 * Check if password reset requests are blocked due to too many attempts.
 *
 * @return bool True if blocked.
 */
function ec_is_password_reset_blocked() {
	$attempts = get_transient( ec_get_password_reset_attempt_key() );
	return $attempts && $attempts >= 5;
}

/**
 * Record a password reset request attempt.
 */
function ec_record_password_reset_attempt() {
	$key      = ec_get_password_reset_attempt_key();
	$attempts = get_transient( $key );
	$attempts = $attempts ? $attempts + 1 : 1;
	set_transient( $key, $attempts, 15 * MINUTE_IN_SECONDS );
}

/**
 * Handle password reset request form submission.
 */
function ec_handle_password_reset_request() {
	$redirect = EC_Redirect_Handler::from_post( 'ec_password_reset' );

	$redirect->verify_nonce( 'ec_password_reset_nonce', 'ec_password_reset_request' );

	if ( ec_is_password_reset_blocked() ) {
		$redirect->error( __( 'Too many password reset requests. Please try again in 15 minutes.', 'extrachill-users' ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above through EC_Redirect_Handler.
	$user_login = isset( $_POST['user_login'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ) : '';

	if ( empty( $user_login ) ) {
		$redirect->error( __( 'Please enter your email or username.', 'extrachill-users' ) );
	}

	ec_record_password_reset_attempt();

	/**
	 * Fires before password reset processing.
	 *
	 * Mirrors WordPress core's wp-login.php action so plugins (rate limiters,
	 * audit loggers, captcha validators) listening for reset attempts see ours.
	 *
	 * Listeners can add errors to the WP_Error object to abort the flow.
	 *
	 * @param WP_Error $errors WP_Error object to collect validation errors.
	 */
	$errors = new WP_Error();
	do_action( 'lostpassword_post', $errors );

	if ( $errors->has_errors() ) {
		$redirect->error( $errors->get_error_message() );
	}

	if ( strpos( $user_login, '@' ) !== false ) {
		$user = get_user_by( 'email', $user_login );
	} else {
		$user = get_user_by( 'login', $user_login );
	}

	if ( ! $user ) {
		/**
		 * Fires when a password reset lookup fails.
		 *
		 * Mirrors WordPress core's retrieve_password_failure action so
		 * silent failures (issue #27) are observable. Also writes to
		 * error_log when WP_DEBUG_LOG is on so misses surface in debug.log.
		 *
		 * @param WP_Error $errors WP_Error containing the failure reason.
		 */
		$failure_errors = new WP_Error( 'invalid_email', __( 'There is no account with that username or email address.', 'extrachill-users' ) );
		do_action( 'retrieve_password_failure', $failure_errors );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug log; gated on WP_DEBUG_LOG.
			error_log( sprintf( '[ec_password_reset] No user found for input (sanitized length=%d, contains_at=%s)', strlen( $user_login ), strpos( $user_login, '@' ) !== false ? 'yes' : 'no' ) );
		}

		// Enumeration protection: same generic success message regardless of whether a user was found.
		$redirect->success( __( 'If an account exists with that email or username, you will receive a password reset link.', 'extrachill-users' ) );
	}

	/**
	 * Fires before a password reset key is generated for a found user.
	 *
	 * Mirrors WordPress core's retrieve_password action. Listeners can
	 * short-circuit by adding errors to the WP_Error object.
	 *
	 * @param WP_Error $errors    WP_Error object to collect validation errors.
	 * @param WP_User  $user_data User object whose password is being reset.
	 */
	$user_errors = new WP_Error();
	do_action( 'retrieve_password', $user_errors, $user );

	if ( $user_errors->has_errors() ) {
		$redirect->error( $user_errors->get_error_message() );
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
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
add_action( 'admin_post_nopriv_ec_password_reset_request', 'ec_handle_password_reset_request' );
add_action( 'admin_post_ec_password_reset_request', 'ec_handle_password_reset_request' );

/**
 * Handle password reset form submission (setting new password).
 */
function ec_handle_reset_password() {
	$redirect = EC_Redirect_Handler::from_post( 'ec_password_reset' );

	$redirect->verify_nonce( 'ec_reset_password_nonce', 'ec_reset_password' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above through EC_Redirect_Handler.
	$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
	$pass1 = isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '';
	$pass2 = isset( $_POST['pass2'] ) ? wp_unslash( $_POST['pass2'] ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

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

	$subject = __( 'Password Reset Request - Extra Chill', 'extrachill-users' );
	$message = '<html><body>';
	/* translators: %s: user display name. */
	$message .= '<p>' . sprintf( __( 'Hello <strong>%s</strong>,', 'extrachill-users' ), esc_html( $user->display_name ) ) . '</p>';
	$message .= '<p>' . __( 'Someone requested a password reset for your Extra Chill account.', 'extrachill-users' ) . '</p>';
	$message .= '<p>' . __( 'If this was you, click the link below to reset your password:', 'extrachill-users' ) . '</p>';
	$message .= '<p><a href="' . esc_url( $reset_url ) . '">' . __( 'Reset Your Password', 'extrachill-users' ) . '</a></p>';
	$message .= '<p>' . __( 'This link will expire in 24 hours.', 'extrachill-users' ) . '</p>';
	$message .= '<p>' . __( 'If you didn\'t request this, you can safely ignore this email.', 'extrachill-users' ) . '</p>';
	$message .= '<p>' . __( 'Much love,', 'extrachill-users' ) . '<br>' . __( 'Extra Chill', 'extrachill-users' ) . '</p>';
	$message .= '</body></html>';

	/**
	 * Filters the password reset email subject.
	 *
	 * Mirrors WordPress core's retrieve_password_title filter so existing
	 * integrations work. Note: core passes (string $title, string $user_login,
	 * WP_User $user_data); we match that signature.
	 *
	 * @param string  $subject    Default email subject.
	 * @param string  $user_login User login of the recipient.
	 * @param WP_User $user       User object of the recipient.
	 */
	$subject = apply_filters( 'retrieve_password_title', $subject, $user->user_login, $user );

	/**
	 * Filters the password reset email message.
	 *
	 * Mirrors WordPress core's retrieve_password_message filter. Note: core
	 * passes (string $message, string $key, string $user_login, WP_User $user_data);
	 * we match that signature. Our message is HTML; filters that expect plain
	 * text should check $headers and act accordingly.
	 *
	 * @param string  $message    Default email body (HTML).
	 * @param string  $reset_key  Password reset key.
	 * @param string  $user_login User login of the recipient.
	 * @param WP_User $user       User object of the recipient.
	 */
	$message = apply_filters( 'retrieve_password_message', $message, $reset_key, $user->user_login, $user );

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: Extra Chill <' . get_option( 'admin_email' ) . '>',
	);

	return wp_mail( $user->user_email, $subject, $message, $headers );
}
