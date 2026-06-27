<?php
/**
 * Frontend Asset Management
 *
 * Handles enqueuing of analytics tracking scripts.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Name of the first-party anonymous visitor cookie.
 */
define( 'EXTRACHILL_ANALYTICS_VISITOR_COOKIE', 'ec_vid' );

/**
 * Resolve the cookie domain the visitor cookie must be scoped to.
 *
 * On this subdomain multisite, an empty/host-scoped cookie domain mints a NEW
 * visitor id on every subdomain (extrachill.com vs events.extrachill.com vs
 * community.extrachill.com), making cross-site retention structurally
 * unmeasurable. Scoping the cookie to the NETWORK ROOT with a leading dot
 * (`.extrachill.com`) lets ONE id span every subdomain.
 *
 * Resolution order (first that fits wins):
 *   1. The WP `COOKIE_DOMAIN` constant — on this install it is already defined
 *      as the network root with a leading dot, which is exactly what we want.
 *   2. A multisite-derived value: a leading dot prefixed to the network's
 *      primary domain (`.` . get_network()->domain). The leading dot is what
 *      makes the cookie span subdomains.
 *
 * The whole thing is filterable so the value is never a bare hardcoded literal
 * buried in setcookie() and so single-site / non-standard installs can override.
 *
 * @return string The cookie domain (e.g. `.extrachill.com`), or '' when no
 *                 network-root domain can be derived (host-scoped fallback).
 */
function extrachill_analytics_visitor_cookie_domain() {
	$domain = '';

	// 1. Prefer WP's COOKIE_DOMAIN when defined and non-empty. On this network
	// it is the leading-dot network root already.
	if ( defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) && '' !== COOKIE_DOMAIN ) {
		$domain = COOKIE_DOMAIN;
	} elseif ( function_exists( 'get_network' ) ) {
		// 2. Multisite-derived: leading dot + network primary domain so the
		// cookie spans every subdomain.
		$network = get_network();
		if ( $network && ! empty( $network->domain ) ) {
			$network_domain = ltrim( $network->domain, '.' );
			if ( '' !== $network_domain ) {
				$domain = '.' . $network_domain;
			}
		}
	}

	/**
	 * Filter the visitor cookie domain.
	 *
	 * @param string $domain Resolved cookie domain (leading-dot network root, or
	 *                       '' for host-scoped fallback).
	 */
	return (string) apply_filters( 'extrachill_analytics_visitor_cookie_domain', $domain );
}

/**
 * Detect whether the current request signals an opt-out of tracking.
 *
 * Honors Global Privacy Control (`Sec-GPC: 1`) and the legacy Do Not Track
 * header (`DNT: 1`). When either is present we neither mint the visitor cookie
 * nor attach a visitor_id to events — the pageview row is still written, just
 * anonymously, so aggregate volume counts stay accurate without any per-visitor
 * identifier.
 *
 * @return bool True if the visitor has opted out (no visitor_id should be set).
 */
function extrachill_analytics_visitor_opted_out() {
	if ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) {
		return true;
	}

	if ( isset( $_SERVER['HTTP_DNT'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) ) {
		return true;
	}

	return false;
}

/**
 * Validate a string as a canonical lowercase UUID v4.
 *
 * @param string $value Candidate value.
 * @return bool True when the value is a well-formed UUID v4.
 */
function extrachill_analytics_is_valid_visitor_id( $value ) {
	return is_string( $value ) && 1 === preg_match(
		'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
		$value
	);
}

/**
 * Read the existing first-party visitor id from the cookie WITHOUT minting.
 *
 * This is the read-only resolver used by server-side, non-pageview event
 * writes (search, 404, registration, email, etc.). Minting a cookie is the
 * pageview path's job — it owns the early `template_redirect` hook where
 * `headers_sent()` is still false. A search or 404 write happens deep in the
 * request (often after output has begun), so it must NOT try to mint; it just
 * stitches to the visitor's already-established `ec_vid` cookie when one
 * exists. Honors GPC/DNT opt-out by returning an empty string.
 *
 * @return string The existing visitor UUID, or empty string when none is set
 *                 or the visitor has opted out.
 */
function extrachill_analytics_read_visitor_id() {
	if ( extrachill_analytics_visitor_opted_out() ) {
		return '';
	}

	$cookie_name = EXTRACHILL_ANALYTICS_VISITOR_COOKIE;

	if ( isset( $_COOKIE[ $cookie_name ] ) ) {
		$existing = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		if ( extrachill_analytics_is_valid_visitor_id( $existing ) ) {
			return $existing;
		}
	}

	return '';
}

