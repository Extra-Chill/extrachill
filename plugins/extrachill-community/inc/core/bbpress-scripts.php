<?php
/**
 * bbPress Script Dependency Filter
 *
 * Removes jQuery from bbPress script dependencies. Blocks Everywhere replaces
 * the bbPress editor with Gutenberg, so these jQuery-dependent scripts
 * (editor.js, engagements.js, reply.js) are never executed. Removing the
 * dependency prevents warnings when the theme deregisters jQuery on the frontend.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove jQuery dependency from all bbPress scripts.
 *
 * @param array $scripts bbPress script definitions keyed by handle.
 * @return array Filtered scripts with jQuery removed from dependencies.
 */
function extrachill_filter_bbpress_script_dependencies( $scripts ) {
	if ( ! is_plugin_active( 'blocks-everywhere/blocks-everywhere.php' ) ) {
		return $scripts;
	}

	foreach ( $scripts as $handle => &$attributes ) {
		if ( ! empty( $attributes['dependencies'] ) ) {
			$attributes['dependencies'] = array_values(
				array_diff( $attributes['dependencies'], array( 'jquery' ) )
			);
		}
	}

	return $scripts;
}
add_filter( 'bbp_default_scripts', 'extrachill_filter_bbpress_script_dependencies' );
