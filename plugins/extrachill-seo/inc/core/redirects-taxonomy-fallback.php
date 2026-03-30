<?php
/**
 * Taxonomy Fallback Redirects
 *
 * On 404, if the request is for a deleted tag archive (/tag/{slug}/),
 * checks whether the slug exists in the artist, venue, or festival
 * taxonomies and 301 redirects to the correct archive URL.
 *
 * Auto-inserts a DB redirect rule on first hit so subsequent requests
 * are served by the fast exact-match at priority 6.
 *
 * @package ExtraChill\SEO
 * @since 0.9.6
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomies to check, in priority order.
 *
 * When a /tag/{slug}/ 404s, we look for the slug in each of these
 * taxonomies. First match wins.
 */
define( 'EXTRACHILL_SEO_TAG_FALLBACK_TAXONOMIES', array( 'artist', 'festival', 'venue' ) );

/**
 * Redirect deleted tag archives to their new taxonomy archive.
 *
 * Priority 7 — after DB rules matcher (priority 6), before 404 tracking.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_404() ) {
			return;
		}

		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// Strip query string and fragment.
		$request_path = strtok( $raw_uri, '?' );
		$request_path = strtok( $request_path, '#' );

		// Only handle /tag/{slug}/ requests.
		if ( ! preg_match( '#^/tag/([^/]+)/?$#', $request_path, $matches ) ) {
			return;
		}

		$slug = sanitize_title( $matches[1] );

		if ( empty( $slug ) ) {
			return;
		}

		// Check each taxonomy for a matching term.
		foreach ( EXTRACHILL_SEO_TAG_FALLBACK_TAXONOMIES as $taxonomy ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );

			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$term_link = get_term_link( $term, $taxonomy );

			if ( is_wp_error( $term_link ) ) {
				continue;
			}

			// Auto-insert a DB rule so the fast matcher handles future hits.
			$from_url = '/tag/' . $slug;

			extrachill_seo_add_redirect(
				$from_url,
				$term_link,
				301,
				sprintf( 'Auto: tag migrated to %s taxonomy', $taxonomy ),
				'auto-taxonomy-fallback'
			);

			wp_safe_redirect( $term_link, 301, 'ExtraChill SEO' );
			exit;
		}
	},
	7
);
