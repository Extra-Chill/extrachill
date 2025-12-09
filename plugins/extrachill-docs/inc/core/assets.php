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

	// Load theme's single-post.css and TOC JS for ec_doc singular pages.
	if ( is_singular( 'ec_doc' ) ) {
		$single_post_css = get_stylesheet_directory() . '/assets/css/single-post.css';
		if ( file_exists( $single_post_css ) ) {
			wp_enqueue_style(
				'extrachill-single-post',
				get_stylesheet_directory_uri() . '/assets/css/single-post.css',
				array( 'extrachill-root', 'extrachill-style' ),
				filemtime( $single_post_css )
			);
		}

		$toc_js = EXTRACHILL_DOCS_PLUGIN_DIR . 'assets/js/docs-toc.js';
		if ( file_exists( $toc_js ) ) {
			wp_enqueue_script(
				'extrachill-docs-toc',
				EXTRACHILL_DOCS_PLUGIN_URL . 'assets/js/docs-toc.js',
				array(),
				filemtime( $toc_js ),
				true
			);
		}
	}
}
