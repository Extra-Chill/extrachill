<?php
/**
 * WooCommerce bootstrap helpers for REST endpoints.
 *
 * switch_to_blog() does not load site plugins. This utility lazy-loads WooCommerce
 * when REST route handlers need wc_* functions while running on non-shop sites.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure WooCommerce is available in the current PHP process.
 *
 * @return true|WP_Error
 */
function extrachill_api_require_woocommerce() {
	if ( function_exists( 'wc_get_product' ) ) {
		return true;
	}

	$woocommerce_plugin_file = defined( 'WP_PLUGIN_DIR' ) ? trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php' : null;
	if ( $woocommerce_plugin_file && file_exists( $woocommerce_plugin_file ) ) {
		require_once $woocommerce_plugin_file;

		if ( function_exists( 'wc_get_product' ) ) {
			if ( function_exists( 'WC' ) ) {
				WC();
			}
			return true;
		}
	}

	return new WP_Error(
		'dependency_missing',
		'WooCommerce is not available.',
		array( 'status' => 500 )
	);
}
