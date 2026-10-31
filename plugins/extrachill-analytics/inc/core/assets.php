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
 * buried in the client mint config and so single-site / non-standard installs
 * can override.
 *
 * @return string The cookie domain (e.g. `.extrachill.com`), or '' when no
 *                 network-root domain can be derived (host-scoped fallback).
 */
function extrachill_analytics_visitor_cookie_domain() {
	$domain = '';

	// 1. Prefer WP's COOKIE_DOMAIN when defined and non-empty. On this network
	// it is the leading-dot network root already.
	if ( defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ) {
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
 * @param mixed $value Candidate value.
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
 * This is the ONLY server-side identity resolver. Every consumer — pageview
 * beacon, non-pageview event writes (search, 404, registration, email, etc.),
 * experiment assignment — reads through it. Minting never happens server-side:
 * a response carrying `Set-Cookie` is refused by the edge cache, so a
 * server-side mint makes first-visit HTML responses uncacheable. The visitor's
 * browser mints the UUID instead (see view-tracking.js and
 * extrachill_analytics_visitor_cookie_client_config()); until that first
 * client-side mint lands, this resolver returns '' and the event is attributed
 * anonymously. Honors GPC/DNT opt-out by returning an empty string.
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
 * Check whether a browser beacon originated on the cookie's first-party site.
 *
 * Custom-domain link pages can beacon to an Extra Chill subdomain, but browsers
 * may reject that response's cookie as third-party. Such requests must remain
 * anonymous instead of minting a new, non-persistent UUID on every pageview.
 *
 * @return bool True when the request origin belongs to the cookie domain.
 */
function extrachill_analytics_beacon_is_first_party() {
	$source = '';

	if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
		$source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
	} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
		$source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}

	$source_host   = wp_parse_url( $source, PHP_URL_HOST );
	$cookie_domain = ltrim( extrachill_analytics_visitor_cookie_domain(), '.' );
	if ( '' === $cookie_domain ) {
		$cookie_domain = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	if ( ! is_string( $source_host ) || '' === $source_host || ! is_string( $cookie_domain ) || '' === $cookie_domain ) {
		return false;
	}

	$source_host   = strtolower( rtrim( $source_host, '.' ) );
	$cookie_domain = strtolower( rtrim( $cookie_domain, '.' ) );
	$suffix        = '.' . $cookie_domain;

	return $source_host === $cookie_domain
		|| ( strlen( $source_host ) > strlen( $suffix ) && substr( $source_host, -strlen( $suffix ) ) === $suffix );
}

/**
 * Client-side visitor-cookie mint configuration for eligible public requests.
 *
 * Visitor identity is minted BY THE BROWSER, never by the server. Any response
 * carrying `Set-Cookie` is refused by the edge cache, and first-time visitors
 * are the overwhelming majority of traffic — a server-side mint therefore made
 * almost every HTML response uncacheable. The analytics JS (view-tracking.js)
 * generates the UUIDv4 and sets the cookie on the client; the server stays a
 * READ-ONLY resolver (extrachill_analytics_read_visitor_id()) and attributes
 * anonymously until the cookie exists.
 *
 * The computed domain is the same leading-dot NETWORK ROOT the server cookie
 * used, exposed to JS instead of hardcoded there — ONE visitor id still spans
 * every subdomain on this multisite, so cross-site retention stays measurable.
 * An empty `cookieDomain` (custom-domain host, opt-out, or a non-template
 * runtime) tells the JS to never mint on that page.
 *
 * @return array{cookieName:string,cookieDomain:string,cookieMaxAge:int} Mint
 *               config for view-tracking.js. `HttpOnly` is necessarily
 *               forfeited by the client-side mint — an accepted trade-off for
 *               an anonymous first-party UUID that is never PII.
 */
function extrachill_analytics_visitor_cookie_client_config() {
	$config = array(
		'cookieName'   => EXTRACHILL_ANALYTICS_VISITOR_COOKIE,
		'cookieDomain' => '',
		'cookieMaxAge' => YEAR_IN_SECONDS,
	);

	if (
		extrachill_analytics_visitor_opted_out()
		|| ! extrachill_analytics_is_eligible_public_template_request()
		|| ! extrachill_analytics_request_host_is_first_party()
	) {
		return $config;
	}

	$config['cookieDomain'] = extrachill_analytics_visitor_cookie_domain();

	return $config;
}

/**
 * Whether the current request is a safe public browser template request.
 *
 * @return bool True for frontend GET/HEAD template requests.
 */
function extrachill_analytics_is_eligible_public_template_request() {
	if (
		is_preview()
		|| is_admin()
		|| wp_doing_ajax()
		|| wp_doing_cron()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| defined( 'WP_CLI' )
	) {
		return false;
	}

	$request_method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
		: '';
	if ( ! in_array( $request_method, array( 'GET', 'HEAD' ), true ) ) {
		return false;
	}

	return true;
}

/**
 * Whether the current template host belongs to the first-party network.
 *
 * @return bool True for the cookie domain or one of its subdomains.
 */
function extrachill_analytics_request_host_is_first_party() {

	$request_host  = isset( $_SERVER['HTTP_HOST'] )
		? wp_parse_url( 'https://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST )
		: '';
	$cookie_domain = ltrim( extrachill_analytics_visitor_cookie_domain(), '.' );
	if ( '' === $cookie_domain ) {
		$cookie_domain = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	if ( ! is_string( $request_host ) || '' === $request_host || ! is_string( $cookie_domain ) || '' === $cookie_domain ) {
		return false;
	}

	$request_host  = strtolower( rtrim( $request_host, '.' ) );
	$cookie_domain = strtolower( rtrim( $cookie_domain, '.' ) );
	$suffix        = '.' . $cookie_domain;

	return $request_host === $cookie_domain
		|| ( strlen( $request_host ) > strlen( $suffix ) && substr( $request_host, -strlen( $suffix ) ) === $suffix );
}

/**
 * Script handle for the shared, network-activated Chart.js v4 asset.
 *
 * Extra Chill Analytics is network-activated, so registering Chart.js once here
 * makes a single guaranteed-present copy available to every consumer on the
 * network — instead of each plugin re-bundling its own. Consumers (artist-
 * platform link-page analytics, the Mediavine revenue ARC, the Studio Network
 * tab) declare this handle as a script dependency and webpack-externalize their
 * `chart.js` import to the exposed global. See extrachill-analytics#93.
 */
const EXTRACHILL_ANALYTICS_CHART_HANDLE = 'extrachill-analytics-chart';

/** Shared on-demand analytics date-range script and style handle. */
const EXTRACHILL_ANALYTICS_DATE_RANGE_HANDLE = 'extrachill-analytics-date-range';

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
 * Register, but do not enqueue, the shared analytics date-range runtime.
 *
 * Consumers declare the handle as a dependency. The bundled Flatpickr runtime
 * exposes `window.ExtraChillAnalyticsDateRange.create()` for plain JavaScript or
 * framework wrappers, while the matching style handle owns Flatpickr's base and
 * analytics theme CSS.
 *
 * @return bool True when the built runtime is available.
 */
function extrachill_analytics_register_date_range_asset() {
	if (
		wp_script_is( EXTRACHILL_ANALYTICS_DATE_RANGE_HANDLE, 'registered' )
		&& wp_style_is( EXTRACHILL_ANALYTICS_DATE_RANGE_HANDLE, 'registered' )
	) {
		return true;
	}

	$asset_file = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'build/date-range.asset.php';
	$style_file = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'build/date-range.css';
	if ( ! file_exists( $asset_file ) || ! file_exists( $style_file ) ) {
		return false;
	}

	$asset = require $asset_file;
	wp_register_script(
		EXTRACHILL_ANALYTICS_DATE_RANGE_HANDLE,
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'build/date-range.js',
		isset( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
		isset( $asset['version'] ) ? $asset['version'] : EXTRACHILL_ANALYTICS_VERSION,
		true
	);
	wp_register_style(
		EXTRACHILL_ANALYTICS_DATE_RANGE_HANDLE,
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'build/date-range.css',
		array(),
		isset( $asset['version'] ) ? $asset['version'] : EXTRACHILL_ANALYTICS_VERSION
	);

	return true;
}
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_register_date_range_asset', 5 );
add_action( 'admin_enqueue_scripts', 'extrachill_analytics_register_date_range_asset', 5 );

/**
 * Enqueue view tracking on eligible public routes.
 */
function extrachill_analytics_enqueue_view_tracking() {
	if ( ! extrachill_analytics_is_eligible_public_template_request() ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '/';
	$source_path = extrachill_analytics_normalize_route_path( $request_uri );
	if ( '' === $source_path ) {
		return;
	}
	$route_family = extrachill_analytics_classify_current_route( $source_path );
	$post_id      = 'singular' === $route_family && is_singular() ? (int) get_the_ID() : 0;
	// Existing custom-domain singular views remain anonymous and post-backed;
	// route-level collection is first-party only.
	if ( $post_id <= 0 && ! extrachill_analytics_request_host_is_first_party() ) {
		return;
	}

	$request_host = isset( $_SERVER['HTTP_HOST'] )
		? wp_parse_url( 'https://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST )
		: '';
	if ( ! is_string( $request_host ) || '' === $request_host ) {
		return;
	}

	$js_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/js/view-tracking.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-view-tracking',
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/js/view-tracking.js',
		array(),
		(string) filemtime( $js_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	// Client-side visitor-cookie mint config. The domain is empty whenever this
	// request must not mint (custom-domain host, GPC/DNT opt-out, non-template
	// runtime), and view-tracking.js never mints without it. Cookie name,
	// domain, and max-age are static per site, so pages that are otherwise
	// cacheable stay cacheable — no per-visitor state ever reaches the markup.
	$cookie_config = extrachill_analytics_visitor_cookie_client_config();

	$config = array(
		'postId'       => $post_id,
		'sourcePath'   => $source_path,
		'routeFamily'  => $route_family,
		'proof'        => extrachill_analytics_pageview_proof(
			$post_id,
			$source_path,
			$route_family,
			$request_host
		),
		'endpoint'     => rest_url( 'wp-abilities/v1/abilities/extrachill/track-page-view/run' ),
		'cookieName'   => $cookie_config['cookieName'],
		'cookieDomain' => $cookie_config['cookieDomain'],
		'cookieMaxAge' => $cookie_config['cookieMaxAge'],
	);

	wp_add_inline_script(
		'extrachill-view-tracking',
		'window.ecViewTracking = ' . wp_json_encode( $config ) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_enqueue_view_tracking' );

// Custom-domain link pages intentionally bypass wp_head(), so their existing
// minimal-head lifecycle must enqueue the same signed Analytics-owned tracker.
add_action( 'extrachill_artist_link_page_minimal_head', 'extrachill_analytics_enqueue_view_tracking', 20 );

/**
 * Enqueue outbound-click, CTA-click, and form-submit tracking on every
 * front-end view.
 *
 * Unlike pageview tracking (singular only), a click or form submit can happen
 * from any front-end surface — archives, the homepage, taxonomy listings — so
 * this runs network-wide on all non-admin views. The handler is a single
 * delegated click listener plus a single delegated submit listener (see
 * assets/js/outbound-tracking.js). The click listener fires a sendBeacon
 * `outbound_click` event for an anchor to an off-network host (unchanged
 * behaviour) AND, independently, a `cta_click` event for any element matching
 * the design-system button classes, `[data-ec-track]`, or a submit control in
 * a form — an off-network design-system button can fire both, which is
 * intended. The submit listener fires `form_submit` for any form it can name.
 * See Extra-Chill/extrachill-analytics#293.
 *
 * Because every beacon fires only from a real, JS-executing browser, the data
 * is bot-filtered by construction — the same guarantee the bridge_click /
 * pageview beacons rely on. Visitor identity is omitted from cacheable HTML and
 * resolved by the browser-facing write adapter when a stable cookie exists.
 *
 * The network-host list is the canonical multisite map so an INTERNAL hop
 * (extrachill.com → community.extrachill.com, already covered by the conversion
 * map) is never miscounted as an outbound exit.
 *
 * `route` is classified server-side here (the same classifier pageview
 * tracking uses) and handed to the browser, rather than reclassified in JS:
 * the classifier reads live WP_Query conditionals (is_search(), is_archive(),
 * is_singular()...) that only exist during THIS template render, not at the
 * later async request the click/submit beacon makes.
 */
function extrachill_analytics_enqueue_outbound_tracking() {
	if ( is_admin() || is_preview() ) {
		return;
	}

	$js_path = EXTRACHILL_ANALYTICS_PLUGIN_DIR . 'assets/js/outbound-tracking.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

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

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '/';
	$source_path = extrachill_analytics_normalize_route_path( $request_uri );
	$route       = '' !== $source_path ? extrachill_analytics_classify_current_route( $source_path ) : 'other';

	wp_enqueue_script(
		'extrachill-outbound-tracking',
		EXTRACHILL_ANALYTICS_PLUGIN_URL . 'assets/js/outbound-tracking.js',
		array(),
		(string) filemtime( $js_path ),
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
			'networkHosts' => $network_hosts,
			'ctaEndpoint'  => rest_url( 'wp-abilities/v1/abilities/extrachill/track-cta-click/run' ),
			'formEndpoint' => rest_url( 'wp-abilities/v1/abilities/extrachill/track-form-submit/run' ),
			'route'        => $route,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_analytics_enqueue_outbound_tracking' );
