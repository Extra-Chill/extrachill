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
 * @param bool   $from_join Whether artist/professional onboarding is required.
 * @return string The canonical login URL.
 */
function ec_users_canonical_google_signin_url( $return_to, $from_join = false ) {
	$base = function_exists( 'ec_get_site_url' )
		? ec_get_site_url( 'community' ) . '/login/'
		: home_url( '/login/' );

	$args = array();
	if ( '' !== (string) $return_to ) {
		$args[ EC_USERS_GOOGLE_REDIRECT_PARAM ] = rawurlencode( $return_to );
	}
	if ( $from_join ) {
		$args['from_join'] = 'true';
	}

	if ( empty( $args ) ) {
		return $base;
	}

	return add_query_arg( $args, $base );
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
 *   - Host is registered by the network redirect policy
 *
 * @param mixed $url Candidate return URL (defensive against non-string input).
 * @return bool True iff the URL is safe to redirect a logged-in user to.
 */
function ec_users_is_valid_return_to_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}

	$validated = wp_validate_redirect( $url, '' );
	if ( '' === $validated ) {
		return false;
	}

	$parts = wp_parse_url( $validated );
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

	if ( function_exists( 'ec_get_allowed_redirect_hosts' ) ) {
		$allowed_hosts = array_map( 'strtolower', ec_get_allowed_redirect_hosts() );
		return in_array( $host, $allowed_hosts, true );
	}

	// Keep the helper usable when the network plugin is unavailable, such as
	// isolated tests, while preserving the original Extra Chill-only policy.
	return 'extrachill.com' === $host
		|| str_ends_with( $host, '.extrachill.com' )
		|| in_array( $host, array( 'extrachill.link', 'www.extrachill.link' ), true );
}

/**
 * Read a validated redirect parameter from the current request.
 *
 * @param string $parameter Query parameter name.
 * @return string|null Validated destination, or null.
 */
function ec_users_get_validated_redirect_from_request( $parameter ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameter used only as a post-auth destination.
	if ( ! isset( $_GET[ $parameter ] ) ) {
		return null;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve encoded destination query values until the URL is validated below.
	$raw = wp_unslash( $_GET[ $parameter ] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}

	if ( ! ec_users_is_valid_return_to_url( $raw ) ) {
		return null;
	}

	return esc_url_raw( $raw );
}

/**
 * Read the standard WordPress login continuation from the current request.
 *
 * The outer query value has already been decoded by PHP. Decoding it again
 * would corrupt encoded query values inside the destination URL.
 *
 * @return string|null Validated destination, or null.
 */
function ec_users_get_validated_login_redirect_from_request() {
	return ec_users_get_validated_redirect_from_request( 'redirect_to' );
}

/**
 * Resolve the login block's post-auth destination.
 *
 * A safe request continuation represents the user's current intent and takes
 * precedence over the block's configured default. The current login URL is the
 * final fallback when neither value is safe.
 *
 * @param mixed  $block_redirect Configured login block redirect.
 * @param string $fallback       Current login URL.
 * @return string Safe post-auth destination.
 */
function ec_users_resolve_login_block_redirect( $block_redirect, $fallback ) {
	$request_redirect = ec_users_get_validated_login_redirect_from_request();
	if ( null !== $request_redirect ) {
		return $request_redirect;
	}

	if ( ec_users_is_valid_return_to_url( $block_redirect ) ) {
		return esc_url_raw( $block_redirect );
	}

	return esc_url_raw( $fallback );
}

/**
 * Add a safe continuation to a custom login URL.
 *
 * @param string $login_url   Custom login URL.
 * @param mixed  $redirect_to Candidate post-auth destination.
 * @return string Login URL, with redirect_to only when it is safe.
 */
function ec_users_login_url_with_redirect( $login_url, $redirect_to ) {
	if ( ! ec_users_is_valid_return_to_url( $redirect_to ) ) {
		return $login_url;
	}

	return add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $login_url );
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
	return ec_users_get_validated_redirect_from_request( EC_USERS_GOOGLE_REDIRECT_PARAM );
}
