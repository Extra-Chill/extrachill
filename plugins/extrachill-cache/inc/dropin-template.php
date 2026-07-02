<?php
/**
 * Extra Chill Cache — advanced-cache.php drop-in TEMPLATE.
 *
 * This file is the SERVE gate. The installer (inc/dropin-installer.php) copies
 * it verbatim to wp-content/advanced-cache.php, where WordPress includes it
 * VERY early (from wp-settings.php, before most of core and before any plugin
 * loads) when WP_CACHE is true. That early position is the ONLY place a
 * full-page cache can serve a stored page and exit before WordPress does the
 * expensive work of rendering it.
 *
 * It replaces the Breeze-generated wp-content/advanced-cache.php, which included
 * breeze/inc/cache/execute-cache.php. That Breeze serve gate carried the bug
 * this plugin exists to fix: it only bypassed the anonymous cache for a
 * logged-in user when BOTH the wordpress_logged_in_* cookie AND the
 * breeze_folder_name role cookie were present and in sync
 * (execute-cache.php:33-67). When the role cookie desynced, an authenticated
 * user was served the anonymous cached page and got trapped
 * (extrachill-users#161, mitigated by extrachill-multisite#84).
 *
 * ============================================================================
 * THE INVARIANT (non-negotiable):
 *   Any request carrying a `wordpress_logged_in_` cookie is NEVER served a
 *   cached anonymous page. Presence of that cookie alone = bypass, full stop.
 *   No secondary role cookie is consulted. Ever. This check is the FIRST thing
 *   the gate does, so it is structurally impossible to serve a cached anon page
 *   to a logged-in request.
 * ============================================================================
 *
 * Modeled on Breeze's wp-content/advanced-cache.php (blog_id resolution from
 * host) and breeze_serve_cache() (inc/cache/execute-cache.php:597).
 *
 * @package ExtraChillCache
 */

// Marker so this drop-in (and the rest of the plugin) can tell it was loaded
// from the early drop-in layer, where ABSPATH is defined by WP but our plugin
// constants are not yet available.
if ( ! defined( 'EXTRACHILL_CACHE_DROPIN' ) ) {
	define( 'EXTRACHILL_CACHE_DROPIN', true );
}

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// ---------------------------------------------------------------------------
// 1. LOGGED-IN BYPASS — the FIRST and ONLY precondition for anon cache serve.
//    If any wordpress_logged_in_ cookie is present, do nothing: let WordPress
//    load normally and render a fresh, authenticated page. No role cookie,
//    no secondary condition, no exceptions.
// ---------------------------------------------------------------------------
if ( ! empty( $_COOKIE ) ) {
	foreach ( $_COOKIE as $ec_cache_cookie_name => $ec_cache_cookie_value ) {
		if ( 0 === strpos( (string) $ec_cache_cookie_name, 'wordpress_logged_in_' ) ) {
			return;
		}
	}
}

// ---------------------------------------------------------------------------
// 2. Only GET requests can hit the anonymous cache.
// ---------------------------------------------------------------------------
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}

// wp-admin / login / cron / xmlrpc never cache.
// @phpstan-ignore phpstanWP.wpConstant.fetch (Drop-in serve gate runs from wp-settings.php before core loads, where is_admin() does not yet exist; WP_ADMIN is the only signal available at this layer.)
if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
	return;
}
if ( isset( $_SERVER['REQUEST_URI'] ) ) {
	$ec_cache_uri = (string) $_SERVER['REQUEST_URI'];
	if (
		false !== strpos( $ec_cache_uri, '/wp-admin' ) ||
		false !== strpos( $ec_cache_uri, 'wp-login.php' ) ||
		false !== strpos( $ec_cache_uri, 'wp-cron.php' ) ||
		false !== strpos( $ec_cache_uri, 'xmlrpc.php' ) ||
		false !== strpos( $ec_cache_uri, '/wp-json/' ) ||
		false !== strpos( $ec_cache_uri, 'robots.txt' ) ||
		false !== strpos( $ec_cache_uri, 'favicon.ico' )
	) {
		return;
	}
}

