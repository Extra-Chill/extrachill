<?php
/**
 * ExtraChill Network Security Settings
 *
 * Network admin page for configuring security settings across the multisite network,
 * including Cloudflare Turnstile configuration.
 *
 * @package ExtraChill\Network
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_add_network_security_menu' );

/**
 * Add security settings page to network admin menu
 *
 * @since 1.0.0
 */
function ec_add_network_security_menu() {
	add_submenu_page(
		EXTRACHILL_NETWORK_MENU_SLUG,
		'Security Settings',
		'Security',
		'manage_network_options',
		'extrachill-security',
		'ec_render_network_security_page'
	);
}

add_action( 'network_admin_edit_extrachill_security', 'ec_handle_network_security_save' );

/**
 * Handle security settings form submission
 *
 * @since 1.0.0
 */
function ec_handle_network_security_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-network' ) );
	}

	if ( ! wp_verify_nonce( $_POST['ec_security_nonce'], 'ec_security_settings' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'extrachill-network' ) );
	}

	// Save Turnstile settings
	$site_key   = isset( $_POST['ec_turnstile_site_key'] ) ? sanitize_text_field( $_POST['ec_turnstile_site_key'] ) : '';
	$secret_key = isset( $_POST['ec_turnstile_secret_key'] ) ? sanitize_text_field( $_POST['ec_turnstile_secret_key'] ) : '';

	ec_update_turnstile_site_key( $site_key );
	ec_update_turnstile_secret_key( $secret_key );

	// Redirect back with success message
	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-security',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render network security settings page
 *
 * @since 1.0.0
 */
function ec_render_network_security_page() {
	$site_key      = ec_get_turnstile_site_key();
	$secret_key    = ec_get_turnstile_secret_key();
	$is_configured = ec_is_turnstile_configured();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill Security Settings', 'extrachill-network' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag from the post-save redirect; no data is processed. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Security settings updated successfully.', 'extrachill-network' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="edit.php?action=extrachill_security">
			<?php wp_nonce_field( 'ec_security_settings', 'ec_security_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th colspan="2">
							<h2><?php esc_html_e( 'Cloudflare Turnstile Configuration', 'extrachill-network' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Configure Cloudflare Turnstile for spam protection across all sites in the network.', 'extrachill-network' ); ?>
								<?php if ( $is_configured ) : ?>
									<span style="color: #46b450; font-weight: bold;">✓ <?php esc_html_e( 'Currently configured', 'extrachill-network' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">⚠ <?php esc_html_e( 'Not configured', 'extrachill-network' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_turnstile_site_key"><?php esc_html_e( 'Site Key', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="ec_turnstile_site_key"
									name="ec_turnstile_site_key"
									value="<?php echo esc_attr( $site_key ); ?>"
									class="regular-text"
									placeholder="0x4AAAAAAAPvQsUv5Z6QBB5n" />
							<p class="description">
								<?php esc_html_e( 'The site key from your Cloudflare Turnstile dashboard. This will be used in forms across all sites.', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ec_turnstile_secret_key"><?php esc_html_e( 'Secret Key', 'extrachill-network' ); ?></label>
						</th>
						<td>
							<input type="password"
									id="ec_turnstile_secret_key"
									name="ec_turnstile_secret_key"
									value="<?php echo esc_attr( $secret_key ); ?>"
									class="regular-text"
									placeholder="0x4AAAAAAAPvQp7DbBfqJD7LW-gbrAkiAb0" />
							<p class="description">
								<?php esc_html_e( 'The secret key from your Cloudflare Turnstile dashboard. Used for server-side verification.', 'extrachill-network' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Affected Forms', 'extrachill-network' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'These forms across your multisite network will use the Turnstile configuration:', 'extrachill-network' ); ?>
			</p>
			<ul style="margin-left: 20px;">
				<li><?php esc_html_e( 'Contact forms (extrachill-contact plugin)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'User registration (extrachill-users plugin)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Newsletter subscriptions (extrachill-newsletter plugin)', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Festival tip submissions (extrachill-news-wire plugin)', 'extrachill-network' ); ?></li>
			</ul>

			<?php submit_button( __( 'Save Security Settings', 'extrachill-network' ) ); ?>
		</form>

		<div class="card" style="margin-top: 20px;">
			<h3><?php esc_html_e( 'Setup Instructions', 'extrachill-network' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Create a Cloudflare account and add your domain', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Navigate to Security → Turnstile in your Cloudflare dashboard', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Create a new widget for your domain', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Copy the Site Key and Secret Key from the widget settings', 'extrachill-network' ); ?></li>
				<li><?php esc_html_e( 'Paste the keys in the fields above and save', 'extrachill-network' ); ?></li>
			</ol>
			<p><strong><?php esc_html_e( 'Note:', 'extrachill-network' ); ?></strong> <?php esc_html_e( 'These settings apply to all sites in your multisite network.', 'extrachill-network' ); ?></p>
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