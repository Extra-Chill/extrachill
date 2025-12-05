<?php
/**
 * Programmatic Link Addition API
 *
 * WordPress action-based interface for adding links to artist link pages.
 * Provides sanitization, validation, and action hook for external integrations.
 */

defined( 'ABSPATH' ) || exit;

function ec_sanitize_link_data( $link_data ) {
	$sanitized = array();

	if ( isset( $link_data['link_text'] ) ) {
		$sanitized['link_text'] = sanitize_text_field( wp_unslash( $link_data['link_text'] ) );
	}

	if ( isset( $link_data['link_url'] ) ) {
		$sanitized['link_url'] = esc_url_raw( wp_unslash( $link_data['link_url'] ) );
	}

	if ( isset( $link_data['expires_at'] ) ) {
		$sanitized['expires_at'] = sanitize_text_field( wp_unslash( $link_data['expires_at'] ) );
	}

	if ( isset( $link_data['id'] ) ) {
		$sanitized['id'] = sanitize_text_field( wp_unslash( $link_data['id'] ) );
	} else {
		$sanitized['id'] = 'link_' . time() . '_' . wp_rand();
	}

	return $sanitized;
}
add_filter( 'ec_sanitize_link_data', 'ec_sanitize_link_data' );

/**
 * Validate link data before save
 *
 * @param bool  $valid     Current validation state
 * @param array $link_data Link data to validate
 * @return bool|WP_Error True if valid, WP_Error if invalid
 */
function ec_validate_link_data( $valid, $link_data ) {
	if ( empty( $link_data['link_text'] ) ) {
		return new WP_Error( 'missing_text', 'Link text is required' );
	}

	if ( empty( $link_data['link_url'] ) ) {
		return new WP_Error( 'missing_url', 'Link URL is required' );
	}

	if ( ! filter_var( $link_data['link_url'], FILTER_VALIDATE_URL ) ) {
		return new WP_Error( 'invalid_url', 'Invalid URL format' );
	}

	return true;
}
add_filter( 'ec_validate_link_data', 'ec_validate_link_data', 10, 2 );

/**
 * Add single link to link page
 *
 * Action handler for programmatic link addition. Handles validation, permissions,
 * and persistence using existing save infrastructure.
 *
 * @param int   $link_page_id Link page post ID
 * @param array $link_data    Array with 'link_text', 'link_url', optional 'section_index', 'position', 'expires_at'
 * @param int   $user_id      User making the request
 * @return array|WP_Error Success data with link_id/position or WP_Error on failure
 */
function ec_action_artist_add_link( $link_page_id, $link_data, $user_id ) {
	// Sanitize via centralized filter
	$clean_data = apply_filters( 'ec_sanitize_link_data', $link_data );

	// Validate via centralized filter
	$is_valid = apply_filters( 'ec_validate_link_data', true, $clean_data );
	if ( is_wp_error( $is_valid ) ) {
		do_action( 'ec_artist_link_add_failed', $link_page_id, $clean_data, $is_valid, $user_id );
		return $is_valid;
	}

	// Verify link page exists
	if ( ! $link_page_id || get_post_type( $link_page_id ) !== 'artist_link_page' ) {
		$error = new WP_Error( 'invalid_link_page', 'Invalid link page ID' );
		do_action( 'ec_artist_link_add_failed', $link_page_id, $clean_data, $error, $user_id );
		return $error;
	}

	// Permission check via existing function
	$artist_id = apply_filters( 'ec_get_artist_id', $link_page_id );
	if ( ! $artist_id || ! ec_can_manage_artist( $user_id, $artist_id ) ) {
		$error = new WP_Error( 'permission_denied', 'User cannot manage this link page' );
		do_action( 'ec_artist_link_add_failed', $link_page_id, $clean_data, $error, $user_id );
		return $error;
	}

	// Get current data via existing centralized function
	$current_data = ec_get_link_page_data( $artist_id, $link_page_id );
	$links = $current_data['links'] ?? array();

	// Determine section (default: first section)
	$section_index = isset( $link_data['section_index'] ) ? absint( $link_data['section_index'] ) : 0;

	// Create section if it doesn't exist
	if ( ! isset( $links[ $section_index ] ) ) {
		$links[ $section_index ] = array(
			'section_title' => '',
			'links' => array()
		);
	}

	// Determine position (default: append to end)
	$position = isset( $link_data['position'] )
		? absint( $link_data['position'] )
		: count( $links[ $section_index ]['links'] );

	// Insert link at position
	array_splice( $links[ $section_index ]['links'], $position, 0, array( $clean_data ) );

	// Save via existing centralized function
	$save_data = array( 'links' => $links );
	$result = ec_handle_link_page_save( $link_page_id, $save_data );

	if ( is_wp_error( $result ) ) {
		do_action( 'ec_artist_link_add_failed', $link_page_id, $clean_data, $result, $user_id );
		return $result;
	}

	// Fire success action (for logging, notifications, webhooks, etc.)
	do_action( 'ec_artist_link_added', $link_page_id, $clean_data, $section_index, $position, $user_id );

	return array(
		'success' => true,
		'link_id' => $clean_data['id'],
		'section_index' => $section_index,
		'position' => $position,
		'link_page_id' => $link_page_id,
		'artist_id' => $artist_id
	);
}

// Register the action handler
add_action( 'ec_artist_add_link', 'ec_action_artist_add_link', 10, 3 );
