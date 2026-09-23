<?php
/**
 * Public route normalization and classification.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a browser route to a bounded, query-free path.
 *
 * @param string $route Raw URL or path.
 * @return string Normalized path, or an empty string when invalid.
 */
function extrachill_analytics_normalize_route_path( $route ) {
	$route = trim( (string) $route );
	if ( '' === $route || false !== strpos( $route, "\0" ) ) {
		return '';
	}

	$path = wp_parse_url( $route, PHP_URL_PATH );
	if ( ! is_string( $path ) ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );
	$path = preg_replace( '#/+#', '/', $path );
	if ( ! is_string( $path ) ) {
		return '';
	}

	// Keep malformed or attacker-generated paths from creating unbounded rows.
	return substr( $path, 0, 512 );
}

/**
 * Reduce an HTTP(S) URL or root-relative route to origin plus path only.
 *
 * @param string $url Raw URL or path.
 * @return string Canonical URL/path, or an empty string when invalid.
 */
function extrachill_analytics_canonicalize_tracked_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || false !== strpos( $url, "\0" ) ) {
		return '';
	}

	if ( '/' === $url[0] && 0 !== strpos( $url, '//' ) ) {
		return extrachill_analytics_normalize_route_path( $url );
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = strtolower( (string) $parts['scheme'] );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}

	$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
	if ( '' === $host || preg_match( '/[\s\/?#@]/', $host ) ) {
		return '';
	}

	$path = extrachill_analytics_normalize_route_path( isset( $parts['path'] ) ? (string) $parts['path'] : '/' );
	if ( '' === $path ) {
		return '';
	}

	$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

	return $scheme . '://' . $host . $port . $path;
}

/**
 * Return the route families accepted by pageview events.
 *
 * @return string[] Route family slugs.
 */
function extrachill_analytics_route_families() {
	return array( 'singular', 'home', 'archive', 'search', 'auth', 'directory', 'other' );
}

/**
 * Reduce a CTA destination to a bounded host+path string, scheme dropped.
 *
 * `cta_click.dest` is deliberately more compact than the scheme-carrying
 * `dest_url` used by outbound_click: it exists for stable hashing and for a
 * human scanning a report, not for building a clickable link. The browser
 * always sends an already-reduced `host/path` string (no scheme); a bare
 * https:// prefix is assumed so the existing canonical-URL validator (host
 * format, no embedded credentials, no whitespace) can be reused unchanged.
 * Query strings and fragments are always stripped.
 *
 * @param string $dest Raw client-supplied destination (host+path, or a full URL).
 * @return string Bounded `host/path` string, or '' when the value cannot be
 *                resolved to a valid host.
 */
function extrachill_analytics_normalize_cta_dest( $dest ) {
	$dest = trim( (string) $dest );
	if ( '' === $dest ) {
		return '';
	}

	if ( ! preg_match( '#^https?://#i', $dest ) ) {
		$dest = 'https://' . ltrim( $dest, '/' );
	}

	$canonical = extrachill_analytics_canonicalize_tracked_url( $dest );
	if ( '' === $canonical ) {
		return '';
	}

	$parts = wp_parse_url( $canonical );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

	return substr( strtolower( rtrim( (string) $parts['host'], '.' ) ) . $path, 0, 512 );
}

/**
 * Classify the current frontend template into a bounded route family.
 *
 * @param string $path Normalized current path.
 * @return string Route family slug.
 */
function extrachill_analytics_classify_current_route( $path ) {
	$path = extrachill_analytics_normalize_route_path( $path );

	if ( function_exists( 'is_search' ) && is_search() ) {
		return 'search';
	}

	if ( '/' === $path || ( function_exists( 'is_front_page' ) && is_front_page() ) || ( function_exists( 'is_home' ) && is_home() ) ) {
		return 'home';
	}

	if ( preg_match( '#^/(login|log-in|signin|sign-in|register|sign-up|signup|account|wp-login\.php)(/|$)#i', $path ) ) {
		return 'auth';
	}

	if ( function_exists( 'is_archive' ) && is_archive() ) {
		return 'archive';
	}

	if ( function_exists( 'is_singular' ) && is_singular() ) {
		return 'singular';
	}

	if ( preg_match( '#^/(events|forums?|members?|artists?|venues?|locations?|festivals?|directory|newsletter)(/|$)#i', $path ) ) {
		return 'directory';
	}

	return 'other';
}
