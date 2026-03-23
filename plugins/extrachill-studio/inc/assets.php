<?php
/**
 * Asset registration helpers.
 *
 * @package ExtraChillStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue shared tabs assets from the active theme.
 *
 * @return void
 */
function extrachill_studio_enqueue_shared_tabs_assets() {
	$theme_dir = get_template_directory();
	$theme_url = get_template_directory_uri();

	$style_path  = $theme_dir . '/assets/css/shared-tabs.css';
	$script_path = $theme_dir . '/assets/js/shared-tabs.js';

	if ( file_exists( $style_path ) && ! wp_style_is( 'extrachill-shared-tabs', 'registered' ) ) {
		wp_register_style(
			'extrachill-shared-tabs',
			$theme_url . '/assets/css/shared-tabs.css',
			array(),
			filemtime( $style_path )
		);
	}

	if ( file_exists( $script_path ) && ! wp_script_is( 'extrachill-shared-tabs', 'registered' ) ) {
		wp_register_script(
			'extrachill-shared-tabs',
			$theme_url . '/assets/js/shared-tabs.js',
			array(),
			filemtime( $script_path ),
			true
		);
	}

	if ( wp_style_is( 'extrachill-shared-tabs', 'registered' ) ) {
		wp_enqueue_style( 'extrachill-shared-tabs' );
	}

	if ( wp_script_is( 'extrachill-shared-tabs', 'registered' ) ) {
		wp_enqueue_script( 'extrachill-shared-tabs' );
	}
}

/**
 * Move Studio block viewScript to the footer.
 *
 * WordPress registers viewScript in the header by default, but Studio
 * renders its block via do_blocks() in a PHP template (after wp_head).
 * Scripts enqueued for the header after wp_head() has fired are silently
 * skipped. Moving to the footer ensures the script prints via wp_footer().
 *
 * @return void
 * @since 0.2.2
 */
function extrachill_studio_move_view_script_to_footer() {
	$view_handle = generate_block_asset_handle( 'extrachill/studio', 'viewScript' );

	if ( wp_script_is( $view_handle, 'registered' ) ) {
		$scripts = wp_scripts();
		if ( isset( $scripts->registered[ $view_handle ] ) ) {
			$scripts->registered[ $view_handle ]->extra['group'] = 1;
		}
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_studio_move_view_script_to_footer' );
