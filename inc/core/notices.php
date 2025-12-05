<?php
/**
 * Notice System
 *
 * Centralized notice display for the Extra Chill platform.
 * Provides a unified API for setting and displaying user notices
 * across all themes and plugins. Supports multiple notices of
 * different types (success, error, info) in a single request.
 *
 * Storage strategy:
 * - Logged-in users: transient storage (supports full $args)
 * - Anonymous users: cookie storage (message + type only)
 *
 * @package ExtraChill
 * @since 1.1.3
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add a notice to display on the next page load
 *
 * Appends a notice to the queue stored in user-specific transient (logged-in)
 * or cookie (anonymous). Multiple notices can be queued with different types.
 *
 * @param string $message Notice text to display
 * @param string $type    Notice type: 'success', 'error', 'info'
 * @param array  $args    Optional additional data (logged-in users only):
 *                        - 'actions' => array of ['label' => string, 'url' => string]
 */
function extrachill_set_notice( $message, $type = 'info', $args = array() ) {
	$user_id = get_current_user_id();

	$notice = array(
		'message' => $message,
		'type'    => $type,
	);
	if ( ! empty( $args ) ) {
		$notice['args'] = $args;
	}

	if ( $user_id ) {
		$notices = get_transient( 'extrachill_notices_' . $user_id );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = $notice;
		set_transient( 'extrachill_notices_' . $user_id, $notices, 60 );
	} else {
		$notices = array();
		if ( isset( $_COOKIE['extrachill_notices'] ) ) {
			$existing = json_decode( wp_unslash( $_COOKIE['extrachill_notices'] ), true );
			if ( is_array( $existing ) ) {
				$notices = $existing;
			}
		}
		$notices[] = $notice;
		setcookie(
			'extrachill_notices',
			wp_json_encode( $notices ),
			time() + 60,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}
}

/**
 * Get and clear all pending notices
 *
 * Retrieves all pending notices for the current user (transient) or
 * anonymous visitor (cookie) and immediately clears storage.
 * Includes backward compatibility for old singular notice format.
 *
 * @return array Array of notice arrays, each with 'message', 'type', and optionally 'args' keys
 */
function extrachill_get_notices() {
	$user_id = get_current_user_id();
	$notices = array();

	if ( $user_id ) {
		// Check new plural transient first
		$stored = get_transient( 'extrachill_notices_' . $user_id );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			$notices = $stored;
			delete_transient( 'extrachill_notices_' . $user_id );
		} else {
			// Backward compat: check old singular transient
			$old_notice = get_transient( 'extrachill_notice_' . $user_id );
			if ( $old_notice && isset( $old_notice['message'], $old_notice['type'] ) ) {
				$notices = array( $old_notice );
				delete_transient( 'extrachill_notice_' . $user_id );
			}
		}
	}

	// Check cookies (for anonymous users or logged-in without transient)
	if ( empty( $notices ) ) {
		// Check new plural cookie first
		if ( isset( $_COOKIE['extrachill_notices'] ) ) {
			$stored = json_decode( wp_unslash( $_COOKIE['extrachill_notices'] ), true );
			if ( is_array( $stored ) && ! empty( $stored ) ) {
				$notices = $stored;
			}
			setcookie( 'extrachill_notices', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
		} elseif ( isset( $_COOKIE['extrachill_notice'] ) ) {
			// Backward compat: check old singular cookie
			$old_notice = json_decode( wp_unslash( $_COOKIE['extrachill_notice'] ), true );
			if ( is_array( $old_notice ) && isset( $old_notice['message'], $old_notice['type'] ) ) {
				$notices = array( $old_notice );
			}
			setcookie( 'extrachill_notice', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
		}
	}

	return $notices;
}

/**
 * Display notices
 *
 * Hooked to extrachill_notices action in header.php.
 * Renders all pending notices with optional action buttons.
 */
function extrachill_display_notices() {
	$notices = extrachill_get_notices();
	if ( empty( $notices ) ) {
		return;
	}

	$allowed_types = array( 'success', 'error', 'info' );

	foreach ( $notices as $notice ) {
		if ( ! isset( $notice['message'], $notice['type'] ) ) {
			continue;
		}

		$type = in_array( $notice['type'], $allowed_types, true ) ? $notice['type'] : 'info';

		echo '<div class="notice notice-' . esc_attr( $type ) . '">';
		echo '<p>' . esc_html( $notice['message'] ) . '</p>';

		// Render action buttons if provided
		if ( ! empty( $notice['args']['actions'] ) && is_array( $notice['args']['actions'] ) ) {
			echo '<p class="notice-actions">';
			foreach ( $notice['args']['actions'] as $action ) {
				if ( isset( $action['label'], $action['url'] ) ) {
					printf(
						'<a href="%s" class="button-2 button-medium">%s</a> ',
						esc_url( $action['url'] ),
						esc_html( $action['label'] )
					);
				}
			}
			echo '</p>';
		}

		echo '</div>';
	}
}
add_action( 'extrachill_notices', 'extrachill_display_notices' );
