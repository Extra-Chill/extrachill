<?php
/**
 * Artist Grid AJAX Handler
 *
 * Handles AJAX pagination requests for artist grid on homepage.
 * Follows WordPress native AJAX patterns with nonce verification and input sanitization.
 *
 * @package ExtraChillArtistPlatform
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Register AJAX actions for both logged-in and logged-out users
add_action( 'wp_ajax_ec_load_artist_page', 'ec_ajax_load_artist_page' );
add_action( 'wp_ajax_nopriv_ec_load_artist_page', 'ec_ajax_load_artist_page' );

/**
 * AJAX handler for loading paginated artist grid.
 *
 * Verifies nonce, sanitizes inputs, calls ec_display_artist_cards_grid()
 * with return_data parameter, and returns JSON response with HTML and pagination data.
 */
function ec_ajax_load_artist_page() {
	try {
		// Verify nonce
		check_ajax_referer( 'ec_ajax_nonce', 'nonce' );

		// Sanitize and validate inputs
		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$exclude_user_artists = isset( $_POST['exclude_user_artists'] ) && $_POST['exclude_user_artists'] === 'true';

		// Ensure page is at least 1
		$page = max( 1, $page );

		// Call artist grid function with return_data = true
		$data = ec_display_artist_cards_grid( 24, $exclude_user_artists, $page, true );

		// Validate response data
		if ( ! is_array( $data ) || ! isset( $data['html'] ) ) {
			throw new Exception( 'Invalid data returned from artist grid function' );
		}

		// Return success response
		wp_send_json_success( array(
			'html' => $data['html'],
			'pagination_html' => $data['pagination_html'],
			'current_page' => $data['current_page'],
			'total_pages' => $data['total_pages'],
			'total_artists' => $data['total_artists']
		) );

	} catch ( Exception $e ) {
		// Log error and return failure response
		error_log( 'Artist grid AJAX error: ' . $e->getMessage() );
		wp_send_json_error( array(
			'message' => 'Failed to load artist page'
		) );
	}
}
