<?php
/**
 * Login/Register Block Server-Side Render
 *
 * @package ExtraChillUsers
 */

if ( is_user_logged_in() ) {
	$current_user = wp_get_current_user();
	$profile_url  = ec_get_site_url( 'community' ) . '/u/' . $current_user->user_nicename . '/';
	?>
	<div class="login-already-logged-in-card">
		<div class="logged-in-avatar">
			<?php echo get_avatar( $current_user->ID, 80 ); ?>
		</div>
		<h3><?php echo esc_html( $current_user->display_name ); ?></h3>
		<p class="logged-in-status"><?php esc_html_e( 'You are logged in', 'extrachill-users' ); ?></p>
		<div class="logged-in-actions">
			<a href="<?php echo esc_url( $profile_url ); ?>" class="button-1 button-medium"><?php esc_html_e( 'View Profile', 'extrachill-users' ); ?></a>
			<a href="<?php echo esc_url( home_url() ); ?>" class="button-2 button-medium"><?php esc_html_e( 'Go to Homepage', 'extrachill-users' ); ?></a>
			<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="button-3 button-medium"><?php esc_html_e( 'Log Out', 'extrachill-users' ); ?></a>
		</div>
	</div>
	<?php
	return;
}

if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

wp_enqueue_style( 'extrachill-shared-tabs' );
wp_enqueue_script( 'extrachill-shared-tabs' );
wp_enqueue_script( 'extrachill-auth-utils' );

$google_oauth_enabled = function_exists( 'ec_is_google_oauth_configured' ) && ec_is_google_oauth_configured();
if ( $google_oauth_enabled ) {
	wp_enqueue_script(
		'google-gsi',
		'https://accounts.google.com/gsi/client',
		array(),
		null,
		true
	);

	$google_signin_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/js/google-signin.js';
	if ( file_exists( $google_signin_path ) ) {
		wp_enqueue_script(
			'extrachill-google-signin',
			EXTRACHILL_USERS_PLUGIN_URL . 'assets/js/google-signin.js',
			array( 'google-gsi', 'extrachill-auth-utils' ),
			filemtime( $google_signin_path ),
			true
		);

		wp_localize_script(
			'extrachill-google-signin',
			'ecGoogleConfig',
			array(
				'clientId' => get_site_option( 'extrachill_google_client_id', '' ),
				'restUrl'  => rest_url( 'extrachill/v1/' ),
			)
		);

		wp_add_inline_script(
			'extrachill-google-signin',
			'document.addEventListener("DOMContentLoaded", function() { if (window.ECGoogleSignIn && window.ecGoogleConfig) { ECGoogleSignIn.init(ecGoogleConfig); } });',
			'after'
		);
	}
}

