<?php
/**
 * Redirect Rules Matcher
 *
 * On 404, checks the redirect rules table for a matching source URL
 * and performs the redirect. Fires AFTER pattern-based redirects (like .html)
 * so patterns handle the bulk, and the rules table handles one-offs.
 *
 * @package ExtraChill\SEO
 * @since 0.9.0
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Match incoming 404 requests against the redirect rules table.
 *
 * Priority 6 — after pattern redirects (priority 5), before 404 tracking.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_404() ) {
			return;
		}

		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// Strip query string for matching.
		$request_path = strtok( $raw_uri, '?' );
		$request_path = strtok( $request_path, '#' );

		// Normalize: ensure leading slash, strip trailing slash.
		$request_path = '/' . ltrim( $request_path, '/' );
		$request_path = untrailingslashit( $request_path );

		if ( empty( $request_path ) || '/' === $request_path ) {
			return;
		}

		// Look up in redirect rules table.
		$rule = extrachill_seo_get_redirect_by_url( $request_path );

		if ( ! $rule ) {
			// Also try with trailing slash variant.
			$rule = extrachill_seo_get_redirect_by_url( $request_path . '/' );
		}

		if ( ! $rule ) {
			return;
		}

		// Record the hit.
		extrachill_seo_record_redirect_hit( $rule->id );

		$to_url = $rule->to_url;

		// If the target is a relative path, build the full URL.
		if ( strpos( $to_url, 'http' ) !== 0 ) {
			$to_url = home_url( $to_url );
		}

		$status_code = in_array( (int) $rule->status_code, array( 301, 302, 307, 308 ), true )
			? (int) $rule->status_code
			: 301;

		wp_safe_redirect( $to_url, $status_code );
		exit;
	},
	6
);
