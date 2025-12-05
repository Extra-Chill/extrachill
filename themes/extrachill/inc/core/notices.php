<?php
/**
 * Notice System
 *
 * Centralized notice display for the Extra Chill platform.
 * Provides a unified API for setting and displaying user notices
 * across all themes and plugins.
 *
 * @package ExtraChill
 */

defined( 'ABSPATH' ) || exit;

/**
 * Set a notice to display on the next page load
 *
 * Stores a notice in a user-specific transient that will be
 * displayed once and then cleared automatically.
 *
 * @param string $message Notice text to display
 * @param string $type    Notice type: 'success', 'error', 'info'
 */
function extrachill_set_notice( $message, $type = 'info' ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	set_transient(
		'extrachill_notice_' . $user_id,
		array(
			'message' => $message,
			'type'    => $type,
		),
		60
	);
}

/**
 * Get and clear the current notice
 *
 * Retrieves the pending notice for the current user and
 * immediately clears it to prevent duplicate display.
 *
 * @return array|null Array with 'message' and 'type' keys, or null if no notice
 */
function extrachill_get_notice() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return null;
	}

	$notice = get_transient( 'extrachill_notice_' . $user_id );
	if ( $notice ) {
		delete_transient( 'extrachill_notice_' . $user_id );
		return $notice;
	}

	return null;
}

/**
 * Display notices
 *
 * Hooked to extrachill_notices action in header.php.
 * Renders any pending notice using the unified notice CSS classes.
 */
function extrachill_display_notices() {
	$notice = extrachill_get_notice();
	if ( ! $notice ) {
		return;
	}

	$allowed_types = array( 'success', 'error', 'info' );
	$type = in_array( $notice['type'], $allowed_types, true ) ? $notice['type'] : 'info';

	printf(
		'<div class="notice notice-%s"><p>%s</p></div>',
		esc_attr( $type ),
		esc_html( $notice['message'] )
	);
}
add_action( 'extrachill_notices', 'extrachill_display_notices' );
