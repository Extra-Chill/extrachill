<?php
/**
 * Password Reset System
 *
 * Custom UI for WordPress native password reset (never exposes wp-admin).
 *
 * @package ExtraChill\Users
 */

/**
 * Filter lostpassword_url to custom reset page.
 *
 * @param string $lostpassword_url Default lost password URL
 * @param string $redirect Redirect destination
 * @return string Modified lost password URL
 */
add_filter( 'lostpassword_url', 'ec_custom_lostpassword_url', 10, 2 );
function ec_custom_lostpassword_url( $lostpassword_url, $redirect ) {
	return 'https://community.extrachill.com/reset-password/';
}

/**
 * Render password reset request form.
 *
 * @return string Form HTML
 */
function ec_render_password_reset_request_form() {
	$messages = ec_get_password_reset_messages();

	ob_start();
	?>
	<div class="password-reset-form">
		<h2><?php esc_html_e( 'Reset Your Password', 'extrachill-users' ); ?></h2>
		<p><?php esc_html_e( 'Enter your email address and we\'ll send you a link to reset your password.', 'extrachill-users' ); ?></p>

		<?php if ( ! empty( $messages ) ) : ?>
			<?php foreach ( $messages as $message ) : ?>
				<div class="password-reset-<?php echo esc_attr( $message['type'] ); ?>">
					<?php echo esc_html( $message['text'] ); ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ec_password_reset_request', 'ec_password_reset_nonce' ); ?>
			<input type="hidden" name="action" value="ec_password_reset_request">

			<label for="user_login"><?php esc_html_e( 'Email Address', 'extrachill-users' ); ?></label>
			<input type="email" name="user_login" id="user_login" required>

			<input type="submit" class="button-1 button-medium" value="<?php esc_attr_e( 'Send Reset Link', 'extrachill-users' ); ?>">
		</form>

		<p><a href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><?php esc_html_e( '← Back to Login', 'extrachill-users' ); ?></a></p>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render reset password form with validation.
 *
 * @param string $login User login
 * @param string $key Reset key
 * @return string Form HTML
 */
function ec_render_reset_password_form( $login, $key ) {
	$user = check_password_reset_key( $key, $login );

	if ( is_wp_error( $user ) ) {
		ob_start();
		?>
		<div class="password-reset-form">
			<div class="password-reset-error">
				<?php esc_html_e( 'This password reset link is invalid or has expired. Please request a new one.', 'extrachill-users' ); ?>
			</div>
			<p><a href="<?php echo esc_url( home_url( '/reset-password/' ) ); ?>" class="button-1 button-medium"><?php esc_html_e( 'Request New Reset Link', 'extrachill-users' ); ?></a></p>
		</div>
		<?php
		return ob_get_clean();
	}

	$messages = ec_get_password_reset_messages();

	ob_start();
	?>
	<div class="password-reset-form">
		<h2><?php esc_html_e( 'Set New Password', 'extrachill-users' ); ?></h2>
		<p><?php esc_html_e( 'Enter your new password below.', 'extrachill-users' ); ?></p>

		<?php if ( ! empty( $messages ) ) : ?>
			<?php foreach ( $messages as $message ) : ?>
				<div class="password-reset-<?php echo esc_attr( $message['type'] ); ?>">
					<?php echo esc_html( $message['text'] ); ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ec_reset_password', 'ec_reset_password_nonce' ); ?>
			<input type="hidden" name="action" value="ec_reset_password">
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">

			<label for="pass1"><?php esc_html_e( 'New Password', 'extrachill-users' ); ?></label>
			<input type="password" name="pass1" id="pass1" required minlength="8">

			<label for="pass2"><?php esc_html_e( 'Confirm Password', 'extrachill-users' ); ?></label>
			<input type="password" name="pass2" id="pass2" required minlength="8">

			<input type="submit" class="button-1 button-medium" value="<?php esc_attr_e( 'Reset Password', 'extrachill-users' ); ?>">
		</form>
	</div>
	<?php
	return ob_get_clean();
}

add_action( 'admin_post_nopriv_ec_password_reset_request', 'ec_handle_password_reset_request' );
add_action( 'admin_post_ec_password_reset_request', 'ec_handle_password_reset_request' );
function ec_handle_password_reset_request() {
	if ( ! isset( $_POST['ec_password_reset_nonce'] ) || ! wp_verify_nonce( $_POST['ec_password_reset_nonce'], 'ec_password_reset_request' ) ) {
		wp_die( 'Security check failed' );
	}

	$user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

	if ( empty( $user_login ) ) {
		ec_set_password_reset_message( __( 'Please enter your email address.', 'extrachill-users' ), 'error' );
		wp_safe_redirect( 'https://community.extrachill.com/reset-password/' );
		exit;
	}

	// Get user by email
	$user = get_user_by( 'email', $user_login );

	if ( ! $user ) {
		// Security: Don't reveal whether user exists
		ec_set_password_reset_message( __( 'If an account exists with that email, you will receive a password reset link.', 'extrachill-users' ), 'success' );
		wp_safe_redirect( 'https://community.extrachill.com/reset-password/' );
		exit;
	}

	$reset_key = get_password_reset_key( $user );

	if ( is_wp_error( $reset_key ) ) {
		ec_set_password_reset_message( __( 'Unable to generate reset key. Please try again.', 'extrachill-users' ), 'error' );
		wp_safe_redirect( 'https://community.extrachill.com/reset-password/' );
		exit;
	}

	$sent = ec_send_password_reset_email( $user, $reset_key );

	if ( $sent ) {
		ec_set_password_reset_message( __( 'Password reset email sent! Check your inbox.', 'extrachill-users' ), 'success' );
	} else {
		ec_set_password_reset_message( __( 'Failed to send reset email. Please try again.', 'extrachill-users' ), 'error' );
	}

	wp_safe_redirect( 'https://community.extrachill.com/reset-password/' );
	exit;
}

add_action( 'admin_post_nopriv_ec_reset_password', 'ec_handle_reset_password' );
add_action( 'admin_post_ec_reset_password', 'ec_handle_reset_password' );
function ec_handle_reset_password() {
	if ( ! isset( $_POST['ec_reset_password_nonce'] ) || ! wp_verify_nonce( $_POST['ec_reset_password_nonce'], 'ec_reset_password' ) ) {
		wp_die( 'Security check failed' );
	}

	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
	$pass1 = isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '';
	$pass2 = isset( $_POST['pass2'] ) ? wp_unslash( $_POST['pass2'] ) : '';

	if ( $pass1 !== $pass2 ) {
		ec_set_password_reset_message( __( 'Passwords do not match.', 'extrachill-users' ), 'error' );
		wp_safe_redirect( 'https://community.extrachill.com/reset-password/?action=reset&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ) );
		exit;
	}

	if ( strlen( $pass1 ) < 8 ) {
		ec_set_password_reset_message( __( 'Password must be at least 8 characters.', 'extrachill-users' ), 'error' );
		wp_safe_redirect( 'https://community.extrachill.com/reset-password/?action=reset&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ) );
		exit;
	}

	$user = check_password_reset_key( $key, $login );

	if ( is_wp_error( $user ) ) {
		ec_set_password_reset_message( __( 'Invalid or expired reset link.', 'extrachill-users' ), 'error' );
		wp_safe_redirect( 'https://community.extrachill.com/reset-password/' );
		exit;
	}

	reset_password( $user, $pass1 );
	wp_set_auth_cookie( $user->ID );
	wp_safe_redirect( add_query_arg( 'password-reset', 'success', home_url() ) );
	exit;
}

/**
 * Send password reset email.
 *
 * @param WP_User $user User object
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
		'https://community.extrachill.com/reset-password/'
	);

	$subject = __( 'Password Reset Request - Extra Chill', 'extrachill-users' );
	$message = '<html><body>';
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

/**
 * Set password reset message in transient.
 *
 * @param string $text Message text
 * @param string $type Message type (success|error)
 */
function ec_set_password_reset_message( $text, $type = 'success' ) {
	set_transient(
		'ec_password_reset_message',
		array(
			'text' => $text,
			'type' => $type,
		),
		60
	);
}

/**
 * Get and clear password reset messages.
 *
 * @return array Messages array
 */
function ec_get_password_reset_messages() {
	$message = get_transient( 'ec_password_reset_message' );
	if ( $message ) {
		delete_transient( 'ec_password_reset_message' );
		return array( $message );
	}
	return array();
}
