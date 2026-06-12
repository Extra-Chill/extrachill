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
 * Rewrite WordPress core password reset email to point at /reset-password/.
 *
 * WordPress core's retrieve_password() generates a reset URL of the form
 *   {network_site_url}/wp-login.php?action=rp&key=...&login=...
 * On Extra Chill, /wp-login.php is redirected to /login/ by
 * extrachill_redirect_wp_login_access() — but the redirect strips the
 * key/login (or, with the wp-login.php redirect fix, sends users to
 * community.extrachill.com/reset-password/ via 302). Either way we want
 * the link in the email body itself to point at the right destination
 * from the start, so admin-triggered resets (wp user reset-password,
 * wp-admin → Users → "Send password reset") deliver a clickable link
 * the user can land on without a redirect dance.
 *
 * Same handler the /reset-password/ render template processes via
 * ec_handle_reset_password().
 *
 * @param array   $email      Default email content (subject, message, headers, to).
 * @param string  $key        Password reset key.
 * @param string  $user_login User login of the recipient.
 * @param WP_User $user_data  User object of the recipient.
 * @return array Modified email content with the reset URL pointing at /reset-password/.
 */
function ec_filter_password_reset_email( $email, $key, $user_login, $user_data ) {
	$reset_url = add_query_arg(
		array(
			'action' => 'reset',
			'key'    => rawurlencode( $key ),
			'login'  => rawurlencode( $user_login ),
		),
		ec_get_site_url( 'community' ) . '/reset-password/'
	);

	// Replace the wp-login.php link in the body with the canonical reset URL.
	if ( isset( $email['message'] ) && is_string( $email['message'] ) ) {
		$email['message'] = preg_replace(
			'#https?://\S*wp-login\.php\?action=rp\S*#i',
			$reset_url,
			$email['message']
		);
	}

	return $email;
}
add_filter( 'retrieve_password_notification_email', 'ec_filter_password_reset_email', 10, 4 );

/**
 * Rewrite the new-user notification email reset link.
 *
 * When admins create users via wp-admin → Users → Add New (with notification)
 * or `wp user create --send-email`, WordPress sends a welcome email
 * containing the same wp-login.php?action=rp reset link. Filter that too.
 *
 * @param array   $email      Default email content.
 * @param WP_User $user       Newly created user.
 * @param string  $blogname   Site name.
 * @return array Modified email content.
 */
function ec_filter_new_user_notification_email( $email, $user, $blogname ) {
	if ( ! isset( $email['message'] ) || ! is_string( $email['message'] ) ) {
		return $email;
	}

	// Extract the reset key from the existing wp-login.php URL so we don't
	// need to regenerate one (the email already embeds the key WP minted).
	if ( ! preg_match( '#wp-login\.php\?action=rp&key=([^&\s]+)&login=([^&\s]+)#i', $email['message'], $matches ) ) {
		return $email;
	}

	$reset_url = add_query_arg(
		array(
			'action' => 'reset',
			'key'    => $matches[1],
			'login'  => $matches[2],
		),
		ec_get_site_url( 'community' ) . '/reset-password/'
	);

	$email['message'] = preg_replace(
		'#https?://\S*wp-login\.php\?action=rp\S*#i',
		$reset_url,
		$email['message']
	);

	return $email;
}
add_filter( 'wp_new_user_notification_email', 'ec_filter_new_user_notification_email', 10, 3 );

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
 * Password reset requests run in an unprivileged context (anonymous
 * `admin_post_nopriv` POST), so this routes through
 * {@see extrachill_send_registration_email()} which executes the underlying
 * `datamachine/send-email` ability inside
 * `PermissionHelper::run_as_authenticated()`. Calling `ec_send_email()`
 * directly from this context makes `WP_Ability::execute()` short-circuit on
 * its permission callback and return a `WP_Error` instead of the documented
 * array envelope — array-indexing that WP_Error was a hard fatal on the
 * user-facing reset flow (and the email never sent). Same root cause as #110.
 *
 * The authorization decision is made at THIS layer: the request is
 * nonce-verified and rate-limited before we get here.
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

	$body_html  = '<p>' . esc_html__( 'Someone requested a password reset for your Extra Chill account.', 'extrachill-users' ) . '</p>';
	$body_html .= '<p>' . esc_html__( 'If this was you, click the button below to reset your password:', 'extrachill-users' ) . '</p>';
	$body_html .= '<p>' . esc_html__( 'This link will expire in 24 hours.', 'extrachill-users' ) . '</p>';
	$body_html .= '<p>' . esc_html__( 'If you didn\'t request this, you can safely ignore this email.', 'extrachill-users' ) . '</p>';
	$body_html .= '<p>' . esc_html__( 'Much love,', 'extrachill-users' ) . '<br>' . esc_html__( 'Extra Chill', 'extrachill-users' ) . '</p>';

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
	 * Filters the password reset email message body (HTML inner content).
	 *
	 * Mirrors WordPress core's retrieve_password_message filter signature.
	 * Note: the body is the inner HTML content passed to the EC email
	 * template — it does NOT include `<html>`/`<body>` wrappers. The
	 * `extrachill/minimal` template owns greeting + CTA + footer.
	 *
	 * @param string  $body_html  Default email body inner HTML.
	 * @param string  $reset_key  Password reset key.
	 * @param string  $user_login User login of the recipient.
	 * @param WP_User $user       User object of the recipient.
	 */
	$body_html = apply_filters( 'retrieve_password_message', $body_html, $reset_key, $user->user_login, $user );

	$result = extrachill_send_registration_email(
		array(
			'to'         => $user->user_email,
			'subject'    => $subject,
			'template'   => 'extrachill/minimal',
			'from_name'  => 'Extra Chill',
			'from_email' => get_option( 'admin_email' ),
			'context'    => array(
				'subject_html'   => esc_html( $subject ),
				'body_html'      => $body_html,
				'recipient_name' => $user->display_name,
				'cta_url'        => $reset_url,
				'cta_label'      => __( 'Reset Your Password', 'extrachill-users' ),
				'preheader'      => __( 'Reset your Extra Chill password — link expires in 24 hours.', 'extrachill-users' ),
			),
		)
	);

	// Defensive: the envelope is documented as an array, but WP_Ability::execute()
	// returns WP_Error on permission/validation failure (the production fatal:
	// "Cannot use object of type WP_Error as array"). Never index blindly.
	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		extrachill_log_email_failure( 'password_reset', $user->ID, $user->user_email, $subject, $result );
		return false;
	}

	return true;
}
