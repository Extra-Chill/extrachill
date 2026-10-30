<?php
/**
 * ExtraChill Network Integrations Settings
 *
 * Network admin page for configuring integration credentials consumed by the
 * Data Machine Business analytics/revenue abilities. This is a thin UI wrapper:
 * option names and storage scope are owned by Data Machine Business.
 *
 * @package ExtraChill\Network
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'ec_add_network_integrations_menu' );

/**
 * Add the Integrations submenu page under Extra Chill Network.
 */
function ec_add_network_integrations_menu() {
	add_submenu_page(
		EXTRACHILL_NETWORK_MENU_SLUG,
		'Integrations Settings',
		'Integrations',
		'manage_network_options',
		'extrachill-integrations',
		'ec_render_network_integrations_page'
	);
}

add_action( 'network_admin_edit_extrachill_integrations', 'ec_handle_network_integrations_save' );

/**
 * Integration registry.
 *
 * Mirrors the option names and required keys used by Data Machine Business.
 * All options are network-scoped (get_site_option / update_site_option).
 *
 * @return array<int, array<string, mixed>>
 */
function ec_network_integrations(): array {
	return array(
		array(
			'id'            => 'mediavine',
			'title'         => __( 'Mediavine', 'extrachill-network' ),
			'description'   => __( 'Mediavine publisher dashboard credentials for revenue reports.', 'extrachill-network' ),
			'option_name'   => 'datamachine_mediavine_config',
			'required_keys' => array( 'email', 'password' ),
			'optional_keys' => array( 'site_id' ),
			'fields'        => array(
				array(
					'key'         => 'email',
					'label'       => __( 'Email', 'extrachill-network' ),
					'type'        => 'email',
					'secret'      => false,
					'placeholder' => 'publisher@example.com',
				),
				array(
					'key'         => 'password',
					'label'       => __( 'Password', 'extrachill-network' ),
					'type'        => 'password',
					'secret'      => true,
					'placeholder' => __( '•••• (set — leave blank to keep)', 'extrachill-network' ),
				),
				array(
					'key'         => 'site_id',
					'label'       => __( 'Site ID', 'extrachill-network' ),
					'type'        => 'text',
					'secret'      => false,
					'placeholder' => __( 'Mediavine site id', 'extrachill-network' ),
				),
			),
		),
		array(
			'id'            => 'google_analytics',
			'title'         => __( 'Google Analytics', 'extrachill-network' ),
			'description'   => __( 'GA4 service account JSON and property ID.', 'extrachill-network' ),
			'option_name'   => 'datamachine_ga_config',
			'required_keys' => array( 'service_account_json', 'property_id' ),
			'optional_keys' => array(),
			'fields'        => array(
				array(
					'key'         => 'service_account_json',
					'label'       => __( 'Service Account JSON', 'extrachill-network' ),
					'type'        => 'textarea',
					'secret'      => true,
					'placeholder' => __( '{ "type": "service_account", ... }', 'extrachill-network' ),
				),
				array(
					'key'         => 'property_id',
					'label'       => __( 'Property ID', 'extrachill-network' ),
					'type'        => 'text',
					'secret'      => false,
					'placeholder' => '123456789',
				),
			),
		),
		array(
			'id'            => 'google_search_console',
			'title'         => __( 'Google Search Console', 'extrachill-network' ),
			'description'   => __( 'GSC service account JSON and verified site URL.', 'extrachill-network' ),
			'option_name'   => 'datamachine_gsc_config',
			'required_keys' => array( 'service_account_json' ),
			'optional_keys' => array( 'site_url' ),
			'fields'        => array(
				array(
					'key'         => 'service_account_json',
					'label'       => __( 'Service Account JSON', 'extrachill-network' ),
					'type'        => 'textarea',
					'secret'      => true,
					'placeholder' => __( '{ "type": "service_account", ... }', 'extrachill-network' ),
				),
				array(
					'key'         => 'site_url',
					'label'       => __( 'Site URL', 'extrachill-network' ),
					'type'        => 'text',
					'secret'      => false,
					'placeholder' => 'sc-domain:example.com',
				),
			),
		),
		array(
			'id'            => 'bing_webmaster',
			'title'         => __( 'Bing Webmaster Tools', 'extrachill-network' ),
			'description'   => __( 'Bing Webmaster Tools API key and site URL.', 'extrachill-network' ),
			'option_name'   => 'datamachine_bing_webmaster_config',
			'required_keys' => array( 'api_key' ),
			'optional_keys' => array( 'site_url' ),
			'fields'        => array(
				array(
					'key'         => 'api_key',
					'label'       => __( 'API Key', 'extrachill-network' ),
					'type'        => 'password',
					'secret'      => true,
					'placeholder' => __( '•••• (set — leave blank to keep)', 'extrachill-network' ),
				),
				array(
					'key'         => 'site_url',
					'label'       => __( 'Site URL', 'extrachill-network' ),
					'type'        => 'text',
					'secret'      => false,
					'placeholder' => 'https://example.com/',
				),
			),
		),
	);
}

/**
 * Whether Data Machine Business is active.
 *
 * @return bool
 */
function ec_is_data_machine_business_active(): bool {
	return class_exists( 'DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities' );
}

/**
 * Check whether an integration is configured.
 *
 * @param array<string, mixed> $integration Integration spec.
 * @return bool
 */
