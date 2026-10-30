<?php
/**
 * ExtraChill Network OAuth Settings
 *
 * Network admin page for configuring OAuth provider credentials.
 * Supports Google Sign-In and Apple Sign-In for unified authentication.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_add_network_oauth_menu' );

/**
 * Add OAuth settings page to network admin menu
 */
function ec_add_network_oauth_menu() {
	add_submenu_page(
		EXTRACHILL_NETWORK_MENU_SLUG,
		'OAuth Settings',
		'OAuth',
		'manage_network_options',
		'extrachill-oauth',
		'ec_render_network_oauth_page'
	);
}

add_action( 'network_admin_edit_extrachill_oauth', 'ec_handle_network_oauth_save' );

/**
 * Handle OAuth settings form submission
 */
function ec_handle_network_oauth_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-network' ) );
	}

	check_admin_referer( 'ec_oauth_settings', 'ec_oauth_nonce' );

	// Google OAuth
	$google_client_id         = isset( $_POST['ec_google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ec_google_client_id'] ) ) : '';
	$google_client_secret     = isset( $_POST['ec_google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['ec_google_client_secret'] ) ) : '';
	$google_ios_client_id     = isset( $_POST['ec_google_ios_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ec_google_ios_client_id'] ) ) : '';
	$google_android_client_id = isset( $_POST['ec_google_android_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ec_google_android_client_id'] ) ) : '';

	update_site_option( 'extrachill_google_client_id', $google_client_id );
	update_site_option( 'extrachill_google_client_secret', $google_client_secret );
	update_site_option( 'extrachill_google_ios_client_id', $google_ios_client_id );
	update_site_option( 'extrachill_google_android_client_id', $google_android_client_id );

	// Apple Sign-In
	$apple_client_id   = isset( $_POST['ec_apple_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ec_apple_client_id'] ) ) : '';
	$apple_team_id     = isset( $_POST['ec_apple_team_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ec_apple_team_id'] ) ) : '';
	$apple_key_id      = isset( $_POST['ec_apple_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ec_apple_key_id'] ) ) : '';
	$apple_private_key = isset( $_POST['ec_apple_private_key'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ec_apple_private_key'] ) ) : '';

	update_site_option( 'extrachill_apple_client_id', $apple_client_id );
	update_site_option( 'extrachill_apple_team_id', $apple_team_id );
	update_site_option( 'extrachill_apple_key_id', $apple_key_id );
	update_site_option( 'extrachill_apple_private_key', $apple_private_key );

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-oauth',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render network OAuth settings page
 */
function ec_render_network_oauth_page() {
	$google_client_id         = get_site_option( 'extrachill_google_client_id', '' );
	$google_client_secret     = get_site_option( 'extrachill_google_client_secret', '' );
	$google_ios_client_id     = get_site_option( 'extrachill_google_ios_client_id', '' );
	$google_android_client_id = get_site_option( 'extrachill_google_android_client_id', '' );
	$google_configured        = ec_is_google_oauth_configured();

	$apple_client_id   = get_site_option( 'extrachill_apple_client_id', '' );
	$apple_team_id     = get_site_option( 'extrachill_apple_team_id', '' );
	$apple_key_id      = get_site_option( 'extrachill_apple_key_id', '' );
	$apple_private_key = get_site_option( 'extrachill_apple_private_key', '' );
	$apple_configured  = ec_is_apple_oauth_configured();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill OAuth Settings', 'extrachill-network' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag from the post-save redirect; no data is processed. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'OAuth settings updated successfully.', 'extrachill-network' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="edit.php?action=extrachill_oauth">
			<?php wp_nonce_field( 'ec_oauth_settings', 'ec_oauth_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<!-- Google OAuth Section -->
					<tr>
						<th colspan="2">
							<h2><?php esc_html_e( 'Google Sign-In', 'extrachill-network' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Configure Google OAuth for "Continue with Google" authentication.', 'extrachill-network' ); ?>
								<?php if ( $google_configured ) : ?>
									<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Configured', 'extrachill-network' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-network' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_google_client_id"><?php esc_html_e( 'Client ID', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="ec_google_client_id"
									name="ec_google_client_id"
									value="<?php echo esc_attr( $google_client_id ); ?>"
									class="regular-text"
									placeholder="123456789-abc.apps.googleusercontent.com" />
							<p class="description">
								<?php esc_html_e( 'OAuth 2.0 Client ID from Google Cloud Console.', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_google_client_secret"><?php esc_html_e( 'Client Secret', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="password"
									id="ec_google_client_secret"
									name="ec_google_client_secret"
									value="<?php echo esc_attr( $google_client_secret ); ?>"
									class="regular-text"
									placeholder="GOCSPX-..." />
							<p class="description">
								<?php esc_html_e( 'OAuth 2.0 Client Secret. Keep this confidential.', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_google_ios_client_id"><?php esc_html_e( 'iOS Client ID', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="ec_google_ios_client_id"
									name="ec_google_ios_client_id"
									value="<?php echo esc_attr( $google_ios_client_id ); ?>"
									class="regular-text"
									placeholder="123456789-xyz.apps.googleusercontent.com" />
							<p class="description">
								<?php esc_html_e( 'OAuth 2.0 Client ID for iOS app (created with iOS application type).', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_google_android_client_id"><?php esc_html_e( 'Android Client ID', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="ec_google_android_client_id"
									name="ec_google_android_client_id"
									value="<?php echo esc_attr( $google_android_client_id ); ?>"
									class="regular-text"
									placeholder="123456789-abc.apps.googleusercontent.com" />
							<p class="description">
								<?php esc_html_e( 'OAuth 2.0 Client ID for Android app (created with Android application type and SHA-1 fingerprint).', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>

					<!-- Apple Sign-In Section -->
					<tr>
						<th colspan="2" style="padding-top: 30px;">
							<h2><?php esc_html_e( 'Apple Sign-In', 'extrachill-network' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Configure Apple Sign-In for "Continue with Apple" authentication.', 'extrachill-network' ); ?>
								<?php if ( $apple_configured ) : ?>
									<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Configured', 'extrachill-network' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-network' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_apple_client_id"><?php esc_html_e( 'Services ID', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="ec_apple_client_id"
									name="ec_apple_client_id"
									value="<?php echo esc_attr( $apple_client_id ); ?>"
									class="regular-text"
									placeholder="com.extrachill.auth" />
							<p class="description">
								<?php esc_html_e( 'Services ID identifier from Apple Developer Portal.', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_apple_team_id"><?php esc_html_e( 'Team ID', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="ec_apple_team_id"
									name="ec_apple_team_id"
									value="<?php echo esc_attr( $apple_team_id ); ?>"
									class="regular-text"
									placeholder="ABC123DEF4" />
							<p class="description">
								<?php esc_html_e( 'Your Apple Developer Team ID (10 characters).', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_apple_key_id"><?php esc_html_e( 'Key ID', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="ec_apple_key_id"
									name="ec_apple_key_id"
									value="<?php echo esc_attr( $apple_key_id ); ?>"
									class="regular-text"
									placeholder="XYZ789GHI0" />
							<p class="description">
								<?php esc_html_e( 'Key ID for your Sign In with Apple private key.', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_apple_private_key"><?php esc_html_e( 'Private Key', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<textarea id="ec_apple_private_key"
										name="ec_apple_private_key"
										rows="6"
										class="large-text code"
										placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"><?php echo esc_textarea( $apple_private_key ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Contents of your .p8 private key file. Keep this confidential.', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save OAuth Settings', 'extrachill-network' ) ); ?>
		</form>

		<div class="card" style="margin-top: 20px; max-width: 800px;">
			<h3><?php esc_html_e( 'Google Setup Instructions', 'extrachill-network' ); ?></h3>
			<p><strong><?php esc_html_e( 'Web Client (required for web and token verification):', 'extrachill-network' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Go to Google Cloud Console (console.cloud.google.com)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Create a new project or select an existing one', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Navigate to APIs & Services > Credentials', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Create OAuth 2.0 Client ID (Web application type)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Add authorized JavaScript origins for your domains', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Copy the Client ID and Client Secret', 'extrachill-network' ); ?></li>
			</ol>
			<p><strong><?php esc_html_e( 'iOS Client (for native mobile app):', 'extrachill-network' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Create another OAuth 2.0 Client ID (iOS application type)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Enter your iOS bundle ID (e.g., com.extrachill.app)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Copy the iOS Client ID', 'extrachill-network' ); ?></li>
			</ol>
			<p><strong><?php esc_html_e( 'Android Client (for native mobile app):', 'extrachill-network' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Create another OAuth 2.0 Client ID (Android application type)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Enter your Android package name (e.g., com.extrachill.app)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Add SHA-1 certificate fingerprint for your signing key', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Copy the Android Client ID', 'extrachill-network' ); ?></li>
			</ol>
		</div>

		<div class="card" style="margin-top: 20px; max-width: 800px;">
			<h3><?php esc_html_e( 'Apple Setup Instructions', 'extrachill-network' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Go to Apple Developer Portal (developer.apple.com)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Navigate to Certificates, Identifiers & Profiles', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Create a Services ID with Sign In with Apple enabled', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Configure your domain and return URLs', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Create a Key with Sign In with Apple enabled', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Download the .p8 key file and copy its contents here', 'extrachill-network' ); ?></li>
			</ol>
		</div>
	</div>

	<style>
		.card {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 20px;
			margin: 20px 0;
		}
		.card h3 {
			margin-top: 0;
		}
	</style>
	<?php
}
