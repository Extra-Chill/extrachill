<?php
/**
 * Edit Permission Button Enqueue
 *
 * Enqueues client-side edit button script with localized data.
 * Permission check is performed via REST API (extrachill-api).
 */

/**
 * Enqueues client-side edit button script with localized data
 *
 * JavaScript performs permission check via CORS request to artist.extrachill.com
 * and renders edit button only if user has permission. No server-side HTML rendering.
 *
 * @param int $link_page_id Link page post ID
 * @param int $artist_id Artist profile post ID
 * @return void
 */
function extrch_enqueue_edit_button_script( $link_page_id, $artist_id ) {
	$plugin_dir  = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
	$plugin_url  = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
	$script_path = 'inc/link-pages/live/assets/js/link-page-edit-button.js';

	if ( file_exists( $plugin_dir . $script_path ) ) {
		$script_handle = 'extrch-edit-button';
		wp_enqueue_script(
			$script_handle,
			$plugin_url . $script_path,
			array(),
			filemtime( $plugin_dir . $script_path ),
			true
		);

		wp_localize_script( $script_handle, 'extrchEditButton', array(
			'api_url'   => rest_url( 'extrachill/v1/artist/permissions' ),
			'artist_id' => $artist_id,
		) );
	} else {
		error_log( 'Error: link-page-edit-button.js not found.' );
	}
}
add_action( 'extrch_link_page_minimal_head', 'extrch_enqueue_edit_button_script', 10, 2 );
