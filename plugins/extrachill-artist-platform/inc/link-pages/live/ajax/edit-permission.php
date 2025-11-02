<?php
/**
 * Edit Permission Check AJAX Handler
 *
 * CORS endpoint for cross-domain permission validation from extrachill.link to artist.extrachill.com.
 * Validates WordPress authentication cookies (SameSite=None; Secure configured by extrachill-users plugin)
 * and returns edit permission status with management URL if authorized.
 */

add_action( 'wp_ajax_check_link_page_edit_permission', 'ec_check_link_page_edit_permission' );
add_action( 'wp_ajax_nopriv_check_link_page_edit_permission', 'ec_check_link_page_edit_permission' );

/**
 * Validates user permission to edit link page via CORS request
 *
 * Handles preflight OPTIONS requests and validates edit permissions for link pages.
 * Requires WordPress authentication cookies with SameSite=None; Secure attributes.
 * Cookie configuration managed by extrachill-users plugin (inc/auth/extrachill-link-auth.php).
 *
 * @return void Outputs JSON response and exits
 */
function ec_check_link_page_edit_permission() {
	header( 'Access-Control-Allow-Origin: https://extrachill.link' );
	header( 'Access-Control-Allow-Credentials: true' );
	header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Content-Type' );
	header( 'Content-Type: application/json' );

	if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
		http_response_code( 200 );
		exit;
	}

	$artist_id = isset( $_POST['artist_id'] ) ? intval( $_POST['artist_id'] ) : 0;

	if ( ! $artist_id ) {
		wp_send_json_error( array( 'message' => 'Missing artist_id' ) );
		return;
	}

	$current_user_id = get_current_user_id();
	$can_edit = false;
	$manage_url = '';

	if ( $current_user_id && ec_can_manage_artist( $current_user_id, $artist_id ) ) {
		$can_edit = true;
		$manage_url = home_url( '/manage-link-page/?artist_id=' . $artist_id );
	}

	wp_send_json_success( array(
		'can_edit'    => $can_edit,
		'manage_url'  => $manage_url,
		'user_id'     => $current_user_id,
	) );
}

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
	$plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
	$plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
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
			'ajax_url'   => 'https://artist.extrachill.com/wp-admin/admin-ajax.php',
			'artist_id'  => $artist_id,
		) );
	} else {
		error_log( 'Error: link-page-edit-button.js not found.' );
	}
}
add_action( 'extrch_link_page_minimal_head', 'extrch_enqueue_edit_button_script', 10, 2 );
