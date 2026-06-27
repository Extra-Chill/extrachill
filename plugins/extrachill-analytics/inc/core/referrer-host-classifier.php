<?php
/**
 * Referrer Host Classifier
 *
 * Owns the ONE place that normalizes a raw referrer URL into a stable,
 * low-cardinality `referrer_host` — just the host (e.g. `chatgpt.com`,
 * `facebook.com`, `community.extrachill.com`), never the full URL with query
 * strings. The verdict is stamped into a `pageview` event's `event_data` under
 * the `referrer_host` key at write time (see the track-page-view ability),
 * mirroring how the search SURFACE `source` (issue #86) and the canonical
 * human/bot `is_bot` flag are stamped on events.
 *
 * WHY HOST-ONLY (not the full referrer URL)
 * -----------------------------------------
 * The full referrer URL carries query strings that can leak PII (search terms,
 * session tokens, email addresses in `?email=` links, etc.) and explodes
 * cardinality so no reader can GROUP BY it cleanly. The host alone answers the
 * question the field exists to answer — "what surface SENT this reader here?"
 * (an AI engine like chatgpt.com / perplexity.ai, a social network, a search
 * engine, another network subdomain) — with zero PII and tiny cardinality.
 *
 * WHY THE RAW REFERRER COMES FROM THE CLIENT
 * ------------------------------------------
 * The pageview fires via a deferred `sendBeacon` AFTER page load, so the
 * beacon request's own HTTP `Referer` header is the article page itself, NOT
 * the page the reader navigated FROM. `wp_get_referer()` would therefore
 * mis-attribute every pageview to its own URL. The TRUE referrer is only
 * available client-side as `document.referrer`, which the view-tracking beacon
 * threads into the `referrer` input. This classifier reduces that raw URL to a
 * host server-side at write time.
 *
 * Direct traffic (empty referrer) and same-host referrers (in-page reloads /
 * intra-page nav that the browser still reports) collapse to '' so the field
 * is only present when there is a meaningful cross-host provenance signal.
 *
 * @package ExtraChill\Analytics
 * @since 0.22.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a raw referrer URL into a low-cardinality host.
 *
 * Pure given its inputs: pass the raw referrer (typically `document.referrer`
 * from the beacon) and optionally the current request host so the function is
 * testable without a live request. When `$current_host` is null it reads the
 * live request host (read-only).
 *
 * @param string      $raw_referrer The raw referrer URL (e.g. document.referrer).
 *                                  May be empty (direct traffic).
 * @param string|null $current_host Optional current host override for testing.
 *                                  Defaults to the live request host.
 * @return string The normalized referrer host (e.g. `chatgpt.com`), or '' for
 *                direct traffic, same-host referrers, or an unparseable URL.
 */
function extrachill_analytics_normalize_referrer_host( $raw_referrer, $current_host = null ) {
	$raw_referrer = trim( (string) $raw_referrer );

	// Direct traffic (no referrer) — nothing to attribute. Omit the field.
	if ( '' === $raw_referrer ) {
		return '';
	}

	// Extract the host component only. wp_parse_url() is the canonical, safe
	// URL parser; a referrer without a host (e.g. a bare path the browser
	// reported, or a malformed value) yields no usable provenance signal.
	$host = wp_parse_url( $raw_referrer, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return '';
	}

	// Lowercase + strip a leading `www.` so `www.facebook.com` and
	// `facebook.com` collapse to one bucket, keeping cardinality low.
	$host = strtolower( $host );
	if ( 0 === strpos( $host, 'www.' ) ) {
		$host = substr( $host, 4 );
	}

	// Same-host referrers carry no cross-surface signal (an in-page reload or
	// intra-page navigation the browser still reports as a referrer). Drop them
	// so the field means "a DIFFERENT surface sent this reader here". The
	// current request host is resolved the same way for an apples-to-apples
	// comparison.
	if ( null === $current_host ) {
		$current_host = isset( $_SERVER['HTTP_HOST'] )
			? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';
	}
	$current_host = strtolower( trim( (string) $current_host ) );
	if ( 0 === strpos( $current_host, 'www.' ) ) {
		$current_host = substr( $current_host, 4 );
	}

	if ( '' !== $current_host && $host === $current_host ) {
		return '';
	}

	return $host;
}
