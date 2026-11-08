<?php
/**
 * Link Page editor hint.
 *
 * The Link Page edit button on the public host needs a bearer token minted on
 * the artist site, which costs a top-level redirect. Anonymous visitors must
 * never pay it, so the public page only starts that handoff when it has a
 * signal the visitor may be an editor.
 *
 * This file supplies the signal for owners who open their page directly: the
 * first time a signed-in artist views any network page in a browser, they are
 * bounced once through the public host, which stores a first-party
 * `ec_link_editor` hint cookie there and returns them to the same URL. A
 * network-domain cookie records that the browser is primed so the bounce
 * happens once, and logging in or out resets it.
 *
 * The hint is not a credential. Anyone can set it on themselves; it only makes
 * the public page try the token handoff, which still authenticates against the
 * artist site.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

const EC_LINK_EDITOR_HINT_COOKIE   = 'ec_link_editor';
const EC_LINK_EDITOR_PRIMED_COOKIE = 'ec_link_editor_primed';
const EC_LINK_EDITOR_HINT_ACTION   = 'ec_link_editor_hint';

/**
 * Public Link Page host (e.g. extrachill.link).
 *
 * @return string Host, or empty string when not configured.
 */
function ec_network_link_editor_hint_host() {
	return strtolower( (string) apply_filters( 'ec_link_page_public_host', '' ) );
}

/**
 * Whether the current request is served on the public Link Page host.
 *
 * @return bool
 */
function ec_network_link_editor_hint_on_public_host() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	$host = preg_replace( '/:\d+$/', '', $host );
	return '' !== $host && ec_network_link_editor_hint_host() === $host;
}

/**
 * Whether this request should bounce through the public host to prime the hint.
 *
 * Pure decision over request facts so it can be tested without WordPress.
 *
 * @param array $request {
 *     @type string $method       HTTP method.
 *     @type bool   $frontend     Plain front-end page view (not admin, ajax, feed, preview, etc.).
 *     @type bool   $public_host  Request is on the public Link Page host.
 *     @type bool   $primed       Primed cookie is present.
 *     @type bool   $logged_in    Visitor is signed in.
 *     @type int    $artist_count Artist profiles the visitor owns or belongs to.
 * }
 * @return bool
 */
function ec_network_link_editor_hint_should_prime( array $request ) {
	return 'GET' === ( $request['method'] ?? '' )
		&& ! empty( $request['frontend'] )
		&& empty( $request['public_host'] )
		&& empty( $request['primed'] )
		&& ! empty( $request['logged_in'] )
		&& (int) ( $request['artist_count'] ?? 0 ) > 0;
}

/**
 * Bounce signed-in artists through the public host once per browser.
 *
 * @return void
 */
function ec_network_link_editor_hint_maybe_prime() {
	$host = ec_network_link_editor_hint_host();
	if ( '' === $host ) {
		return;
	}

	$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	$frontend = ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		&& ! is_feed() && ! is_robots() && ! is_trackback() && ! is_preview() && ! is_customize_preview() && ! is_embed();
	$request  = array(
		'method'       => $method,
		'frontend'     => $frontend,
		'public_host'  => ec_network_link_editor_hint_on_public_host(),
		'primed'       => ! empty( $_COOKIE[ EC_LINK_EDITOR_PRIMED_COOKIE ] ),
		'logged_in'    => is_user_logged_in(),
		'artist_count' => 0,
	);

	/* Only look up artists when everything cheaper already says yes. */
	$request['artist_count'] = 1;
	if ( ! ec_network_link_editor_hint_should_prime( $request ) ) {
		return;
	}
	$request['artist_count'] = function_exists( 'ec_get_artists_for_user' ) ? count( (array) ec_get_artists_for_user( get_current_user_id() ) ) : 0;
	if ( ! ec_network_link_editor_hint_should_prime( $request ) ) {
		return;
	}

	$return = set_url_scheme( home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) );
	ec_network_link_editor_hint_set_cookie( EC_LINK_EDITOR_PRIMED_COOKIE, '1', time() + YEAR_IN_SECONDS, COOKIE_DOMAIN );

	$target = add_query_arg(
		array(
			'action' => EC_LINK_EDITOR_HINT_ACTION,
			'return' => rawurlencode( $return ),
		),
		set_url_scheme( 'https://' . $host . '/wp-admin/admin-post.php' )
	);
	nocache_headers();
	wp_redirect( $target, 302, 'Extra Chill' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Target is the configured public Link Page host, not user input.
	exit;
}
add_action( 'template_redirect', 'ec_network_link_editor_hint_maybe_prime', 1 );

/**
 * Public host endpoint: store the first-party hint and return to the network.
 *
 * @return void
 */
function ec_network_link_editor_hint_handle() {
	$return   = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( $_GET['return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Idempotent, non-credential hint.
	$fallback = function_exists( 'ec_get_site_url' ) && ec_get_site_url( 'main' ) ? ec_get_site_url( 'main' ) : home_url( '/' );

	if ( ec_network_link_editor_hint_on_public_host() ) {
		ec_network_link_editor_hint_set_cookie( EC_LINK_EDITOR_HINT_COOKIE, '1', time() + YEAR_IN_SECONDS, '', false );
	}

	nocache_headers();
	wp_safe_redirect( wp_validate_redirect( $return, $fallback ), 302, 'Extra Chill' );
	exit;
}
add_action( 'admin_post_nopriv_' . EC_LINK_EDITOR_HINT_ACTION, 'ec_network_link_editor_hint_handle' );
add_action( 'admin_post_' . EC_LINK_EDITOR_HINT_ACTION, 'ec_network_link_editor_hint_handle' );

/**
 * Re-prime after every login and logout so the hint follows the session.
 *
 * @return void
 */
function ec_network_link_editor_hint_reset() {
	if ( headers_sent() ) {
		return;
	}
	ec_network_link_editor_hint_set_cookie( EC_LINK_EDITOR_PRIMED_COOKIE, '', time() - YEAR_IN_SECONDS, COOKIE_DOMAIN );
}
add_action( 'set_logged_in_cookie', 'ec_network_link_editor_hint_reset' );
add_action( 'clear_auth_cookie', 'ec_network_link_editor_hint_reset' );

/**
 * Set a Lax cookie on the current scheme.
 *
 * @param string      $name      Cookie name.
 * @param string      $value     Cookie value.
 * @param int         $expires   Expiry timestamp.
 * @param string|bool $domain    Cookie domain; empty for host-only.
 * @param bool        $http_only Hide from JavaScript.
 * @return void
 */
function ec_network_link_editor_hint_set_cookie( $name, $value, $expires, $domain = '', $http_only = true ) {
	setcookie(
		$name,
		$value,
		array(
			'expires'  => $expires,
			'path'     => '/',
			'domain'   => is_string( $domain ) ? $domain : '',
			'secure'   => is_ssl(),
			'httponly' => $http_only,
			'samesite' => 'Lax',
		)
	);
}
