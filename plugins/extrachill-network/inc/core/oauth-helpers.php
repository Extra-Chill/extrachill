<?php
/**
 * OAuth Helper Functions
 *
 * Network-wide OAuth configuration checks accessible from all sites.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check if Google OAuth is configured.
 *
 * @return bool True if both client ID and secret are set.
 */
function ec_is_google_oauth_configured() {
	$client_id     = get_site_option( 'extrachill_google_client_id', '' );
	$client_secret = get_site_option( 'extrachill_google_client_secret', '' );
	return ! empty( $client_id ) && ! empty( $client_secret );
}

/**
 * Get Google iOS client ID.
 *
 * @return string iOS client ID or empty string.
 */
function ec_get_google_ios_client_id() {
	return get_site_option( 'extrachill_google_ios_client_id', '' );
}

/**
 * Get Google Android client ID.
 *
 * @return string Android client ID or empty string.
 */
function ec_get_google_android_client_id() {
	return get_site_option( 'extrachill_google_android_client_id', '' );
}

/**
 * Check if Apple Sign-In is configured.
 *
 * @return bool True if all required Apple credentials are set.
 */
function ec_is_apple_oauth_configured() {
	$client_id   = get_site_option( 'extrachill_apple_client_id', '' );
	$team_id     = get_site_option( 'extrachill_apple_team_id', '' );
	$key_id      = get_site_option( 'extrachill_apple_key_id', '' );
	$private_key = get_site_option( 'extrachill_apple_private_key', '' );
	return ! empty( $client_id ) && ! empty( $team_id ) && ! empty( $key_id ) && ! empty( $private_key );
}
