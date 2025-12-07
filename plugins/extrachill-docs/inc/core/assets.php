<?php
/**
 * Asset Management
 *
 * Context-aware CSS loading with filemtime() versioning.
 *
 * @package ExtraChillDocs
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'extrachill_docs_enqueue_styles' );

/**
 * Enqueues docs-specific styles.
 *
 * @since 0.1.0
 * @return void
 */
function extrachill_docs_enqueue_styles() {
	$css_path = EXTRACHILL_DOCS_PLUGIN_DIR . 'assets/css/docs.css';

	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-docs',
			EXTRACHILL_DOCS_PLUGIN_URL . 'assets/css/docs.css',
			array(),
			filemtime( $css_path )
		);
	}
}
