<?php
/**
 * Frontend Asset Management
 *
 * Handles enqueuing of analytics tracking scripts.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue view tracking script on singular pages.
 */
function extrachill_analytics_enqueue_view_tracking() {
	if ( ! is_singular() || is_preview() ) {
		return;
	}

	$js_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/js/view-tracking.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-view-tracking',
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/js/view-tracking.js',
		array(),
		filemtime( $js_path ),
		true
	);

	wp_localize_script( 'extrachill-view-tracking', 'ecViewTracking', array(
		'postId'   => get_the_ID(),
		'endpoint' => rest_url( 'extrachill/v1/analytics/view' ),
	) );
}
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_enqueue_view_tracking' );
