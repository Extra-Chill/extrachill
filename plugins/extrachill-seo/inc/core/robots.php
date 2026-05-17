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
				// Festival taxonomy pages are handled as hub pages and must remain indexable.
				if ( 'festival' !== (string) $term->taxonomy ) {
					$robots['noindex'] = true;
					$robots['follow']  = true;
					unset( $robots['max-image-preview'] );
					return $robots;
				}
			}
		}

		// Noindex bbPress user subpages (replies, topics, engagements, favorites, subscriptions, edit).
		// Main user profile remains indexed. Subpages are low-value and should not appear in sitelinks.
		if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
			$noindex_user_subpages = (
				( function_exists( 'bbp_is_single_user_replies' ) && bbp_is_single_user_replies() ) ||
				( function_exists( 'bbp_is_single_user_topics' ) && bbp_is_single_user_topics() ) ||
				( function_exists( 'bbp_is_single_user_engagements' ) && bbp_is_single_user_engagements() ) ||
				( function_exists( 'bbp_is_favorites' ) && bbp_is_favorites() ) ||
				( function_exists( 'bbp_is_subscriptions' ) && bbp_is_subscriptions() ) ||
				( function_exists( 'bbp_is_single_user_edit' ) && bbp_is_single_user_edit() )
			);

			if ( $noindex_user_subpages ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
				unset( $robots['max-image-preview'] );
				return $robots;
			}
		}

		return $robots;
	}
);
