<?php
/**
 * Blocks Everywhere Integration
 *
 * Enables Gutenberg block editor for bbPress forums via the Blocks Everywhere plugin.
 */

include_once ABSPATH . 'wp-admin/includes/plugin.php';

add_filter( 'blocks_everywhere_bbpress', 'extrachill_enable_blocks_everywhere_bbpress' );
function extrachill_enable_blocks_everywhere_bbpress( $enabled ) {
	if ( ! is_plugin_active( 'blocks-everywhere/blocks-everywhere.php' ) ) {
		return $enabled;
	}

	return true;
}

add_filter( 'blocks_everywhere_bbpress_admin', 'extrachill_enable_blocks_everywhere_bbpress_admin' );
function extrachill_enable_blocks_everywhere_bbpress_admin( $enabled ) {
	if ( ! is_plugin_active( 'blocks-everywhere/blocks-everywhere.php' ) ) {
		return $enabled;
	}

	return true;
}

add_filter( 'blocks_everywhere_allowed_blocks', 'extrachill_blocks_everywhere_allowed_blocks', 10, 2 );
function extrachill_blocks_everywhere_allowed_blocks( array $allowed_blocks, $editor_type ) {
	if ( ! is_plugin_active( 'blocks-everywhere/blocks-everywhere.php' ) ) {
		return $allowed_blocks;
	}

	if ( 'bbpress' !== $editor_type ) {
		return $allowed_blocks;
	}

	$allowed_blocks[] = 'core/heading';
	$allowed_blocks[] = 'core/embed';

	$allowed_blocks = array_diff( $allowed_blocks, array( 'core/code' ) );

	return array_values( array_unique( $allowed_blocks ) );
}