$current_url = set_url_scheme(
	( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . strtok( $_SERVER['REQUEST_URI'], '?' )
);

$login_redirect_url = ! empty( $attributes['redirectUrl'] ) ? esc_url( $attributes['redirectUrl'] ) : $current_url;

$invite_token                   = null;
$invite_artist_id               = null;
$invited_email                  = '';
$artist_name_for_invite_message = '';

if ( isset( $_GET['action'] ) && 'ec_accept_invite' === $_GET['action'] && isset( $_GET['token'] ) && isset( $_GET['artist_id'] ) ) {
	$token_from_url     = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	$artist_id_from_url = absint( $_GET['artist_id'] );

	if ( function_exists( 'ec_get_pending_invitations' ) ) {
		$pending_invitations = ec_get_pending_invitations( $artist_id_from_url );
		foreach ( $pending_invitations as $invite ) {
			if ( isset( $invite['token'] ) && $invite['token'] === $token_from_url && isset( $invite['status'] ) && 'invited_new_user' === $invite['status'] ) {
				$invite_token             = $token_from_url;
				$invite_artist_id         = $artist_id_from_url;
				$invited_email            = isset( $invite['email'] ) ? sanitize_email( $invite['email'] ) : '';
				$artist_post_for_invite   = get_post( $invite_artist_id );
				if ( $artist_post_for_invite ) {
					$artist_name_for_invite_message = $artist_post_for_invite->post_title;
					// Set centralized notice for invitation
					extrachill_set_notice(
						sprintf( __( 'You have been invited to join the artist \'%s\'! Please complete your registration below to accept.', 'extrachill-users' ), $artist_name_for_invite_message ),
						'info'
					);
				}
				break;
			}
		}
	}
}

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

					<form id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">
						<?php EC_Redirect_Handler::render_hidden_fields( 'tab-login' ); ?>

						<label for="user_login"><?php esc_html_e( 'Username', 'extrachill-users' ); ?></label>
						<input type="text" name="log" id="user_login" class="input" placeholder="<?php esc_attr_e( 'Your username', 'extrachill-users' ); ?>" required>

						<label for="user_pass"><?php esc_html_e( 'Password', 'extrachill-users' ); ?></label>
						<input type="password" name="pwd" id="user_pass" class="input" placeholder="<?php esc_attr_e( 'Your password', 'extrachill-users' ); ?>" required>

						<div class="login-remember-me">
							<label>
								<input type="checkbox" name="rememberme" value="forever">
								<?php esc_html_e( 'Remember me', 'extrachill-users' ); ?>
							</label>
						</div>

						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $login_redirect_url ); ?>">

						<input type="submit" id="wp-submit" class="button-2 button-medium" value="<?php esc_attr_e( 'Log In', 'extrachill-users' ); ?>">
					</form>

					<?php if ( $google_oauth_enabled ) : ?>
						<div class="social-login-divider">
							<span><?php esc_html_e( 'or', 'extrachill-users' ); ?></span>
						</div>
						<div class="social-login-buttons">
							<div class="google-signin-button"></div>
						</div>
					<?php endif; ?>

					
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

					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="extrachill_register_user">
						<?php EC_Redirect_Handler::render_hidden_fields( 'tab-register' ); ?>
						<?php wp_nonce_field( 'extrachill_register_nonce', 'extrachill_register_nonce_field' ); ?>
					<input type="hidden" name="success_redirect_url" value="<?php echo esc_url( ! empty( $attributes['redirectUrl'] ) ? $attributes['redirectUrl'] : $current_url ); ?>">
						<?php if ( $invite_token && $invite_artist_id ) : ?>
							<input type="hidden" name="invite_token" value="<?php echo esc_attr( $invite_token ); ?>">
							<input type="hidden" name="invite_artist_id" value="<?php echo esc_attr( $invite_artist_id ); ?>">
						<?php endif; ?>

						<label for="extrachill_email"><?php esc_html_e( 'Email', 'extrachill-users' ); ?></label>
						<input type="email" name="extrachill_email" id="extrachill_email" placeholder="<?php esc_attr_e( 'you@example.com', 'extrachill-users' ); ?>" required value="<?php echo esc_attr( $invited_email ); ?>">

						<label for="extrachill_password"><?php esc_html_e( 'Password', 'extrachill-users' ); ?></label>
						<input type="password" name="extrachill_password" id="extrachill_password" placeholder="<?php esc_attr_e( 'Create a password', 'extrachill-users' ); ?>" required minlength="8">

						<label for="extrachill_password_confirm"><?php esc_html_e( 'Confirm Password', 'extrachill-users' ); ?></label>
						<input type="password" name="extrachill_password_confirm" id="extrachill_password_confirm" placeholder="<?php esc_attr_e( 'Repeat your password', 'extrachill-users' ); ?>" required minlength="8">

						<div class="registration-submit-section">
							<input type="submit" name="extrachill_register" class="button-1 button-medium" value="<?php esc_attr_e( 'Join Now', 'extrachill-users' ); ?>">
						</div>

						<?php echo ec_render_turnstile_widget(); ?>
					</form>

					<?php if ( $google_oauth_enabled ) : ?>
						<div class="social-login-divider">
							<span><?php esc_html_e( 'or', 'extrachill-users' ); ?></span>
						</div>
						<div class="social-login-buttons">
							<div class="google-signin-button"></div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Desktop Tab Content Area -->
	<div class="shared-desktop-tab-content-area" style="display: none;"></div>
</div>

<?php
do_action( 'extrachill_below_login_register_form' );
