<?php
/**
 * Network ad policy settings.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'extrachill_add_network_ad_settings_menu' );
add_action( 'network_admin_edit_extrachill_ad_policy', 'extrachill_handle_network_ad_settings_save' );

/**
 * Register the Ads submenu.
 */
function extrachill_add_network_ad_settings_menu(): void {
	add_submenu_page(
		EXTRACHILL_NETWORK_MENU_SLUG,
		__( 'Ad Policy', 'extrachill-network' ),
		__( 'Ads', 'extrachill-network' ),
		'manage_network_options',
		'extrachill-ad-policy',
		'extrachill_render_network_ad_settings_page'
	);
}

/**
 * Save explicit site eligibility.
 */
function extrachill_handle_network_ad_settings_save(): void {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-network' ) );
	}

	check_admin_referer( 'extrachill_ad_policy_settings', 'extrachill_ad_policy_nonce' );

	$known_ids = array_map( 'absint', array_values( ec_get_blog_ids() ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
	$submitted = isset( $_POST['extrachill_ad_enabled_site_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['extrachill_ad_enabled_site_ids'] ) ) : array();
	$enabled   = array_values( array_intersect( $known_ids, array_map( 'absint', $submitted ) ) );

	update_site_option( 'extrachill_ad_enabled_site_ids', $enabled );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'extrachill-ad-policy',
				'updated' => 'true',
			),
			network_admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Render site eligibility and current delivery drift.
 */
function extrachill_render_network_ad_settings_page(): void {
	$enabled_ids = extrachill_get_ad_enabled_site_ids();
	$sites       = ec_get_blog_ids();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill Ad Policy', 'extrachill-network' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag. ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ad policy updated successfully.', 'extrachill-network' ); ?></p></div>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Choose which sites are eligible to serve ads. Delivery integration state is diagnostic evidence and does not change this policy.', 'extrachill-network' ); ?></p>

		<form method="post" action="edit.php?action=extrachill_ad_policy">
			<?php wp_nonce_field( 'extrachill_ad_policy_settings', 'extrachill_ad_policy_nonce' ); ?>
			<table class="widefat striped" style="max-width: 900px; margin-top: 20px;">
				<thead><tr><th><?php esc_html_e( 'Site', 'extrachill-network' ); ?></th><th><?php esc_html_e( 'Ad-enabled', 'extrachill-network' ); ?></th><th><?php esc_html_e( 'Integration', 'extrachill-network' ); ?></th><th><?php esc_html_e( 'Drift', 'extrachill-network' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $sites as $site_key => $blog_id ) : ?>
					<?php $policy = extrachill_get_ad_policy( array( 'blog_id' => $blog_id ) ); ?>
					<tr>
						<td><strong><?php echo esc_html( ucfirst( $site_key ) ); ?></strong> <code><?php echo esc_html( (string) $blog_id ); ?></code></td>
						<td><label><input type="checkbox" name="extrachill_ad_enabled_site_ids[]" value="<?php echo esc_attr( (string) $blog_id ); ?>" <?php checked( in_array( $blog_id, $enabled_ids, true ) ); ?> /> <?php esc_html_e( 'Enabled', 'extrachill-network' ); ?></label></td>
						<td><?php echo $policy['integration_available'] ? esc_html__( 'Available', 'extrachill-network' ) : esc_html__( 'Unavailable', 'extrachill-network' ); ?></td>
						<td><code><?php echo esc_html( $policy['drift'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save Ad Policy', 'extrachill-network' ) ); ?>
		</form>
	</div>
	<?php
}
