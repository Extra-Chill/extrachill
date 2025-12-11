<?php
/**
 * Blocks Everywhere Integration
 *
 * Enables Gutenberg block editor for bbPress forums via the Blocks Everywhere plugin.
 */

include_once ABSPATH . 'wp-admin/includes/plugin.php';

add_filter('blocks_everywhere_bbpress', 'extrachill_enable_blocks_everywhere_bbpress');
function extrachill_enable_blocks_everywhere_bbpress($enabled) {
	if (!is_plugin_active('blocks-everywhere/blocks-everywhere.php')) {
		return $enabled;
	}
	return true;
}

add_filter('blocks_everywhere_bbpress_admin', 'extrachill_enable_blocks_everywhere_bbpress_admin');
function extrachill_enable_blocks_everywhere_bbpress_admin($enabled) {
	if (!is_plugin_active('blocks-everywhere/blocks-everywhere.php')) {
		return $enabled;
	}
	return true;
}
