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
	// Only load on our specific submenu page
	if ( 'extra-chill-multisite_page_extrachill-analytics' !== $hook ) {
		return;
	}

	$js_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/js/admin-analytics.js';
	if ( file_exists( $js_path ) ) {
		wp_enqueue_script(
			'extrachill-analytics-admin',
			EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/js/admin-analytics.js',
			array( 'wp-element', 'wp-i18n', 'wp-api-fetch' ),
			filemtime( $js_path ),
			true
		);
	}

	$css_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/css/admin-analytics.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-analytics-admin',
			EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/css/admin-analytics.css',
			array(),
			filemtime( $css_path )
		);
	}
}
add_action( 'admin_enqueue_scripts', 'extrachill_analytics_enqueue_admin_assets' );
