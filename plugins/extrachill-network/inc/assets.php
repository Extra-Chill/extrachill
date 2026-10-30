<?php
/**
 * Asset Management for ExtraChill Network
 *
 * Loads CSS assets with filemtime() versioning.
 * Conditionally enqueues based on page context.
 *
 * @package ExtraChill\Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Style dependencies that cascade after the theme's root design tokens.
 *
 * The `extrachill-root` handle is registered by the Extra Chill theme. This
 * plugin is network-activated and also runs on sites using other themes
 * (e.g. extrachill.link on Twenty Twenty-Five), where the handle is never
 * registered — declaring it anyway makes WordPress drop the stylesheet and
 * log a `_doing_it_wrong` notice.
 *
 * @return string[] Style dependencies.
 */
function extrachill_network_root_style_deps() {
	return wp_style_is( 'extrachill-root', 'registered' ) ? array( 'extrachill-root' ) : array();
}

/**
 * Enqueue 404 error page styles.
 */
function extrachill_network_enqueue_404_styles() {
	if ( ! is_404() ) {
		return;
	}

	$css_path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/css/404.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-network-404',
			EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/css/404.css',
			extrachill_network_root_style_deps(),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_network_enqueue_404_styles', 10 );

/**
 * Enqueue community activity styles.
 *
 * Loads when sidebar is active or community activity is displayed.
 */
function extrachill_network_enqueue_community_activity_styles() {
	// Only load on singular posts where the sidebar renders.
	if ( ! is_singular() ) {
		return;
	}

	$css_path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/css/community-activity.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-network-community-activity',
			EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/css/community-activity.css',
			extrachill_network_root_style_deps(),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_network_enqueue_community_activity_styles', 15 );
