<?php
/**
 * Network Tracking Settings
 *
 * Stores network-wide tracking configuration for the platform.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'extrachill_analytics_add_tracking_network_menu', 30 );

/**
 * Add Tracking submenu under Extra Chill Multisite.
 *
 * @return void
 */
function extrachill_analytics_add_tracking_network_menu() {
	add_submenu_page(
		'extrachill-multisite',
		'Tracking',
		'Tracking',
		'manage_network_options',
		'extrachill-analytics-tracking',
		'extrachill_analytics_render_tracking_settings_page'
	);
}

add_action( 'network_admin_edit_extrachill_analytics_tracking', 'extrachill_analytics_handle_tracking_settings_save' );

/**
 * Handle tracking settings form submission.
 *
 * @return void
 */
function extrachill_analytics_handle_tracking_settings_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( __( 'You do not have permission to access this page.', 'extrachill-analytics' ) );
	}

	check_admin_referer( 'extrachill_analytics_tracking_settings', 'extrachill_analytics_tracking_nonce' );

	$gtm_container_id = isset( $_POST['extrachill_gtm_container_id'] )
		? sanitize_text_field( wp_unslash( $_POST['extrachill_gtm_container_id'] ) )
		: '';

	update_site_option( EXTRACHILL_ANALYTICS_GTM_CONTAINER_ID_OPTION, $gtm_container_id );

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-analytics-tracking',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_redirect( $redirect_url );
	exit;
}

/**
 * Render Tracking settings page.
 *
 * @return void
 */
function extrachill_analytics_render_tracking_settings_page() {
	$gtm_container_id = get_site_option( EXTRACHILL_ANALYTICS_GTM_CONTAINER_ID_OPTION, '' );
	$is_configured    = ! empty( $gtm_container_id );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Tracking', 'extrachill-analytics' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Tracking settings updated successfully.', 'extrachill-analytics' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="edit.php?action=extrachill_analytics_tracking">
			<?php wp_nonce_field( 'extrachill_analytics_tracking_settings', 'extrachill_analytics_tracking_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th colspan="2">
							<h2><?php esc_html_e( 'Google Tag Manager', 'extrachill-analytics' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Configure a single GTM container for the entire network.', 'extrachill-analytics' ); ?>
								<?php if ( $is_configured ) : ?>
									<span style="color: #46b450; font-weight: bold;">&#10003; <?php esc_html_e( 'Configured', 'extrachill-analytics' ); ?></span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">&#9888; <?php esc_html_e( 'Not configured', 'extrachill-analytics' ); ?></span>
								<?php endif; ?>
							</p>
						</th>
					</tr>
					<tr>
						<th scope="row">
							<label for="extrachill_gtm_container_id">
								<?php esc_html_e( 'GTM Container ID', 'extrachill-analytics' ); ?>
							</label>
						</th>
						<td>
							<input type="text"
								   id="extrachill_gtm_container_id"
								   name="extrachill_gtm_container_id"
								   value="<?php echo esc_attr( $gtm_container_id ); ?>"
								   class="regular-text"
								   placeholder="GTM-XXXXXXX" />
							<p class="description">
								<?php esc_html_e( 'Example: GTM-ABC1234. Leave blank to disable GTM output.', 'extrachill-analytics' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Tracking Settings', 'extrachill-analytics' ) ); ?>
		</form>
	</div>
	<?php
}
