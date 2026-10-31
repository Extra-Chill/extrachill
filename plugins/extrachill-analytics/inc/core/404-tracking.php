<?php
/**
 * 404 Error Tracking
 *
 * Logs 404 errors as analytics events using the existing events system.
 * Replaces the standalone 404 logger from extrachill-admin-tools.
 *
 * @package ExtraChill\Analytics
 * @since 0.5.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'extrachill_analytics_track_404' );

/**
 * Track 404 errors as analytics events.
 *
 * Fires on template_redirect, checks is_404(), and records the event
 * via the existing extrachill_track_analytics_event() function.
 *
 * Excludes:
 * - /event/ URLs (calendar plugin integration)
 * - Known bot user agents (to reduce noise)
 */
function extrachill_analytics_track_404() {
	if ( ! is_404() ) {
		return;
	}

	$url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	// Exclude event URLs (calendar plugin).
	if ( preg_match( '/^\/event\//', $url ) ) {
		return;
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	// Skip non-human requests to reduce noise. Uses the canonical classifier so
	// the human/bot verdict matches every other analytics instrument.
	if ( extrachill_analytics_request_is_bot( array( 'user_agent' => $user_agent ) ) ) {
		return;
	}

	$referer    = wp_get_referer() ? wp_get_referer() : '';
	$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	extrachill_track_analytics_event(
		EC_ANALYTICS_EVENT_404_ERROR,
		array(
			'requested_url' => $url,
			'referer'       => $referer,
			'user_agent'    => $user_agent,
			'ip_hash'       => wp_hash( $ip_address ),
		),
		home_url( $url )
	);
}
