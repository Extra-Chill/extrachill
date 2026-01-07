<?php
/**
 * Network Admin Settings Page
 *
 * Single React app handling both Audit and Config tabs.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Admin;

use function ExtraChill\SEO\Core\ec_seo_get_default_og_image_id;
use function ExtraChill\SEO\Core\ec_seo_get_indexnow_key;
use function ExtraChill\SEO\Audit\ec_seo_get_audit_results;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'network_admin_menu', __NAMESPACE__ . '\\ec_seo_add_network_menu' );
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

	$asset_file = EXTRACHILL_SEO_PATH . 'build/seo-admin.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

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
			'restUrl'    => rest_url( 'extrachill/v1/' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'auditData'  => ec_seo_get_audit_data_for_js(),
			'configData' => ec_seo_get_config_data_for_js(),
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
 * Gets config data formatted for JavaScript.
 *
 * @return array Config data with default OG image and IndexNow key.
 */
function ec_seo_get_config_data_for_js() {
	$default_og_image_id  = ec_seo_get_default_og_image_id();
	$default_og_image_url = $default_og_image_id
		? wp_get_attachment_image_url( $default_og_image_id, 'medium' )
		: '';

	return array(
		'defaultOgImageId'  => $default_og_image_id,
		'defaultOgImageUrl' => $default_og_image_url,
		'indexNowKey'       => ec_seo_get_indexnow_key(),
	);
}

/**
 * Renders the settings page with React mount point.
 */
function ec_seo_render_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Extra Chill SEO', 'extrachill-seo' ); ?></h1>
		<div id="extrachill-seo-admin-app">
			<p class="description"><?php esc_html_e( 'Loading...', 'extrachill-seo' ); ?></p>
		</div>
	</div>
	<?php
}
