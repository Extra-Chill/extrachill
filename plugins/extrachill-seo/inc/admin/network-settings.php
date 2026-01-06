<?php
/**
 * Network Admin Settings Page
 *
 * Tabbed interface with Audit and Config tabs under the Extra Chill Multisite menu.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Admin;

use function ExtraChill\SEO\Core\ec_seo_get_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_get_indexnow_key;
use function ExtraChill\SEO\Core\ec_seo_set_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_set_indexnow_key;
use function ExtraChill\SEO\Audit\ec_seo_get_audit_results;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'network_admin_menu', __NAMESPACE__ . '\\ec_seo_add_network_menu' );
add_action( 'network_admin_edit_extrachill_seo_settings', __NAMESPACE__ . '\\ec_seo_handle_settings_save' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\ec_seo_enqueue_network_assets' );

/**
 * Adds SEO submenu under Extra Chill Multisite menu.
 */
function ec_seo_add_network_menu() {
	if ( ! defined( 'EXTRACHILL_MULTISITE_MENU_SLUG' ) ) {
		return;
	}

	add_submenu_page(
		EXTRACHILL_MULTISITE_MENU_SLUG,
		'SEO',
		'SEO',
		'manage_network_options',
		'extrachill-seo',
		__NAMESPACE__ . '\\ec_seo_render_settings_page'
	);
}

/**
 * Enqueues admin assets for the SEO settings page.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function ec_seo_enqueue_network_assets( $hook_suffix ) {
	if ( ! is_network_admin() ) {
		return;
	}

	if ( 'extra-chill-multisite_page_extrachill-seo' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_media();

	// Media picker for config tab.
	wp_enqueue_script(
		'extrachill-seo-media-picker',
		EXTRACHILL_SEO_URL . 'inc/admin/media-picker.js',
		array(),
		filemtime( EXTRACHILL_SEO_PATH . 'inc/admin/media-picker.js' ),
		true
	);

	// Config tab styles (keep minimal CSS for non-React parts).
	$css_path = EXTRACHILL_SEO_PATH . 'inc/admin/admin-styles.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-seo-config',
			EXTRACHILL_SEO_URL . 'inc/admin/admin-styles.css',
			array(),
			filemtime( $css_path )
		);
	}

	// React app for audit tab.
	$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'audit';
	if ( 'audit' === $current_tab ) {
		$asset_file = EXTRACHILL_SEO_PATH . 'build/seo-admin.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;

			wp_enqueue_script(
				'extrachill-seo-admin',
				EXTRACHILL_SEO_URL . 'build/seo-admin.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_localize_script(
				'extrachill-seo-admin',
				'ecSeoAdmin',
				array(
					'restUrl'   => rest_url( 'extrachill/v1/' ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'auditData' => ec_seo_get_audit_data_for_js(),
				)
			);

			$css_path = EXTRACHILL_SEO_PATH . 'build/seo-admin.css';
			if ( file_exists( $css_path ) ) {
				wp_enqueue_style(
					'extrachill-seo-admin',
					EXTRACHILL_SEO_URL . 'build/seo-admin.css',
					array( 'wp-components' ),
					filemtime( $css_path )
				);
			}
		}
	}
}

/**
 * Gets audit data formatted for JavaScript.
 *
 * @return array Audit data with status, results, and timestamp.
 */
function ec_seo_get_audit_data_for_js() {
	$audit_data = function_exists( 'ExtraChill\\SEO\\Audit\\ec_seo_get_audit_results' )
		? ec_seo_get_audit_results()
		: array(
			'status'    => 'none',
			'timestamp' => 0,
			'results'   => array(),
		);

	return array(
		'status'    => $audit_data['status'] ?? 'none',
		'timestamp' => $audit_data['timestamp'] ?? 0,
		'results'   => $audit_data['results'] ?? array(),
	);
}

/**
 * Handles config settings form submission.
 */
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
			'tab'     => 'config',
			'updated' => 'true',
		),
		network_admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Renders the main settings page with tabs.
 */
function ec_seo_render_settings_page() {
	$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'audit';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill SEO', 'extrachill-seo' ); ?></h1>

		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'audit', remove_query_arg( 'updated' ) ) ); ?>" 
			   class="nav-tab <?php echo 'audit' === $current_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Audit', 'extrachill-seo' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'config', remove_query_arg( 'updated' ) ) ); ?>" 
			   class="nav-tab <?php echo 'config' === $current_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Config', 'extrachill-seo' ); ?>
			</a>
		</nav>

		<div class="extrachill-seo-tab-content">
			<?php if ( 'audit' === $current_tab ) : ?>
				<?php ec_seo_render_audit_tab(); ?>
			<?php else : ?>
				<?php ec_seo_render_config_tab(); ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Renders the Audit tab content.
 */
function ec_seo_render_audit_tab() {
	?>
	<div id="extrachill-seo-audit-app">
		<p class="description"><?php esc_html_e( 'Loading SEO audit dashboard...', 'extrachill-seo' ); ?></p>
	</div>
	<?php
}

/**
 * Renders the Config tab content.
 */
function ec_seo_render_config_tab() {
	$default_og_image_id  = ec_seo_get_default_og_image_id();
	$indexnow_key         = ec_seo_get_indexnow_key();
	$default_og_image_url = $default_og_image_id ? wp_get_attachment_image_url( $default_og_image_id, 'thumbnail' ) : '';
	?>
	<div class="extrachill-seo-config">
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
							<div
								class="extrachill-seo-media-picker"
								data-target-input="#extrachill_seo_default_og_image_id"
								data-target-preview="#extrachill-seo-default-og-preview"
								data-target-id="#extrachill-seo-default-og-id"
							>
								<input type="hidden" name="extrachill_seo_default_og_image_id" id="extrachill_seo_default_og_image_id" value="<?php echo esc_attr( $default_og_image_id ); ?>" />
								<button type="button" class="button" data-action="select">
									<?php esc_html_e( 'Select Image', 'extrachill-seo' ); ?>
								</button>
								<button type="button" class="button" data-action="remove" <?php echo $default_og_image_id ? '' : 'disabled'; ?>>
									<?php esc_html_e( 'Remove', 'extrachill-seo' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Fallback og:image when no featured image exists.', 'extrachill-seo' ); ?>
								</p>
								<p class="description" id="extrachill-seo-default-og-id" <?php echo $default_og_image_id ? '' : 'style="display:none;"'; ?>>
									<?php echo esc_html( sprintf( 'Attachment ID: %d', (int) $default_og_image_id ) ); ?>
								</p>
								<p>
									<img
										id="extrachill-seo-default-og-preview"
										src="<?php echo esc_url( $default_og_image_url ); ?>"
										alt=""
										style="max-width: 150px; height: auto; <?php echo $default_og_image_url ? '' : 'display:none;'; ?>"
									/>
								</p>
							</div>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="extrachill_seo_indexnow_key"><?php esc_html_e( 'IndexNow Key', 'extrachill-seo' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" name="extrachill_seo_indexnow_key" id="extrachill_seo_indexnow_key" value="<?php echo esc_attr( $indexnow_key ); ?>" />
							<p class="description">
								<?php esc_html_e( 'When set, posts will ping IndexNow on publish/unpublish/delete. You must also host /{key}.txt as a static file at the domain root.', 'extrachill-seo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'extrachill-seo' ) ); ?>
		</form>
	</div>
	<?php
}