// Search results and comment authors are never served the anon cache.
if ( isset( $_GET['s'] ) && '' !== $_GET['s'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return;
}
if ( ! empty( $_COOKIE ) ) {
	foreach ( $_COOKIE as $ec_cache_cookie_name => $ec_cache_cookie_value ) {
		if ( 0 === strpos( (string) $ec_cache_cookie_name, 'comment_author_' ) ) {
			return;
		}
	}
}

// ---------------------------------------------------------------------------
// 3. Resolve blog_id from the request host (multisite). Mirrors Breeze's
//    advanced-cache.php host->blog_id switch, but data-driven so it needs no
//    per-site edits: the map is written by the installer at install time.
//    EXTRACHILL_CACHE_DROPIN_BLOG_MAP is injected below the template by the
//    installer. If it's absent (single-site or unmapped host) we fall back to
//    blog_id 0, which resolves to the shared base cache dir.
// ---------------------------------------------------------------------------
$ec_cache_blog_id = 0;
if ( defined( 'EXTRACHILL_CACHE_DROPIN_BLOG_MAP' ) && isset( $_SERVER['HTTP_HOST'] ) ) {
	$ec_cache_host = strtolower( (string) $_SERVER['HTTP_HOST'] );
	if ( ':80' === substr( $ec_cache_host, -3 ) ) {
		$ec_cache_host = substr( $ec_cache_host, 0, -3 );
	} elseif ( ':443' === substr( $ec_cache_host, -4 ) ) {
		$ec_cache_host = substr( $ec_cache_host, 0, -4 );
	}
	$ec_cache_map = json_decode( EXTRACHILL_CACHE_DROPIN_BLOG_MAP, true );
	if ( is_array( $ec_cache_map ) && isset( $ec_cache_map[ $ec_cache_host ] ) ) {
		$ec_cache_blog_id = (int) $ec_cache_map[ $ec_cache_host ];
	}
}

// ---------------------------------------------------------------------------
// 4. Load the shared cache-store helpers and attempt a serve.
//    EXTRACHILL_CACHE_DIR / _TTL are injected by the installer above the
//    template so the store resolves the same paths the plugin uses.
// ---------------------------------------------------------------------------
$ec_cache_store = __DIR__ . '/plugins/extrachill-cache/inc/cache-store.php';
if ( defined( 'EXTRACHILL_CACHE_STORE_PATH' ) ) {
	$ec_cache_store = EXTRACHILL_CACHE_STORE_PATH;
}
// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Drop-in serve gate runs before WordPress loads; the @ guards a benign missing-file check (handled via the return below).
if ( ! @is_file( $ec_cache_store ) ) {
	return;
}
require_once $ec_cache_store;

if ( ! function_exists( 'extrachill_cache_url_identity' ) || ! function_exists( 'extrachill_cache_read' ) ) {
	return;
}

$ec_cache_identity = extrachill_cache_url_identity();
if ( '' === $ec_cache_identity ) {
	return;
}

$ec_cache_device = extrachill_cache_is_mobile_request() ? 'mobile' : 'desktop';
$ec_cache_key    = extrachill_cache_key( $ec_cache_identity, $ec_cache_device );
$ec_cache_ttl    = defined( 'EXTRACHILL_CACHE_TTL' ) ? EXTRACHILL_CACHE_TTL : 86400;

$ec_cache_payload = extrachill_cache_read( $ec_cache_key, $ec_cache_blog_id, $ec_cache_ttl );
if ( false === $ec_cache_payload ) {
	// MISS: let WordPress load and render. The WP-layer buffer callback
	// (inc/page-cache.php) will store the result for next time.
	return;
}

// HIT: emit stored headers + body and exit before WordPress loads.
if ( ! empty( $ec_cache_payload['headers'] ) && is_array( $ec_cache_payload['headers'] ) ) {
	foreach ( $ec_cache_payload['headers'] as $ec_cache_header ) {
		if ( isset( $ec_cache_header['name'], $ec_cache_header['value'] ) ) {
			header( $ec_cache_header['name'] . ': ' . $ec_cache_header['value'] );
		}
	}
}
header( 'X-Extrachill-Cache: HIT' );
header( 'Content-Length: ' . strlen( $ec_cache_payload['body'] ) );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cached HTML previously rendered by WordPress.
echo $ec_cache_payload['body'];
exit;
