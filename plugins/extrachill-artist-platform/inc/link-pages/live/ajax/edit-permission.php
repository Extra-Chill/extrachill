<?php
/**
 * AJAX handler for cross-domain edit permission checks
 *
 * Validates user permissions for link page editing via CORS-enabled endpoint.
 */

add_action( 'wp_ajax_extrch_check_edit_permission', 'extrch_check_edit_permission_ajax' );
add_action( 'wp_ajax_nopriv_extrch_check_edit_permission', 'extrch_check_edit_permission_ajax' );

/**
 * Validates user permission to edit link page via CORS-enabled endpoint
 */
function extrch_check_edit_permission_ajax() {
	// Authenticate from .extrachill.com cookie
	$current_user_id = 0;
	foreach ( $_COOKIE as $name => $value ) {
		if ( strpos( $name, 'wordpress_logged_in_' ) === 0 ) {
			$cookie_parts = explode( '|', $value );
			if ( count( $cookie_parts ) >= 1 ) {
				$username = rawurldecode( $cookie_parts[0] );
				$user = get_user_by( 'login', $username );
				if ( $user ) {
					$current_user_id = $user->ID;
					break;
				}
			}
		}
	}

	// Set CORS headers for extrachill.link domain
	header( 'Access-Control-Allow-Origin: https://extrachill.link' );
	header( 'Access-Control-Allow-Credentials: true' );
	header( 'Access-Control-Allow-Methods: POST' );
	header( 'Access-Control-Allow-Headers: Content-Type' );

	$artist_id = isset( $_POST['artist_id'] ) ? (int) $_POST['artist_id'] : 0;

	if ( ! $artist_id ) {
		wp_send_json_error( 'Invalid artist ID' );
	}

	// Debug logging
	error_log( sprintf(
		'[Edit Permission Check] user_id=%d, artist_id=%d, artist_post_author=%d',
		$current_user_id,
		$artist_id,
		get_post_field( 'post_author', $artist_id )
	) );

	// Use existing permission function
	$can_manage = ec_can_manage_artist( $current_user_id, $artist_id );

	if ( $can_manage ) {
		wp_send_json_success(
			array(
				'can_edit'   => true,
				'manage_url' => 'https://artist.extrachill.com/manage-link-page/?artist_id=' . $artist_id,
			)
		);
	} else {
		wp_send_json_success( array( 'can_edit' => false ) );
	}
}

/**
 * Enqueues edit permission script for link page
 */
function extrch_enqueue_edit_permission_script($link_page_id, $artist_id) {
	$plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
	$plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
	$edit_permission_js_path = '/inc/link-pages/live/assets/js/link-page-edit-permission.js';

	if (file_exists($plugin_dir . $edit_permission_js_path)) {
		$script_handle = 'extrachill-link-page-edit-permission';
		wp_enqueue_script(
			$script_handle,
			$plugin_url . $edit_permission_js_path,
			array(),
			filemtime($plugin_dir . $edit_permission_js_path),
			true
		);

		// Pass artist_id to edit permission script
		if ($artist_id) {
			wp_localize_script($script_handle, 'extrchEditPermission', array(
				'artistId' => (int)$artist_id
			));
		}
	}
}
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_edit_permission_script', 10, 2);
