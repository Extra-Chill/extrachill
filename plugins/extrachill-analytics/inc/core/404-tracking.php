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

	$url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';

	// Exclude event URLs (calendar plugin).
	if ( preg_match( '/^\/event\//', $url ) ) {
		return;
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	// Skip known bots to reduce noise.
	if ( extrachill_analytics_is_bot( $user_agent ) ) {
		return;
	}

	$referer    = wp_get_referer() ?: '';
	$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	extrachill_track_analytics_event(
		'404_error',
		array(
			'requested_url' => $url,
			'referer'       => $referer,
			'user_agent'    => $user_agent,
			'ip_hash'       => wp_hash( $ip_address ),
		),
		home_url( $url )
	);
}

/**
 * Check if a user agent is a known bot/crawler.
 *
 * @param string $user_agent The user agent string.
 * @return bool True if the user agent is a known bot.
 */
function extrachill_analytics_is_bot( $user_agent ) {
	if ( empty( $user_agent ) ) {
		return true;
	}

	$bot_patterns = array(
		'bot',
		'crawl',
		'spider',
		'slurp',
		'mediapartners',
		'lighthouse',
		'pagespeed',
		'pingdom',
		'uptimerobot',
		'headlesschrome',
		'python-requests',
		'curl/',
		'wget/',
		'go-http-client',
		'apache-httpclient',
	);

	$ua_lower = strtolower( $user_agent );

	foreach ( $bot_patterns as $pattern ) {
		if ( false !== strpos( $ua_lower, $pattern ) ) {
			return true;
		}
	}

	return false;
}
