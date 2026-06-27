<?php
/**
 * Search Source (Surface) Classifier
 *
 * Owns the ONE place that decides which search SURFACE fired a `search`
 * analytics event — the nav/header box, an archive/results-page search box,
 * the bbPress in-forum search, etc. The verdict is a stable, low-cardinality
 * identifier stamped into `event_data` under the `source` key at write time
 * (see extrachill_track_analytics_event() in events.php), mirroring how the
 * canonical human/bot `is_bot` flag is stamped on every event.
 *
 * WHY STAMP AT WRITE TIME (not at the upstream capture site)
 * ----------------------------------------------------------
 * Every `search` event currently flows through a single capture listener in
 * the extrachill-search plugin (extrachill_search_performed →
 * track_search_analytics), which lives OUTSIDE this plugin. Threading an
 * explicit `source` through that caller is the cleanest long-term shape, but
 * it cannot be done from this plugin without editing extrachill-search. By
 * deriving the surface here — from the request context the search write
 * already has (the `source_url` on the payload, the current request host and
 * URI, and the bbPress `bbp_search` query var) — EVERY new `search` event
 * carries a `source` going forward regardless of which surface fired it, with
 * no cross-plugin change required. Callers that already know their surface can
 * still pass an explicit `event_data['source']`; that always wins.
 *
 * The identifiers are intentionally low-cardinality so get-search-gaps and
 * other readers can GROUP BY them cleanly:
 *   - `nav`           — search box on a normal (non-results) page: the
 *                       homepage / header / nav search. The dominant surface
 *                       (e.g. source_url is a bare site root).
 *   - `archive`       — search fired from a search-results / archive / paged
 *                       listing page (source_url carries ?s= / &s= / /search/
 *                       / /page/). The user refined a query from a results
 *                       page.
 *   - `bbpress_forum` — community in-forum bbPress search (the request carries
 *                       the `bbp_search` query var).
 *   - `unknown`       — no usable signal. Field is still present (never NULL on
 *                       new rows) so readers get one consistent shape.
 *
 * @package ExtraChill\Analytics
 * @since 0.21.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classify which search surface fired a `search` event.
 *
 * Pure given its inputs: pass an explicit `$source_url` and (optionally) the
 * request URI so the function is testable without a live request. When
 * `$request_uri` is null it reads `$_SERVER['REQUEST_URI']` (read-only).
 *
 * @param string      $source_url  The page the user was on before searching
 *                                 (the `source_url` already attached to the
 *                                 search event). May be empty.
 * @param string|null $request_uri Optional request URI override for testing.
 *                                 Defaults to the live request URI.
 * @return string One of: 'nav', 'archive', 'bbpress_forum', 'unknown'.
 */
function extrachill_analytics_classify_search_source( $source_url = '', $request_uri = null ) {
	$source_url = (string) $source_url;

	if ( null === $request_uri ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';
	}

	// bbPress in-forum search: the strongest, most specific signal. The forum
	// filter-bar search submits a `bbp_search` query var, so when it's present
	// on the live request the search originated in the community forum search
	// box rather than the network nav/results search.
	if ( ! empty( $_GET['bbp_search'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'bbpress_forum';
	}
	if ( '' !== $request_uri && false !== stripos( $request_uri, 'bbp_search' ) ) {
		return 'bbpress_forum';
	}

	// Beyond bbPress, the originating surface is read off the source_url — the
	// page the user submitted the search FROM. With no source_url there is no
	// signal to distinguish surfaces.
	if ( '' === trim( $source_url ) ) {
		return 'unknown';
	}

	// A search submitted FROM a results/archive/paged page is a refinement on
	// an existing results view, not a fresh nav search. Detect the canonical
	// search/archive markers in the originating URL.
	if ( extrachill_analytics_url_is_search_surface( $source_url ) ) {
		return 'archive';
	}

	// Otherwise the search was launched from a normal content page — the
	// header/nav search box. This is the dominant human-search surface.
	return 'nav';
}

/**
 * Whether a URL looks like a search-results / archive / paged listing page.
 *
 * Used to distinguish an `archive` refinement search (submitted from a results
 * page) from a `nav` search (submitted from a normal content page).
 *
 * @param string $url Candidate URL (typically the search event's source_url).
 * @return bool True when the URL carries search/archive/pagination markers.
 */
function extrachill_analytics_url_is_search_surface( $url ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return false;
	}

	// Pretty + query-string search markers and core pagination. `/search/` is
	// the network search permalink base; `s=` is the core search query var;
	// `/page/N` and `paged=` are core pagination that only appears on listing
	// (archive / search-results) views.
	$needles = array(
		'/search/',
		'?s=',
		'&s=',
		'/page/',
		'?paged=',
		'&paged=',
	);

	foreach ( $needles as $needle ) {
		if ( false !== stripos( $url, $needle ) ) {
			return true;
		}
	}

	return false;
}
