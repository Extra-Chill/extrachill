<?php
/**
 * Link Page Editor Block Registration
 *
 * Registers the Gutenberg block for link page management.
 * Block renders on frontend via render.php with React app.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'extrachill_register_link_page_editor_block' );

/**
 * Registers the link-page-editor block from compiled assets.
 */
function extrachill_register_link_page_editor_block() {
	$block_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'build/blocks/link-page-editor';

	if ( ! file_exists( $block_path . '/block.json' ) ) {
		return;
	}

	register_block_type( $block_path );
}
