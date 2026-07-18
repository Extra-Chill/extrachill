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
