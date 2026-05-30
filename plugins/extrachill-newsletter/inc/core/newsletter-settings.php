<?php
/**
 * Newsletter Settings Page
 *
 * Admin UI for Sendy API configuration and integration management.
 * Dynamically discovers registered integrations via filter.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
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
	$settings['sendy_url'] = isset( $_POST['sendy_url'] ) ? esc_url_raw( wp_unslash( $_POST['sendy_url'] ) ) : EXTRACHILL_NEWSLETTER_SENDY_URL_DEFAULT;

	// Email Configuration
	$settings['from_name'] = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : 'Extra Chill';
	$settings['from_email'] = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : 'newsletter@extrachill.com';
	$settings['reply_to'] = isset( $_POST['reply_to'] ) ? sanitize_email( wp_unslash( $_POST['reply_to'] ) ) : 'chubes@extrachill.com';
	$settings['brand_id'] = isset( $_POST['brand_id'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_id'] ) ) : '1';

	// Sendy DB connection (read-only campaign queries). Credentials are stored
	// explicitly here rather than scraped from Sendy's config.php. The password
	// is preserved when the field is submitted empty so it is never wiped or
	// echoed back into the page.
	$existing       = get_site_option( 'extrachill_newsletter_settings', array() );
	$existing_db    = isset( $existing['sendy_db'] ) && is_array( $existing['sendy_db'] ) ? $existing['sendy_db'] : array();
	$submitted_pass = isset( $_POST['sendy_db_pass'] ) ? wp_unslash( $_POST['sendy_db_pass'] ) : '';

	$settings['sendy_db'] = array(
		'host' => isset( $_POST['sendy_db_host'] ) ? sanitize_text_field( wp_unslash( $_POST['sendy_db_host'] ) ) : '',
		'user' => isset( $_POST['sendy_db_user'] ) ? sanitize_text_field( wp_unslash( $_POST['sendy_db_user'] ) ) : '',
		'pass' => '' !== $submitted_pass ? $submitted_pass : ( isset( $existing_db['pass'] ) ? $existing_db['pass'] : '' ),
		'name' => isset( $_POST['sendy_db_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sendy_db_name'] ) ) : '',
		'port' => isset( $_POST['sendy_db_port'] ) ? sanitize_text_field( wp_unslash( $_POST['sendy_db_port'] ) ) : '',
	);

	// Integration Sendy list IDs
	$integrations = get_newsletter_integrations();
	foreach ( $integrations as $context => $integration ) {
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

			<!-- Sendy Database Connection -->
			<?php
			$sendy_db = isset( $settings['sendy_db'] ) && is_array( $settings['sendy_db'] ) ? $settings['sendy_db'] : array();
			$sendy_db = wp_parse_args(
				$sendy_db,
				array(
					'host' => '',
					'user' => '',
					'pass' => '',
					'name' => '',
					'port' => '',
				)
			);
			?>
			<h2><?php _e( 'Sendy Database Connection', 'extrachill-newsletter' ); ?></h2>
			<p class="description"><?php _e( 'Read-only credentials for the Sendy MySQL database (used to list and inspect campaigns). Leave blank if the credentials are supplied via the extrachill_newsletter_sendy_db filter in wp-config.php. The password is write-only — leave it blank to keep the saved value.', 'extrachill-newsletter' ); ?></p>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="sendy_db_host"><?php _e( 'DB Host', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="text" id="sendy_db_host" name="sendy_db_host" value="<?php echo esc_attr( $sendy_db['host'] ); ?>" class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sendy_db_name"><?php _e( 'DB Name', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="text" id="sendy_db_name" name="sendy_db_name" value="<?php echo esc_attr( $sendy_db['name'] ); ?>" class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sendy_db_user"><?php _e( 'DB User', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="text" id="sendy_db_user" name="sendy_db_user" value="<?php echo esc_attr( $sendy_db['user'] ); ?>" class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sendy_db_pass"><?php _e( 'DB Password', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="password" id="sendy_db_pass" name="sendy_db_pass" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $sendy_db['pass'] ) ? esc_attr__( '(saved — leave blank to keep)', 'extrachill-newsletter' ) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sendy_db_port"><?php _e( 'DB Port', 'extrachill-newsletter' ); ?></label>
						</th>
						<td>
							<input type="text" id="sendy_db_port" name="sendy_db_port" value="<?php echo esc_attr( $sendy_db['port'] ); ?>" class="small-text" autocomplete="off" />
							<p class="description"><?php _e( 'Optional. Leave blank for the default MySQL port.', 'extrachill-newsletter' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Integration Configuration -->
			<h2><?php _e( 'Form Integrations', 'extrachill-newsletter' ); ?></h2>
			<p class="description"><?php _e( 'Configure Sendy list IDs for each subscription form context.', 'extrachill-newsletter' ); ?></p>

			<table class="form-table">
				<tbody>
					<?php foreach ( $integrations as $context => $integration ): ?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $integration['list_id_key'] ); ?>"><?php echo esc_html( $integration['label'] ); ?></label>
						</th>
						<td>
							<input type="text"
								   id="<?php echo esc_attr( $integration['list_id_key'] ); ?>"
								   name="<?php echo esc_attr( $integration['list_id_key'] ); ?>"
								   value="<?php echo esc_attr( $settings[ $integration['list_id_key'] ] ?? '' ); ?>"
								   class="regular-text" />
							<p class="description"><?php echo esc_html( $integration['description'] ); ?></p>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Newsletter Settings', 'extrachill-newsletter' ) ); ?>
		</form>
	</div>
	<?php
}

function get_newsletter_settings() {
	$ability = wp_get_ability( 'extrachill/get-newsletter-settings' );
	if ( $ability ) {
		$result = $ability->execute( array() );
		if ( ! is_wp_error( $result ) ) {
			// Flatten ability output back to legacy flat format for admin UI.
			$settings = $result['settings'];
			foreach ( $result['integrations'] as $context => $integration ) {
				$integrations = get_newsletter_integrations();
				if ( isset( $integrations[ $context ]['list_id_key'] ) ) {
					$settings[ $integrations[ $context ]['list_id_key'] ] = $integration['list_id'];
				}
			}
			return $settings;
		}
	}

	// Legacy fallback below.
	$defaults = extrachill_newsletter_default_settings();

	// Add defaults for registered integrations (list IDs only)
	$integrations = get_newsletter_integrations();
	foreach ( $integrations as $context => $integration ) {
		$defaults[ $integration['list_id_key'] ] = '';
	}

	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	return wp_parse_args( $settings, $defaults );
}
