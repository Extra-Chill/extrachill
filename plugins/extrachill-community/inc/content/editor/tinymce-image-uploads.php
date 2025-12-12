<?php
/**
 * TinyMCE Image Upload UI
 *
 * Registers TinyMCE plugin and button for image uploads.
 * Disables large thumbnail generation for performance.
 * Upload logic handled by unified REST endpoint: POST /wp-json/extrachill/v1/media
 * Skipped when Blocks Everywhere plugin is active (Gutenberg replaces TinyMCE).
 *
 * @package ExtraChillCommunity
 */

include_once ABSPATH . 'wp-admin/includes/plugin.php';
if (is_plugin_active('blocks-everywhere/blocks-everywhere.php')) {
	return;
}

$thumb_names = array( '1536x1536', '2048x2048' );

add_filter( 'big_image_size_threshold', '__return_false' );

add_filter(
	'intermediate_image_sizes_advanced',
	function( $sizes ) use ( $thumb_names ) {
		foreach ( $thumb_names as $thumb_name ) {
			unset( $sizes[$thumb_name] );
		}
		return $sizes;
	}
);

add_action(
	'init',
	function() use ( $thumb_names ) {
		foreach( $thumb_names as $thumb_name ) {
			remove_image_size( $thumb_name );
		}
	}
);

function register_custom_tinymce_plugin($plugin_array) {
	$version = filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/tinymce-image-upload.js');
	$plugin_array['local_upload_plugin'] = EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/tinymce-image-upload.js?ver=' . $version;
	return $plugin_array;
}
add_filter('mce_external_plugins', 'register_custom_tinymce_plugin');

function add_custom_tinymce_button($buttons) {
	array_push($buttons, 'image_upload');
	return $buttons;
}
add_filter('mce_buttons', 'add_custom_tinymce_button');
