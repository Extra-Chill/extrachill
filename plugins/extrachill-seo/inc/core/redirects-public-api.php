<?php
/**
 * Redirects Public API
 *
 * Global-scope helper functions intended for cross-plugin consumption.
 * These let other plugins (e.g. extrachill-analytics) ask whether a URL
 * is currently covered by an active redirect rule, without depending on
 * the internal namespaced implementation. Consumers must call these
 * behind a function_exists() guard so they never hard-depend on this plugin.
 *
 * @package ExtraChill\SEO
 * @since 0.9.1
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'extrachill_seo_normalize_redirect_url' ) ) {
	/**
	 * Normalize a URL path for redirect-rule matching.
	 *
	 * Mirrors the normalization used by the redirect matcher
	 * (inc/core/redirects-matcher.php): strip query/fragment, ensure a
	 * leading slash, and strip the trailing slash.
	 *
	 * @param string $url Raw URL or path.
	 * @return string Normalized path (leading slash, no trailing slash), or '' if empty/root.
	 */
	function extrachill_seo_normalize_redirect_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		// Drop scheme/host if a full URL was passed; keep only the path.
		$path = $url;
		if ( false !== strpos( $path, '://' ) ) {
			$parsed = wp_parse_url( $path );
			$path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
		}

		// Strip query string and fragment for matching.
		$path = strtok( $path, '?' );
		$path = strtok( $path, '#' );

		if ( false === $path || '' === $path ) {
			return '';
		}

		// Normalize: ensure leading slash, strip trailing slash.
		$path = '/' . ltrim( $path, '/' );
		$path = untrailingslashit( $path );

		if ( '' === $path || '/' === $path ) {
			return '';
		}

		return $path;
	}
}

if ( ! function_exists( 'extrachill_seo_url_has_active_redirect' ) ) {
	/**
	 * Determine whether a single URL currently matches an active redirect rule.
	 *
	 * @param string $url Raw URL or path.
	 * @return bool True if an active redirect rule matches.
	 */
	function extrachill_seo_url_has_active_redirect( $url ) {
		$normalized = extrachill_seo_normalize_redirect_url( $url );

		if ( '' === $normalized ) {
			return false;
		}

		$matched = extrachill_seo_filter_redirected_urls( array( $url ) );

		return ! empty( $matched );
	}
}

if ( ! function_exists( 'extrachill_seo_filter_redirected_urls' ) ) {
	/**
	 * Filter a list of URLs down to those that currently match an active redirect rule.
	 *
	 * Uses the same normalization as the redirect matcher and checks both the
	 * trailing-slash and non-trailing-slash variants of each URL against the
	 * redirect rules table (active = 1). Performs a single bulk query rather
	 * than N+1 per-URL lookups.
	 *
	 * @param array $urls List of raw URLs or paths.
	 * @return array Subset of the input array (original values preserved) that have an active redirect.
	 */
	function extrachill_seo_filter_redirected_urls( array $urls ) {
		global $wpdb;

		if ( empty( $urls ) ) {
			return array();
		}

		// Build a map of candidate normalized lookup values -> original input URLs.
		// Each normalized URL gets two candidates: without and with a trailing slash,
		// matching the matcher's two-pass lookup behavior.
		$candidate_to_originals = array();
		$lookup_values          = array();

		foreach ( $urls as $original ) {
			$normalized = extrachill_seo_normalize_redirect_url( $original );

			if ( '' === $normalized ) {
				continue;
			}

			$variants = array( $normalized, $normalized . '/' );

			foreach ( $variants as $variant ) {
				if ( ! isset( $candidate_to_originals[ $variant ] ) ) {
					$candidate_to_originals[ $variant ] = array();
					$lookup_values[]                    = $variant;
				}
				$candidate_to_originals[ $variant ][] = $original;
			}
		}

		if ( empty( $lookup_values ) ) {
			return array();
		}

		$lookup_values = array_values( array_unique( $lookup_values ) );
		$table         = \ExtraChill\SEO\Core\extrachill_seo_redirects_table();

		$placeholders = implode( ', ', array_fill( 0, count( $lookup_values ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is an internal table name and $placeholders is a generated list of %s tokens bound via $lookup_values.
		$sql          = "SELECT from_url FROM {$table} WHERE active = 1 AND from_url IN ( {$placeholders} )";
		$matched_rows = $wpdb->get_col( $wpdb->prepare( $sql, $lookup_values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( empty( $matched_rows ) ) {
			return array();
		}

		// Resolve matched from_url values back to the original input URLs.
		$redirected = array();
		foreach ( $matched_rows as $from_url ) {
			if ( isset( $candidate_to_originals[ $from_url ] ) ) {
				foreach ( $candidate_to_originals[ $from_url ] as $original ) {
					$redirected[ $original ] = true;
				}
			}
		}

		return array_keys( $redirected );
	}
}
