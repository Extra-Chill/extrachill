<?php
/**
 * Network Admin Settings Page
 *
 * Adds a network settings page under the existing Extra Chill Multisite menu.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Admin;

use function ExtraChill\SEO\Core\ec_seo_get_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_get_indexnow_key;
use function ExtraChill\SEO\Core\ec_seo_set_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_set_indexnow_key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'network_admin_menu', __NAMESPACE__ . '\\ec_seo_add_network_menu' );
add_action( 'network_admin_edit_extrachill_seo_settings', __NAMESPACE__ . '\\ec_seo_handle_settings_save' );

function ec_seo_add_network_menu() {
	if ( ! defined( 'EXTRACHILL_MULTISITE_MENU_SLUG' ) ) {
		return;
	}

	add_submenu_page(
		EXTRACHILL_MULTISITE_MENU_SLUG,
		'SEO Settings',
		'SEO',
		'manage_network_options',
		'extrachill-seo',
		__NAMESPACE__ . '\\ec_seo_render_settings_page'
	);
}

function ec_seo_handle_settings_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'extrachill-seo' ) );
	}

	check_admin_referer( 'extrachill_seo_settings', 'extrachill_seo_nonce' );

	$default_og_image_id = isset( $_POST['extrachill_seo_default_og_image_id'] ) ? (int) $_POST['extrachill_seo_default_og_image_id'] : 0;
	$indexnow_key        = isset( $_POST['extrachill_seo_indexnow_key'] ) ? sanitize_text_field( wp_unslash( $_POST['extrachill_seo_indexnow_key'] ) ) : '';

	ec_seo_set_default_og_image_id( $default_og_image_id );
	ec_seo_set_indexnow_key( $indexnow_key );

	$redirect_url = add_query_arg(
		array(
			'page'    => 'extrachill-seo',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

function ec_seo_render_settings_page() {
	$default_og_image_id = ec_seo_get_default_og_image_id();
	$indexnow_key        = ec_seo_get_indexnow_key();

	$default_og_image_url = $default_og_image_id ? wp_get_attachment_image_url( $default_og_image_id, 'thumbnail' ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill SEO Settings', 'extrachill-seo' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'SEO settings updated.', 'extrachill-seo' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="edit.php?action=extrachill_seo_settings">
			<?php wp_nonce_field( 'extrachill_seo_settings', 'extrachill_seo_nonce' ); ?>

			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="extrachill_seo_default_og_image_id"><?php esc_html_e( 'Default OG Image', 'extrachill-seo' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" name="extrachill_seo_default_og_image_id" id="extrachill_seo_default_og_image_id" value="<?php echo esc_attr( $default_og_image_id ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Attachment ID used as fallback og:image when no featured image exists.', 'extrachill-seo' ); ?>
							</p>
							<?php if ( $default_og_image_url ) : ?>
								<p>
									<img src="<?php echo esc_url( $default_og_image_url ); ?>" alt="" style="max-width: 150px; height: auto;" />
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="extrachill_seo_indexnow_key"><?php esc_html_e( 'IndexNow Key', 'extrachill-seo' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" name="extrachill_seo_indexnow_key" id="extrachill_seo_indexnow_key" value="<?php echo esc_attr( $indexnow_key ); ?>" />
							<p class="description">
								<?php esc_html_e( 'When set, /{key}.txt will return the key and posts will ping IndexNow on publish/update/trash.', 'extrachill-seo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save SEO Settings', 'extrachill-seo' ) ); ?>
		</form>
	</div>
	<?php
}
