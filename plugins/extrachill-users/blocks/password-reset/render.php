<?php
/**
 * Password Reset Block Server-Side Render
 *
 * @package ExtraChillUsers
 */

if ( is_user_logged_in() ) {
	$current_user = wp_get_current_user();
	?>
	<div class="password-reset-form">
		<p><strong>You're already logged in as <?php echo esc_html( $current_user->display_name ); ?></strong></p>
		<p>
			<a href="<?php echo esc_url( home_url() ); ?>" class="button-1 button-medium">Go to Homepage</a>
			<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="button-3 button-medium">Log Out</a>
		</p>
	</div>
	<?php
	return;
}

$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'request';
$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
$login  = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

if ( $action === 'reset' && ! empty( $key ) && ! empty( $login ) ) {
	$user = check_password_reset_key( $key, $login );

	if ( is_wp_error( $user ) ) {
		?>
		<div class="password-reset-form">
			<div class="notice notice-error">
				<?php esc_html_e( 'This password reset link is invalid or has expired. Please request a new one.', 'extrachill-users' ); ?>
			</div>
			<p><a href="<?php echo esc_url( home_url( '/reset-password/' ) ); ?>" class="button-1 button-medium"><?php esc_html_e( 'Request New Reset Link', 'extrachill-users' ); ?></a></p>
		</div>
		<?php
		return;
	}

	$message = EC_Redirect_Handler::get_message( 'ec_password_reset' );
	?>
	<div class="password-reset-form">
		<h2><?php esc_html_e( 'Set New Password', 'extrachill-users' ); ?></h2>
		<p><?php esc_html_e( 'Enter your new password below.', 'extrachill-users' ); ?></p>

		<?php if ( $message ) : ?>
			<div class="notice notice-<?php echo 'error' === $message['type'] ? 'error' : 'success'; ?>">
				<?php if ( 'error' === $message['type'] ) : ?>
					<strong><?php esc_html_e( 'Error:', 'extrachill-users' ); ?></strong>
				<?php endif; ?>
				<?php echo esc_html( $message['text'] ); ?>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ec_reset_password">
			<?php EC_Redirect_Handler::render_hidden_fields(); ?>
			<?php wp_nonce_field( 'ec_reset_password', 'ec_reset_password_nonce' ); ?>
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
} else {
	$message = EC_Redirect_Handler::get_message( 'ec_password_reset' );
	?>
	<div class="password-reset-form">
		<h2><?php esc_html_e( 'Reset Your Password', 'extrachill-users' ); ?></h2>
		<p><?php esc_html_e( 'Enter your email address and we\'ll send you a link to reset your password.', 'extrachill-users' ); ?></p>

		<?php if ( $message ) : ?>
			<div class="notice notice-<?php echo 'error' === $message['type'] ? 'error' : 'success'; ?>">
				<?php if ( 'error' === $message['type'] ) : ?>
					<strong><?php esc_html_e( 'Error:', 'extrachill-users' ); ?></strong>
				<?php endif; ?>
				<?php echo esc_html( $message['text'] ); ?>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ec_password_reset_request">
			<?php EC_Redirect_Handler::render_hidden_fields(); ?>
			<?php wp_nonce_field( 'ec_password_reset_request', 'ec_password_reset_nonce' ); ?>

			<label for="user_login"><?php esc_html_e( 'Email Address', 'extrachill-users' ); ?></label>
			<input type="email" name="user_login" id="user_login" required>

			<input type="submit" class="button-1 button-medium" value="<?php esc_attr_e( 'Send Reset Link', 'extrachill-users' ); ?>">
		</form>

		<p><a href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><?php esc_html_e( '← Back to Login', 'extrachill-users' ); ?></a></p>
	</div>
	<?php
}