function ec_is_integration_configured( array $integration ): bool {
	$config = get_site_option( $integration['option_name'], array() );

	foreach ( $integration['required_keys'] as $key ) {
		if ( empty( $config[ $key ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Handle Integrations settings form submission.
 */
function ec_handle_network_integrations_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-network' ) );
	}

	check_admin_referer( 'ec_integrations_settings', 'ec_integrations_nonce' );

	foreach ( ec_network_integrations() as $integration ) {
		$option_name = $integration['option_name'];
		$existing    = get_site_option( $option_name, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$updated = $existing;

		foreach ( $integration['fields'] as $field ) {
			$post_key = 'ec_integration_' . $integration['id'] . '_' . $field['key'];

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
			if ( ! isset( $_POST[ $post_key ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
			$raw = wp_unslash( $_POST[ $post_key ] );

			if ( $field['secret'] ) {
				// Blank secret fields keep the existing value.
				if ( '' === trim( (string) $raw ) ) {
					continue;
				}

				if ( 'textarea' === $field['type'] ) {
					$value = sanitize_textarea_field( $raw );
				} else {
					$value = sanitize_text_field( $raw );
				}
			} elseif ( 'email' === $field['type'] ) {
					$value = sanitize_email( $raw );
			} elseif ( 'textarea' === $field['type'] ) {
				$value = sanitize_textarea_field( $raw );
			} else {
				$value = sanitize_text_field( $raw );
			}

			$updated[ $field['key'] ] = $value;
		}

		// Validate JSON blobs.
		foreach ( $integration['fields'] as $field ) {
			if ( 'textarea' !== $field['type'] || empty( $updated[ $field['key'] ] ) ) {
				continue;
			}

			json_decode( $updated[ $field['key'] ] );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				// Revert to the existing value if the submitted JSON is invalid.
				$updated[ $field['key'] ] = $existing[ $field['key'] ] ?? '';
			}
		}

		update_site_option( $option_name, $updated );
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-integrations',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Render the Integrations settings page.
 */
function ec_render_network_integrations_page() {
	$integrations  = ec_network_integrations();
	$is_dmb_active = ec_is_data_machine_business_active();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill Integrations', 'extrachill-network' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success flag. ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Integration settings updated successfully.', 'extrachill-network' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $is_dmb_active ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php esc_html_e( 'Data Machine Business is not active. Credentials saved here will take effect once the plugin is enabled.', 'extrachill-network' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Configure the credentials used by Data Machine Business analytics and revenue abilities. Secret fields are left blank to keep their current value.', 'extrachill-network' ); ?>
		</p>

		<form method="post" action="edit.php?action=extrachill_integrations">
			<?php wp_nonce_field( 'ec_integrations_settings', 'ec_integrations_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<?php foreach ( $integrations as $integration ) : ?>
						<?php ec_render_integration_section( $integration ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Integrations', 'extrachill-network' ) ); ?>
		</form>
	</div>

	<style>
		.extrachill-integration-section th {
			padding-top: 30px;
		}
		.extrachill-integration-section:first-of-type th {
			padding-top: 0;
		}
	</style>
	<?php
}

/**
 * Render a single integration section.
 *
 * @param array<string, mixed> $integration Integration spec.
 */
function ec_render_integration_section( array $integration ) {
	$config     = get_site_option( $integration['option_name'], array() );
	$is_set     = ec_is_integration_configured( $integration );
	$section_id = 'ec-integration-' . esc_attr( $integration['id'] );
	?>
	<tr class="extrachill-integration-section" id="<?php echo esc_attr( $section_id ); ?>">
		<th colspan="2">
			<h2><?php echo esc_html( $integration['title'] ); ?></h2>
			<p class="description">
				<?php echo esc_html( $integration['description'] ); ?>
				<?php if ( $is_set ) : ?>
					<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Configured', 'extrachill-network' ); ?></span>
				<?php else : ?>
					<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-network' ); ?></span>
				<?php endif; ?>
			</p>
		</th>
	</tr>
	<?php foreach ( $integration['fields'] as $field ) : ?>
		<?php
		$input_id   = 'ec_integration_' . $integration['id'] . '_' . $field['key'];
		$post_key   = 'ec_integration_' . $integration['id'] . '_' . $field['key'];
		$is_secret  = ! empty( $field['secret'] );
		$has_value  = ! empty( $config[ $field['key'] ] );
		$input_type = in_array( $field['type'], array( 'text', 'email', 'password', 'textarea' ), true ) ? $field['type'] : 'text';
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			</th>
			<td>
				<?php if ( 'textarea' === $input_type ) : ?>
					<textarea id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $post_key ); ?>"
							rows="6"
							class="large-text code"
							placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
							<?php echo $is_secret ? 'autocomplete="new-password"' : ''; ?>><?php echo $is_secret ? '' : esc_textarea( $config[ $field['key'] ] ?? '' ); ?></textarea>
				<?php else : ?>
					<input type="<?php echo esc_attr( $input_type ); ?>"
							id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $post_key ); ?>"
							value="<?php echo $is_secret ? '' : esc_attr( $config[ $field['key'] ] ?? '' ); ?>"
							class="regular-text<?php echo 'textarea' === $input_type ? ' code' : ''; ?>"
							placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
							<?php echo $is_secret ? 'autocomplete="new-password"' : ''; ?> />
				<?php endif; ?>

				<?php if ( $is_secret && $has_value ) : ?>
					<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Set', 'extrachill-network' ); ?></span>
				<?php elseif ( $is_secret ) : ?>
					<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not set', 'extrachill-network' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	<?php
}
