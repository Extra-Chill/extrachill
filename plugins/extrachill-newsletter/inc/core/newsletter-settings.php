<?php
/**
 * Newsletter Settings Page
 *
 * Admin UI for Sendy API configuration and integration management.
 * Dynamically discovers registered integrations via filter.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'ec_newsletter_add_settings_menu' );
function ec_newsletter_add_settings_menu() {
	add_submenu_page(
		'edit.php?post_type=newsletter',
		__( 'Newsletter Settings', 'extrachill-newsletter' ),
		__( 'Settings', 'extrachill-newsletter' ),
		'manage_options',
		'newsletter-settings',
		'ec_newsletter_render_settings_page'
	);
}

add_action( 'admin_post_ec_newsletter_settings', 'ec_newsletter_handle_settings_save' );
function ec_newsletter_handle_settings_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have permission to access this page.', 'extrachill-newsletter' ) );
	}

	if ( ! wp_verify_nonce( $_POST['ec_newsletter_nonce'], 'ec_newsletter_settings' ) ) {
		wp_die( __( 'Security check failed.', 'extrachill-newsletter' ) );
	}

	$settings = array();

	// API Configuration
	$settings['sendy_api_key'] = isset( $_POST['sendy_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['sendy_api_key'] ) ) : '';
	$settings['sendy_url'] = isset( $_POST['sendy_url'] ) ? esc_url_raw( wp_unslash( $_POST['sendy_url'] ) ) : 'https://mail.extrachill.com/sendy';

	// Email Configuration
	$settings['from_name'] = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : 'Extra Chill';
	$settings['from_email'] = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : 'newsletter@extrachill.com';
	$settings['reply_to'] = isset( $_POST['reply_to'] ) ? sanitize_email( wp_unslash( $_POST['reply_to'] ) ) : 'chubes@extrachill.com';
	$settings['brand_id'] = isset( $_POST['brand_id'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_id'] ) ) : '1';

	// Dynamic integration fields
	$integrations = get_newsletter_integrations();
	foreach ( $integrations as $context => $integration ) {
		$settings[ $integration['enable_key'] ] = ! empty( $_POST[ $integration['enable_key'] ] ) ? 1 : 0;
		$settings[ $integration['list_id_key'] ] = isset( $_POST[ $integration['list_id_key'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $integration['list_id_key'] ] ) ) : '';
	}

	update_site_option( 'extrachill_newsletter_settings', $settings );

	// Redirect back with success message
	$redirect_url = add_query_arg(
		array(
			'post_type' => 'newsletter',
			'page' => 'newsletter-settings',
			'updated' => 'true',
		),
		admin_url( 'edit.php' )
	);

	wp_redirect( $redirect_url );
	exit;
}
function ec_newsletter_render_settings_page() {
	$settings = get_newsletter_settings();
	$integrations = get_newsletter_integrations();
	?>
	<div class="wrap">
		<h1><?php _e( 'Newsletter Settings', 'extrachill-newsletter' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ): ?>
			<div class="notice notice-success is-dismissible">
				<p><?php _e( 'Newsletter settings updated successfully.', 'extrachill-newsletter' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ec_newsletter_settings" />
			<?php wp_nonce_field( 'ec_newsletter_settings', 'ec_newsletter_nonce' ); ?>

			<!-- API Configuration -->
			<h2><?php _e( 'API Configuration', 'extrachill-newsletter' ); ?></h2>
			<p class="description"><?php _e( 'Configure your Sendy API connection settings.', 'extrachill-newsletter' ); ?></p>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="sendy_api_key"><?php _e( 'Sendy API Key', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="password" id="sendy_api_key" name="sendy_api_key" value="<?php echo esc_attr( $settings['sendy_api_key'] ); ?>" class="regular-text" />
							<p class="description"><?php _e( 'Your Sendy API key from Sendy settings.', 'extrachill-newsletter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sendy_url"><?php _e( 'Sendy URL', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="url" id="sendy_url" name="sendy_url" value="<?php echo esc_attr( $settings['sendy_url'] ); ?>" class="regular-text" />
							<p class="description"><?php _e( 'Your Sendy installation URL (without trailing slash).', 'extrachill-newsletter' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Email Configuration -->
			<h2><?php _e( 'Email Configuration', 'extrachill-newsletter' ); ?></h2>
			<p class="description"><?php _e( 'Configure email sender information and branding.', 'extrachill-newsletter' ); ?></p>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="from_name"><?php _e( 'From Name', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="text" id="from_name" name="from_name" value="<?php echo esc_attr( $settings['from_name'] ); ?>" class="regular-text" />
							<p class="description"><?php _e( 'Name that appears in the "From" field of newsletters.', 'extrachill-newsletter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="from_email"><?php _e( 'From Email', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="email" id="from_email" name="from_email" value="<?php echo esc_attr( $settings['from_email'] ); ?>" class="regular-text" />
							<p class="description"><?php _e( 'Email address that appears in the "From" field of newsletters.', 'extrachill-newsletter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="reply_to"><?php _e( 'Reply To', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="email" id="reply_to" name="reply_to" value="<?php echo esc_attr( $settings['reply_to'] ); ?>" class="regular-text" />
							<p class="description"><?php _e( 'Email address for replies to newsletters.', 'extrachill-newsletter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="brand_id"><?php _e( 'Brand ID', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="text" id="brand_id" name="brand_id" value="<?php echo esc_attr( $settings['brand_id'] ); ?>" class="small-text" />
							<p class="description"><?php _e( 'Sendy brand ID for newsletter campaigns.', 'extrachill-newsletter' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Integration Configuration -->
			<h2><?php _e( 'Form Integrations', 'extrachill-newsletter' ); ?></h2>
			<p class="description"><?php _e( 'Configure newsletter subscription forms from this site and other sites across the network.', 'extrachill-newsletter' ); ?></p>

			<?php if ( !empty( $integrations ) ): ?>
			<div class="card" style="margin-bottom: 20px;">
				<h3><?php _e( 'Registered Integrations:', 'extrachill-newsletter' ); ?></h3>
				<ul>
					<?php foreach ( $integrations as $context => $integration ):
						$enabled = newsletter_integration_enabled( $integration['enable_key'] );
						$status = $enabled ? __( 'Enabled', 'extrachill-newsletter' ) : __( 'Disabled', 'extrachill-newsletter' );
						$status_class = $enabled ? 'enabled' : 'disabled';
					?>
					<li>
						<strong><?php echo esc_html( $integration['label'] ); ?></strong>
						<span class="integration-status <?php echo esc_attr( $status_class ); ?>">(<?php echo esc_html( $status ); ?>)</span>
						<br><em><?php echo esc_html( $integration['description'] ); ?></em>
						<?php if ( !empty( $integration['plugin'] ) ): ?>
						<br><small><?php printf( __( 'Provided by: %s', 'extrachill-newsletter' ), esc_html( $integration['plugin'] ) ); ?></small>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<table class="form-table">
				<tbody>
					<?php foreach ( $integrations as $context => $integration ): ?>
					<tr>
						<th scope="row"><?php echo esc_html( $integration['label'] ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									   name="<?php echo esc_attr( $integration['enable_key'] ); ?>"
									   value="1"
									   <?php checked( ! empty( $settings[ $integration['enable_key'] ] ) ); ?> />
								<?php printf( __( 'Enable %s', 'extrachill-newsletter' ), esc_html( $integration['label'] ) ); ?>
							</label>
							<?php if ( ! empty( $integration['description'] ) ): ?>
							<p class="description"><?php echo esc_html( $integration['description'] ); ?></p>
							<?php endif; ?>
							<br>
							<label for="<?php echo esc_attr( $integration['list_id_key'] ); ?>"><?php _e( 'List ID:', 'extrachill-newsletter' ); ?></label>
							<input type="text"
								   id="<?php echo esc_attr( $integration['list_id_key'] ); ?>"
								   name="<?php echo esc_attr( $integration['list_id_key'] ); ?>"
								   value="<?php echo esc_attr( $settings[ $integration['list_id_key'] ] ?? '' ); ?>"
								   class="regular-text" />
							<p class="description"><?php printf( __( 'Sendy list ID for %s subscriptions.', 'extrachill-newsletter' ), esc_html( $integration['label'] ) ); ?></p>
							<?php if ( ! empty( $integration['plugin'] ) ): ?>
							<p class="description"><small><?php printf( __( 'Provided by: %s', 'extrachill-newsletter' ), esc_html( $integration['plugin'] ) ); ?></small></p>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Newsletter Settings', 'extrachill-newsletter' ) ); ?>
		</form>
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
		.card ul {
			list-style: none;
			padding-left: 0;
		}
		.card li {
			padding: 10px 0;
			border-bottom: 1px solid #eee;
		}
		.card li:last-child {
			border-bottom: none;
		}
		.integration-status.enabled {
			color: #46b450;
			font-weight: bold;
		}
		.integration-status.disabled {
			color: #dc3232;
			font-weight: bold;
		}
	</style>
	<?php
}
function get_newsletter_settings() {
	$defaults = array(
		'sendy_api_key' => '',
		'sendy_url' => 'https://mail.extrachill.com/sendy',
		'from_name' => 'Extra Chill',
		'from_email' => 'newsletter@extrachill.com',
		'reply_to' => 'chubes@extrachill.com',
		'brand_id' => '1',
	);

	// Add defaults for registered integrations
	$integrations = get_newsletter_integrations();
	foreach ( $integrations as $context => $integration ) {
		$defaults[ $integration['enable_key'] ] = 1; // Enable by default
		$defaults[ $integration['list_id_key'] ] = '';
	}

	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	return wp_parse_args( $settings, $defaults );
}
