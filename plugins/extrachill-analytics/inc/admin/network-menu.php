<?php
/**
 * Network Admin Menu Integration
 *
 * Registers the analytics submenu under Extra Chill Multisite.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add submenu page to Extra Chill Multisite menu.
 */
function extrachill_analytics_add_network_menu() {
	add_submenu_page(
		'extrachill-multisite',
		'Analytics',
		'Analytics',
		'manage_network_options',
		'extrachill-analytics',
		'extrachill_analytics_render_admin_page'
	);
}
add_action( 'network_admin_menu', 'extrachill_analytics_add_network_menu', 20 );

/**
 * Render the admin page container for the React app.
 */
function extrachill_analytics_render_admin_page() {
	?>
	<div class="wrap">
		<h1>Extra Chill Analytics</h1>
		<div id="extrachill-analytics-app">
			<p class="description">Loading analytics dashboard...</p>
		</div>
	</div>
	<?php
}

/**
 * Enqueue admin assets for the analytics page.
 */
function extrachill_analytics_enqueue_admin_assets( $hook ) {
	if ( 'extra-chill-multisite_page_extrachill-analytics' !== $hook ) {
		return;
	}

	$asset_file = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'build/analytics.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'extrachill-analytics-admin',
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'build/analytics.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_localize_script(
		'extrachill-analytics-admin',
		'extraChillAnalytics',
		array(
			'restUrl' => rest_url( 'extrachill/v1/' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);

	$css_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'build/analytics.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-analytics-admin',
			EXTRACHILL_ANALYTICS_PLUGIN_URL . 'build/analytics.css',
			array( 'wp-components' ),
			filemtime( $css_path )
		);
	}
}
add_action( 'admin_enqueue_scripts', 'extrachill_analytics_enqueue_admin_assets' );
