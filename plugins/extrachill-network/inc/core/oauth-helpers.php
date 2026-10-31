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
 * Get the accepted audiences for Apple identity tokens.
 *
 * Native iOS tokens carry the app bundle ID as `aud`; web tokens carry the
 * Services ID. Either one is enough to verify an identity token.
 *
 * @return string[] Non-empty, trimmed, unique audiences.
 */
function ec_get_apple_audiences() {
	$audiences = array();
	foreach ( array( 'extrachill_apple_bundle_id', 'extrachill_apple_client_id' ) as $option ) {
		$value = trim( (string) get_site_option( $option, '' ) );
		if ( '' !== $value && ! in_array( $value, $audiences, true ) ) {
			$audiences[] = $value;
		}
	}
	return $audiences;
}

/**
 * Check if Apple Sign-In is configured.
 *
 * Identity-token sign-in only needs an accepted audience (the app bundle ID
 * or the Services ID). Server credentials are checked separately.
 *
 * @return bool True when at least one Apple audience is set.
 */
function ec_is_apple_oauth_configured() {
	return ! empty( ec_get_apple_audiences() );
}

/**
 * Check if Apple server credentials are configured.
 *
 * Needed for the web authorization-code flow and for token revocation,
 * not for native identity-token sign-in.
 *
 * @return bool True if the Services ID, team ID, key ID and private key are set.
 */
function ec_is_apple_server_credentials_configured() {
	$client_id   = get_site_option( 'extrachill_apple_client_id', '' );
	$team_id     = get_site_option( 'extrachill_apple_team_id', '' );
	$key_id      = get_site_option( 'extrachill_apple_key_id', '' );
	$private_key = get_site_option( 'extrachill_apple_private_key', '' );
	return ! empty( $client_id ) && ! empty( $team_id ) && ! empty( $key_id ) && ! empty( $private_key );
}
