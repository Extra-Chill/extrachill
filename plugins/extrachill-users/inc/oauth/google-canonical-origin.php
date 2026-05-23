<?php
/**
 * Single-Origin Google OAuth Helpers
 *
 * Google Identity Services requires every origin that renders the GIS
 * button to be explicitly listed in the "Authorized JavaScript origins"
 * field in Google Cloud Console. No wildcards, no parent-domain
 * inheritance. Every Extra Chill subsite that wanted Google sign-in
 * needed its own GCP entry; adding a new subsite without remembering
 * the GCP touch produced a silent failure (the button just didn't
 * paint, Google returned status code 9 = INVALID_CLIENT_ORIGIN, no
 * server-side log entry).
 *
 * Solution: render the GIS button on ONE canonical origin
 * (community.extrachill.com — the existing auth/identity hub) and have
 * every other subsite redirect there for Google sign-in. The cookie
 * domain is already `.extrachill.com` so the auth cookie set on the
 * canonical origin is visible to every subsite — no token-exchange
 * dance needed. After successful Google auth, the canonical origin
 * 302s the user back to the return_to URL they came from.
 *
 * @package ExtraChill\Users
 * @since 0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical login URL slug used for the cross-origin Google redirect.
 */
const EC_USERS_GOOGLE_REDIRECT_PARAM = 'google_redirect';

/**
 * Is the current site the canonical origin for Google OAuth?
 *
 * The canonical origin is the community subsite — it's the identity hub
 * that already hosts the login page, lostpassword form, and onboarding
 * flow, so concentrating Google sign-in here is a low-disruption move.
 *
 * @return bool True if the current blog is the canonical Google origin.
 */
function ec_users_is_canonical_google_origin() {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		// Multisite helper unavailable — fail safe and treat every site
		// as canonical so Google sign-in keeps working (back-compat with
		// the previous per-site behavior).
		return true;
	}

	$community_id = (int) ec_get_blog_id( 'community' );
	if ( $community_id <= 0 ) {
		return true;
	}

	return (int) get_current_blog_id() === $community_id;
}

/**
 * Build the canonical-origin login URL with a return_to redirect.
 *
 * Used by non-canonical subsites to send the user to community.extrachill.com
 * for Google sign-in. The canonical origin reads the redirect param,
 * persists it through the Google flow, and 302s the user back when
 * authentication completes.
 *
 * @param string $return_to The URL to send the user to after sign-in
 *                          (must pass ec_users_is_valid_return_to_url()
 *                          to be honored by the canonical origin).
 * @return string The canonical login URL.
 */
function ec_users_canonical_google_signin_url( $return_to ) {
	$base = function_exists( 'ec_get_site_url' )
		? ec_get_site_url( 'community' ) . '/login/'
		: home_url( '/login/' );

	if ( '' === (string) $return_to ) {
		return $base;
	}

	return add_query_arg(
		array( EC_USERS_GOOGLE_REDIRECT_PARAM => rawurlencode( $return_to ) ),
		$base
	);
}

/**
 * Validate a return_to URL against the network's host allowlist.
 *
 * Closes an open-redirect surface that exists with or without the
 * single-origin work: the Google handler reflects success_redirect_url
 * back to the client unchanged. Without a host check, a malicious
 * actor could craft a sign-in link that lands the user on an
 * external phishing page after auth. We require:
 *
 *   - Scheme is https (no http downgrades, no javascript: URLs)
 *   - Host is either extrachill.com or a subdomain of extrachill.com
 *
 * @param mixed $url Candidate return URL (defensive against non-string input).
 * @return bool True iff the URL is safe to redirect a logged-in user to.
 */
function ec_users_is_valid_return_to_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return false;
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

	if ( 'https' !== $scheme ) {
		return false;
	}

	if ( '' === $host ) {
		return false;
	}

	// Allow the apex domain and any subdomain. We match against a
	// trailing dot to avoid string-prefix attacks like
	// "extrachill.com.attacker.example".
	if ( 'extrachill.com' === $host ) {
		return true;
	}

	if ( str_ends_with( $host, '.extrachill.com' ) ) {
		return true;
	}

	return false;
}

/**
 * Read and sanitize the google_redirect query param off the current
 * request, if it's present and points at an allowlisted host.
 *
 * Used by the canonical-origin render layer to pick up the return_to
 * URL that a non-canonical subsite sent us, and pass it through the
 * Google flow as success_redirect_url.
 *
 * Returns null if the param is absent, malformed, or fails the
 * allowlist — null tells the caller to fall back to the default
 * post-auth destination.
 *
 * @return string|null Validated return-to URL, or null.
 */
function ec_users_get_validated_google_redirect_from_request() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query param used to compute a destination URL; no state change here.
	if ( ! isset( $_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] ) ) {
		return null;
	}

	$raw = wp_unslash( $_GET[ EC_USERS_GOOGLE_REDIRECT_PARAM ] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}

	$decoded = rawurldecode( $raw );

	if ( ! ec_users_is_valid_return_to_url( $decoded ) ) {
		return null;
	}

	return esc_url_raw( $decoded );
}
