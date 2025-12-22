<?php
/**
 * Robots Meta Directives
 *
 * Controls indexing behavior using WordPress native wp_robots filter.
 * Noindexes: search results, date archives, sparse taxonomy terms.
 * Indexes: all other content including pagination.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modify robots meta directives
 *
 * Uses WordPress native wp_robots filter (WP 5.7+)
 */
add_filter(
	'wp_robots',
	function ( $robots ) {
		// Noindex search results
		if ( is_search() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['max-image-preview'] );
			return $robots;
		}

		// Noindex date archives (day, month, year)
		if ( is_date() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['max-image-preview'] );
			return $robots;
		}

		// Noindex sparse taxonomy terms (fewer than 2 posts).
		// Applies to category, tag, and custom taxonomy term archives.
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && isset( $term->count ) && (int) $term->count < 2 ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
				unset( $robots['max-image-preview'] );
				return $robots;
			}
		}

		return $robots;
	}
);