/**
 * Read the existing first-party visitor id, or mint a new one server-side.
 *
 * The cookie value is a random UUID v4 only — never an IP, email, or
 * fingerprint. It is strictly first-party and used solely for our own
 * aggregate retention analytics; it is never shared with Mediavine or any
 * third party and never used for ad targeting.
 *
 * Respects Global Privacy Control / DNT: if the visitor has opted out we return
 * an empty string and set no cookie.
 *
 * Must be called before output starts (headers not yet sent) so setcookie()
 * works — the deferred beacon cannot reliably set a cookie itself. The result
 * is memoized per-request so callers after output has started (e.g. the
 * footer enqueue) read the already-resolved id without re-minting.
 *
 * @return string The visitor UUID, or empty string if opted out / cannot mint.
 */
function extrachill_analytics_get_or_mint_visitor_id() {
	static $resolved = null;

	// Memoized: a prior early-hook call already resolved (and, if needed,
	// minted + set the cookie for) this request. Never re-mint.
	if ( null !== $resolved ) {
		return $resolved;
	}

	if ( extrachill_analytics_visitor_opted_out() ) {
		$resolved = '';
		return $resolved;
	}

	// Read-only resolve first; if the cookie already exists we reuse it.
	$existing = extrachill_analytics_read_visitor_id();
	if ( '' !== $existing ) {
		$resolved = $existing;
		return $resolved;
	}

	$cookie_name = EXTRACHILL_ANALYTICS_VISITOR_COOKIE;
	$visitor_id  = wp_generate_uuid4();

	// Set the cookie for ~1 year. Secure + HttpOnly + SameSite=Lax: first-party
	// analytics only, not readable by JS, not sent on cross-site sub-requests.
	// Scoped to the network root (leading-dot domain) so ONE visitor id spans
	// every subdomain on this multisite — without it each subdomain mints its
	// own id and cross-site retention is unmeasurable.
	// Guarded against headers_sent() as defense-in-depth; the early
	// template_redirect hook below is what actually makes this succeed. The
	// non-empty $cookie_name guard is belt-and-suspenders: an empty name throws
	// an uncaught ValueError on PHP 8 (setcookie() rejects an empty $name), so
	// we never call setcookie() unless we have a real cookie name to set.
	if ( '' !== $cookie_name && ! headers_sent() ) {
		setcookie(
			$cookie_name,
			$visitor_id,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => '/',
				'domain'   => extrachill_analytics_visitor_cookie_domain(),
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		// Make the freshly-minted id available within this same request.
		$_COOKIE[ $cookie_name ] = $visitor_id;
	}

	$resolved = $visitor_id;
	return $resolved;
}

/**
 * Mint/read the visitor cookie early, before any template output starts.
 *
 * `template_redirect` fires after the main query is resolved but before
 * `get_header()` (and therefore before the theme sends any body output), so
 * `headers_sent()` is still false here and `setcookie()` succeeds. The later
 * footer enqueue on `wp_enqueue_scripts` (which runs after output has begun)
 * then reads the memoized result instead of trying — and failing — to set the
 * cookie itself.
 *
 * Gated on the same conditions as the enqueue: singular, non-preview, and not
 * opted out via GPC/DNT.
 */
function extrachill_analytics_prime_visitor_cookie() {
	if ( ! is_singular() || is_preview() ) {
		return;
	}

	// Resolves + (when applicable) sets the cookie, memoizing for this request.
	extrachill_analytics_get_or_mint_visitor_id();
}
add_action( 'template_redirect', 'extrachill_analytics_prime_visitor_cookie' );

/**
 * Script handle for the shared, network-activated Chart.js v4 asset.
 *
 * extrachill-analytics is network-activated, so registering Chart.js once here
 * makes a single guaranteed-present copy available to every consumer on the
 * network — instead of each plugin re-bundling its own. Consumers (artist-
 * platform link-page analytics, the Mediavine revenue ARC, the Studio Network
 * tab) declare this handle as a script dependency and webpack-externalize their
 * `chart.js` import to the exposed global. See extrachill-analytics#93.
 */
const EXTRACHILL_ANALYTICS_CHART_HANDLE = 'extrachill-analytics-chart';

/**
 * Register (do NOT enqueue) the shared Chart.js v4 script handle.
 *
 * Registering — rather than unconditionally enqueuing — means the asset loads
 * only where a consumer actually declares it as a dependency, on both the front
 * end and in the admin. The built bundle (`build/chart.js`, entry `src/chart.js`)
 * exposes the full Chart.js v4 module namespace on `window.ExtraChillChart`; its
 * `default` / `.Chart` members are the auto-registered Chart constructor.
 *
 * Downstream webpack consumers map their `chart.js` (and `chart.js/auto`) import
 * to the `ExtraChillChart` external and add `extrachill-analytics-chart` to
 * their script dependencies, e.g.:
 *
 *   externals: { 'chart.js': 'ExtraChillChart', 'chart.js/auto': 'ExtraChillChart' }
 *
 * @return bool True when the handle was registered, false when the build asset
 *               is missing (e.g. plugin shipped without a build).
 */
function extrachill_analytics_register_chart_asset() {
	if ( wp_script_is( EXTRACHILL_ANALYTICS_CHART_HANDLE, 'registered' ) ) {
		return true;
	}

	$asset_file = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'build/chart.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return false;
	}

	$asset = require $asset_file;

	wp_register_script(
		EXTRACHILL_ANALYTICS_CHART_HANDLE,
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'build/chart.js',
		isset( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
		isset( $asset['version'] ) ? $asset['version'] : EXTRACHILL_ANALYTICS_VERSION,
		true
	);

	return true;
}
// Register early (priority 5) so the handle exists before consumers' default-
// priority enqueues resolve their dependency tree.
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_register_chart_asset', 5 );
add_action( 'admin_enqueue_scripts', 'extrachill_analytics_register_chart_asset', 5 );

/**
 * Enqueue view tracking script on singular pages.
 */
function extrachill_analytics_enqueue_view_tracking() {
	if ( ! is_singular() || is_preview() ) {
		return;
	}

	$js_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/js/view-tracking.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	// Read the visitor id already resolved on template_redirect (before output
	// started, so its cookie was actually set). Empty string when the visitor
	// opted out (GPC/DNT). Memoization guarantees no re-mint here.
	$visitor_id = extrachill_analytics_get_or_mint_visitor_id();

	wp_enqueue_script(
		'extrachill-view-tracking',
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/js/view-tracking.js',
		array(),
		filemtime( $js_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script(
		'extrachill-view-tracking',
		'ecViewTracking',
		array(
			'postId'    => get_the_ID(),
			'endpoint'  => rest_url( 'extrachill/v1/analytics/view' ),
			'visitorId' => $visitor_id,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_enqueue_view_tracking' );

/**
 * Enqueue outbound-click tracking on every front-end view.
 *
 * Unlike pageview tracking (singular only), an outbound exit can happen from
 * any front-end surface — archives, the homepage, taxonomy listings — so this
 * runs network-wide on all non-admin views. The handler is a single delegated
 * click listener (see assets/js/outbound-tracking.js) that fires a sendBeacon
 * `outbound_click` event when a reader clicks an anchor to an off-network host.
 *
 * Because the beacon fires only from a real, JS-executing browser, the data is
 * bot-filtered by construction — the same guarantee the bridge_click /
 * pageview beacons rely on. The visitor_id echoed here is the already-resolved
 * `ec_vid` (present on singular pages where it was minted; empty on other
 * surfaces or under GPC/DNT opt-out — in which case the click is still recorded
 * anonymously with a NULL visitor_id, exactly like the rest of the system).
 *
 * The network-host list is the canonical multisite map so an INTERNAL hop
 * (extrachill.com → community.extrachill.com, already covered by the conversion
 * map) is never miscounted as an outbound exit.
 */
function extrachill_analytics_enqueue_outbound_tracking() {
	if ( is_admin() || is_preview() ) {
		return;
	}

	$js_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/js/outbound-tracking.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	// Read-only: never mint here (this runs on non-singular pages too, after
	// output may have started). Empty when no cookie / opted out — the click is
	// then recorded anonymously, consistent with the rest of the system.
	$visitor_id = function_exists( 'extrachill_analytics_read_visitor_id' )
		? extrachill_analytics_read_visitor_id()
		: '';

	// Canonical Extra Chill network hosts — a click to any of these is an
	// internal hop, not an outbound exit. Falls back to the current site host
	// alone if the multisite helper is unavailable.
	$network_hosts = function_exists( 'ec_get_allowed_redirect_hosts' )
		? array_values( ec_get_allowed_redirect_hosts() )
		: array();

	$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( is_string( $home_host ) && '' !== $home_host ) {
		$network_hosts[] = $home_host;
	}
	$network_hosts = array_values( array_unique( array_filter( $network_hosts ) ) );

	wp_enqueue_script(
		'extrachill-outbound-tracking',
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/js/outbound-tracking.js',
		array(),
		filemtime( $js_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script(
		'extrachill-outbound-tracking',
		'ecOutboundTracking',
		array(
			'endpoint'     => rest_url( 'extrachill/v1/analytics/click' ),
			'visitorId'    => $visitor_id,
			'networkHosts' => $network_hosts,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_enqueue_outbound_tracking' );
