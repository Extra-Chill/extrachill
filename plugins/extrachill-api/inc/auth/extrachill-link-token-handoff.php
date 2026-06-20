<?php
/**
 * Link Page Bearer-Token Handoff
 *
 * Cross-domain auth bootstrap for the extrachill.link edit button.
 *
 * Problem: extrachill.link is a domain alias for the artist site (blog 4), but
 * the WordPress auth cookie is scoped to COOKIE_DOMAIN (.extrachill.com) — a
 * different registrable domain. So a logged-in user's cookie is never available
 * on extrachill.link, neither server-side nor in same-origin JS. The legacy
 * approach forced WordPress cookies to SameSite=None; Secure so the browser
 * would attach them to a cross-site fetch() to artist.extrachill.com. Modern
 * browsers (Safari ITP, Chrome third-party-cookie phase-out) block exactly
 * that, so the edit button silently failed for many logged-in artists.
 *
 * Fix: a wp-native bearer token in an Authorization header is NOT a cookie, so
 * it is immune to SameSite / third-party-cookie restrictions and resolves the
 * user network-wide (wp-native access tokens are site transients resolved by the
 * determine_current_user bearer filter). This endpoint runs on the artist site,
 * where the .extrachill.com cookie IS first-party, mints a short-lived access
 * token via the generic wp-native primitive, and 302s back to the extrachill.link
 * return URL carrying the token in the URL fragment (#ec_link_token=...). The
 * fragment is never sent to the server or written to any access log; the JS on
 * extrachill.link reads it, stores it in localStorage, and calls the permissions
 * endpoint with an Authorization: Bearer header.
 *
 * Triggered by query argument: ?ec_link_token_handoff=1&return=<extrachill.link URL>
 *
 * @package ExtraChill\API
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_nopriv_ec_link_token_handoff', 'ec_link_token_handoff_handle' );
add_action( 'admin_post_ec_link_token_handoff', 'ec_link_token_handoff_handle' );

/**
 * Handle a link-page bearer-token handoff request.
 *
 * Runs on artist.extrachill.com where the WordPress auth cookie is first-party.
 * If the visitor is logged in and the wp-native token primitive is available,
 * mints a short-lived access token and redirects back to the extrachill.link
 * return URL with the token in the fragment. If the visitor is NOT logged in,
 * redirects back with no token (anonymous = no edit button, no error). The JS
 * on extrachill.link records that the handoff was attempted so an anonymous
 * visitor only round-trips once per session.
 *
 * @return void
 */
function ec_link_token_handoff_handle() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bootstrap; the auth cookie is the only credential and no state is mutated.
	$return_url = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( $_GET['return'] ) ) : '';

	$return_host = $return_url ? wp_parse_url( $return_url, PHP_URL_HOST ) : '';
	if ( ! is_string( $return_host ) || '' === $return_host ) {
		status_header( 400 );
		exit;
	}

	$return_host = strtolower( $return_host );

	// Strict allow-list: this handoff exists solely for the extrachill.link
	// edit button, so the return target must be the link domain.
	$allowed_hosts = array( 'extrachill.link', 'www.extrachill.link' );
	if ( ! in_array( $return_host, $allowed_hosts, true ) ) {
		status_header( 400 );
		exit;
	}

	$user_id = get_current_user_id();

	// Not logged in (no first-party cookie session) — bounce back tokenless so
	// the JS can record the attempt and stop redirecting. Anonymous visitors
	// simply never see the edit button.
	if ( ! $user_id ) {
		ec_link_token_handoff_redirect( $return_url, '' );
	}

	// The generic wp-native primitive owns token minting. If it is unavailable
	// (plugin disabled), fail soft: bounce back tokenless rather than erroring.
	if ( ! function_exists( 'wp_native_auth_generate_access_token' ) ) {
		ec_link_token_handoff_redirect( $return_url, '' );
	}

	// device_id is required by the token primitive but carries no security
	// weight for this read-only, short-lived edit-button token. Use a stable
	// per-handoff synthetic UUID so the token resolves like any other.
	$device_id = wp_generate_uuid4();

	$token = wp_native_auth_generate_access_token( (int) $user_id, $device_id );

	$access_token = isset( $token['token'] ) ? (string) $token['token'] : '';

	ec_link_token_handoff_redirect( $return_url, $access_token );
}

/**
 * Redirect back to the extrachill.link return URL with the token in the fragment.
 *
 * The token rides in the URL fragment (#ec_link_token=...) rather than a query
 * parameter so it is never transmitted to the server or recorded in access logs.
 * An empty token still redirects (anonymous / fail-soft path) and includes a
 * marker the JS uses to avoid re-attempting the handoff.
 *
 * extrachill.link is registered as an allowed redirect host via
 * ec_get_allowed_redirect_hosts() (extrachill-multisite), so wp_safe_redirect()
 * permits it without an inline filter.
 *
 * @param string $return_url   Validated extrachill.link return URL.
 * @param string $access_token Plaintext access token, or '' when none was minted.
 * @return void
 */
function ec_link_token_handoff_redirect( $return_url, $access_token ) {
	// Strip any pre-existing fragment from the return URL before appending ours.
	$base = strtok( $return_url, '#' );

	if ( '' !== $access_token ) {
		$destination = $base . '#ec_link_token=' . rawurlencode( $access_token );
	} else {
		// Marker-only: tells the JS the handoff ran and produced no token.
		$destination = $base . '#ec_link_token_none=1';
	}

	wp_safe_redirect( $destination );
	exit;
}
