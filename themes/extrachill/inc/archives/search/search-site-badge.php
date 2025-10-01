<?php
/**
 * Search Site Badge Component
 *
 * Displays site badge for multisite search results.
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Display site badge for multisite search results
 *
 * Shows which site the search result originated from when different from current site.
 * Uses metadata attached by multisite search integration (_site_name, _site_url).
 */
function extrachill_search_site_badge() {
	if ( ! is_search() ) {
		return;
	}

	global $post;

	// Check if post has site metadata from multisite search
	if ( ! isset( $post->_site_name ) || ! isset( $post->_site_url ) ) {
		return;
	}

	// Only show badge if from different site
	$current_site = parse_url( home_url(), PHP_URL_HOST );
	if ( $post->_site_url === $current_site ) {
		return;
	}

	echo '<span class="site-badge">From: ' . esc_html( $post->_site_name ) . '</span>';
}
add_action( 'extrachill_archive_above_tax_badges', 'extrachill_search_site_badge' );
