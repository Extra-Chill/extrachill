<?php
/**
 * Pagination Filter for Multisite Search
 *
 * Fixes pagination URLs for multisite search where get_pagenum_link()
 * returns relative URLs due to blog context switching.
 *
 * @package ExtraChill\Search
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'extrachill_pagination_base_url', 'extrachill_search_pagination_base_url', 10, 2 );

/**
 * Override pagination base URL for search context.
 *
 * @param string $base_url Default pagination base URL.
 * @param string $context  Pagination context identifier.
 * @return string Corrected base URL for search pagination.
 */
function extrachill_search_pagination_base_url( $base_url, $context ) {
	if ( $context !== 'search' ) {
		return $base_url;
	}

	$base_url = home_url( '/page/%#%/' );

	if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
		$base_url .= '?' . sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
		$base_url = remove_query_arg( 'paged', $base_url );
	}

	return $base_url;
}
