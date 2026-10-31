<?php
/**
 * Cross-Site Bridge Instrumentation
 *
 * Instruments the shared cross-site link engine ONCE so every consumer — the
 * blog bridge, the events bridge, the news-wire bridge, and the taxonomy /
 * artist-profile cross-site renderers — inherits two sibling measurements
 * without any per-consumer code:
 *
 *   1. CLICK event   — fires on the first click per cross-site link element and
 *                      page load.
 *                      Measures a click event. A prefetch request can create a
 *                      UTM arrival but does not execute this page handler.
 *
 *   2. IMPRESSION event — fires once per link element and page load when at
 *                      least 50% of the element enters the viewport. Browsers
 *                      without IntersectionObserver use a scroll/resize check
 *                      bounded to 30 seconds. A click emits a still-missing
 *                      impression first because the interaction proves
 *                      exposure.
 *
 * Client-side dedupe targets one click attempt per viewport-exposed opportunity,
 * but the sibling events use independent best-effort requests. Stored rows can
 * be missing under asymmetric loss or duplicated by a retry after an ambiguous
 * failure, so their quotient is a stored click-to-exposure ratio, not a
 * mathematically bounded CTR. Both require page JavaScript execution, which
 * filters non-JS crawlers and prefetch requests. It does not establish human
 * identity or exclude JS-capable automation (see extrachill-network#58).
 *
 * NO AJAX (system rule). The browser ships both events with
 * `navigator.sendBeacon()` (fire-and-forget, survives navigation) to the
 * canonical extrachill-api analytics routes — clicks to /analytics/click
 * (click_type=bridge), impressions to /analytics/impression
 * (impression_type=bridge). Those thin wrappers record via the network-wide
 * `extrachill/track-analytics-event` ability owned by extrachill-analytics.
 *
 * This file owns ONLY the client: enqueue, localize, and the sendBeacon JS.
 * The REST receiver lives in extrachill-api (the canonical REST home for
 * ability wrappers) — see extrachill-network#62.
 *
 * @package ExtraChillNetwork
 * @since 1.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the bridge instrumentation script on bridge-capable front-end views.
 *
 * Singular views and network homepages can both act as cross-site routers. The
 * script self-gates at runtime: it observes only bridge links present when the
 * deferred script initializes and attempts at most one exposure and click per
 * element and page load. Dynamically inserted links remain navigable but are
 * intentionally outside this measurement contract. No initial bridge cards on
 * the page == zero beacons.
 */
function extrachill_bridge_enqueue_instrumentation() {
	if ( is_admin() || ( ! is_singular() && ! is_front_page() && ! is_home() ) || is_preview() ) {
		return;
	}

	$js_path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/js/bridge-instrumentation.js';
	if ( ! file_exists( $js_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-bridge-instrumentation',
		EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/js/bridge-instrumentation.js',
		array(),
		filemtime( $js_path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script(
		'extrachill-bridge-instrumentation',
		'ecBridgeInstrumentation',
		array(
			'clickEndpoint'      => rest_url( 'extrachill/v1/analytics/click' ),
			'impressionEndpoint' => rest_url( 'extrachill/v1/analytics/impression' ),
			'linkClass'          => 'ec-cross-site-link',
			'sourcePost'         => (int) get_the_ID(),
			'sourceSite'         => (string) extrachill_get_current_site_key(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_bridge_enqueue_instrumentation', 20 );
