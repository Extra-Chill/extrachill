<?php
/**
 * Login/Register Block Server-Side Render
 *
 * @package ExtraChillUsers
 */

if ( is_user_logged_in() ) {
	$current_user = wp_get_current_user();
	$profile_url  = function_exists( 'ec_get_user_profile_url' )
		? ec_get_user_profile_url( $current_user->ID, $current_user->user_email )
		: home_url();
	?>
	<div class="login-already-logged-in-message">
		<p><strong>You're already logged in as <?php echo esc_html( $current_user->display_name ); ?></strong></p>
		<p>What would you like to do?</p>
		<p>
			<a href="<?php echo esc_url( home_url() ); ?>" class="button-1 button-medium">Go to Homepage</a>
			<a href="<?php echo esc_url( $profile_url ); ?>" class="button-1 button-medium">View Profile</a>
			<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="button-3 button-medium">Log Out</a>
		</p>
	</div>
	<?php
	return;
}

if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

wp_enqueue_style( 'extrachill-shared-tabs' );
wp_enqueue_script( 'extrachill-shared-tabs' );

$current_url = set_url_scheme(
	( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . strtok( $_SERVER['REQUEST_URI'], '?' )
);

$login_redirect_url = ! empty( $attributes['redirectUrl'] ) ? esc_url( $attributes['redirectUrl'] ) : $current_url;
$login_message      = EC_Redirect_Handler::get_message( 'ec_login' );

$invite_token                   = null;
$invite_artist_id               = null;
$invited_email                  = '';
$artist_name_for_invite_message = '';

if ( isset( $_GET['action'] ) && 'bp_accept_invite' === $_GET['action'] && isset( $_GET['token'] ) && isset( $_GET['artist_id'] ) ) {
	$token_from_url     = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	$artist_id_from_url = absint( $_GET['artist_id'] );

	if ( function_exists( 'bp_get_pending_invitations' ) ) {
		$pending_invitations = bp_get_pending_invitations( $artist_id_from_url );
		foreach ( $pending_invitations as $invite ) {
			if ( isset( $invite['token'] ) && $invite['token'] === $token_from_url && isset( $invite['status'] ) && 'invited_new_user' === $invite['status'] ) {
				$invite_token             = $token_from_url;
				$invite_artist_id         = $artist_id_from_url;
				$invited_email            = isset( $invite['email'] ) ? sanitize_email( $invite['email'] ) : '';
				$artist_post_for_invite   = get_post( $invite_artist_id );
				if ( $artist_post_for_invite ) {
					$artist_name_for_invite_message = $artist_post_for_invite->post_title;
				}
				break;
			}
		}
	}
}

$register_message = EC_Redirect_Handler::get_message( 'ec_registration' );
?>

<div class="shared-tabs-component">
	<div class="shared-tabs-buttons-container">
		<!-- Login Tab -->
		<div class="shared-tab-item">
			<button type="button" class="shared-tab-button active" data-tab="tab-login">
				Login
				<span class="shared-tab-arrow open"></span>
			</button>
			<div id="tab-login" class="shared-tab-pane active">
				<div class="login-register-form">
					<h2><?php esc_html_e( 'Login to Extra Chill', 'extrachill-users' ); ?></h2>
					<p><?php esc_html_e( 'Welcome back! Log in to your account.', 'extrachill-users' ); ?></p>

					<?php if ( $login_message ) : ?>
						<div class="notice notice-<?php echo 'error' === $login_message['type'] ? 'error' : 'success'; ?>">
							<?php if ( 'error' === $login_message['type'] ) : ?>
								<strong><?php esc_html_e( 'Error:', 'extrachill-users' ); ?></strong>
							<?php endif; ?>
							<?php echo esc_html( $login_message['text'] ); ?>
							<?php if ( 'error' === $login_message['type'] ) : ?>
								<a href="https://community.extrachill.com/reset-password/"><?php esc_html_e( 'Forgot your password?', 'extrachill-users' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<form id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">
						<?php EC_Redirect_Handler::render_hidden_fields( 'tab-login' ); ?>

						<label for="user_login"><?php esc_html_e( 'Username', 'extrachill-users' ); ?></label>
						<input type="text" name="log" id="user_login" class="input" placeholder="<?php esc_attr_e( 'Your username', 'extrachill-users' ); ?>" required>

						<label for="user_pass"><?php esc_html_e( 'Password', 'extrachill-users' ); ?></label>
						<input type="password" name="pwd" id="user_pass" class="input" placeholder="<?php esc_attr_e( 'Your password', 'extrachill-users' ); ?>" required>

						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $login_redirect_url ); ?>">

						<input type="submit" id="wp-submit" class="button-2 button-medium" value="<?php esc_attr_e( 'Log In', 'extrachill-users' ); ?>">
					</form>

					<p class="login-signup-link"><?php esc_html_e( 'Not a member?', 'extrachill-users' ); ?> <a href="#tab-register" class="js-switch-to-register"><?php esc_html_e( 'Sign up here', 'extrachill-users' ); ?></a></p>
				</div>
			</div>
		</div>

		<!-- Register Tab -->
		<div class="shared-tab-item">
			<button type="button" class="shared-tab-button" data-tab="tab-register">
				Register
				<span class="shared-tab-arrow"></span>
			</button>
			<div id="tab-register" class="shared-tab-pane">
				<div class="login-register-form">
					<h2>Join the Extra Chill Community</h2>
					<p>Sign up to connect with music lovers, artists, and professionals in the online music scene! It's free and easy.</p>

					<?php if ( ! empty( $artist_name_for_invite_message ) && ! empty( $invite_token ) ) : ?>
						<div class="notice notice-invite">
							<p><?php echo sprintf( esc_html__( 'You have been invited to join the artist \'%s\'! Please complete your registration below to accept.', 'extrachill-users' ), esc_html( $artist_name_for_invite_message ) ); ?></p>
						</div>
					<?php endif; ?>

					<?php if ( $register_message ) : ?>
						<div class="notice notice-<?php echo 'error' === $register_message['type'] ? 'error' : 'success'; ?>">
							<?php if ( 'error' === $register_message['type'] ) : ?>
								<strong><?php esc_html_e( 'Error:', 'extrachill-users' ); ?></strong>
							<?php endif; ?>
							<?php echo esc_html( $register_message['text'] ); ?>
						</div>
					<?php endif; ?>

					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="extrachill_register_user">
						<?php EC_Redirect_Handler::render_hidden_fields( 'tab-register' ); ?>
						<?php wp_nonce_field( 'extrachill_register_nonce', 'extrachill_register_nonce_field' ); ?>
						<?php if ( ! empty( $attributes['redirectUrl'] ) ) : ?>
							<input type="hidden" name="success_redirect_url" value="<?php echo esc_url( $attributes['redirectUrl'] ); ?>">
						<?php endif; ?>
						<?php if ( $invite_token && $invite_artist_id ) : ?>
							<input type="hidden" name="invite_token" value="<?php echo esc_attr( $invite_token ); ?>">
							<input type="hidden" name="invite_artist_id" value="<?php echo esc_attr( $invite_artist_id ); ?>">
						<?php endif; ?>

						<label for="extrachill_username"><?php esc_html_e( 'Username', 'extrachill-users' ); ?> <small>(<?php esc_html_e( 'required', 'extrachill-users' ); ?>)</small></label>
						<input type="text" name="extrachill_username" id="extrachill_username" placeholder="<?php esc_attr_e( 'Choose a username', 'extrachill-users' ); ?>" required>

						<label for="extrachill_email"><?php esc_html_e( 'Email', 'extrachill-users' ); ?></label>
						<input type="email" name="extrachill_email" id="extrachill_email" placeholder="<?php esc_attr_e( 'you@example.com', 'extrachill-users' ); ?>" required value="<?php echo esc_attr( $invited_email ); ?>">

						<label for="extrachill_password"><?php esc_html_e( 'Password', 'extrachill-users' ); ?></label>
						<input type="password" name="extrachill_password" id="extrachill_password" placeholder="<?php esc_attr_e( 'Create a password', 'extrachill-users' ); ?>" required>

						<label for="extrachill_password_confirm"><?php esc_html_e( 'Confirm Password', 'extrachill-users' ); ?></label>
						<input type="password" name="extrachill_password_confirm" id="extrachill_password_confirm" placeholder="<?php esc_attr_e( 'Repeat your password', 'extrachill-users' ); ?>" required>

						<div class="registration-user-types">
							<label>
								<input type="checkbox" id="user_is_fan" checked disabled> <?php esc_html_e( 'I love music', 'extrachill-users' ); ?>
							</label>
							<label>
								<input type="checkbox" name="user_is_artist" id="user_is_artist" value="1"> <?php esc_html_e( 'I am a musician', 'extrachill-users' ); ?>
								<small>(<?php esc_html_e( 'required for artist profiles and link pages', 'extrachill-users' ); ?>)</small>
							</label>
							<label>
								<input type="checkbox" name="user_is_professional" id="user_is_professional" value="1"> <?php esc_html_e( 'I work in the music industry', 'extrachill-users' ); ?>
								<small>(<?php esc_html_e( 'required for artist profiles and link pages', 'extrachill-users' ); ?>)</small>
							</label>
						</div>

						<div class="registration-submit-section">
							<input type="submit" name="extrachill_register" class="button-1 button-medium" value="<?php esc_attr_e( 'Join Now', 'extrachill-users' ); ?>">
						</div>

						<?php echo ec_render_turnstile_widget(); ?>
					</form>
				</div>
			</div>
		</div>
	</div>

	<!-- Desktop Tab Content Area -->
	<div class="shared-desktop-tab-content-area" style="display: none;"></div>
</div>

<?php
do_action( 'extrachill_below_login_register_form' );
