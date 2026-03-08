<?php
/**
 * Legacy URL Redirects
 *
 * Handles 301 redirects for old permalink structures that no longer resolve.
 * Fires early on template_redirect to catch 404s before they're logged.
 *
 * @package ExtraChill\SEO
 * @since 0.7.0
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect legacy date-prefixed URLs to current slug-based URLs.
 *
 * Handles two patterns:
 *   1. Blogger-era:  /YYYY/MM/slug.html  → /slug/
 *   2. Date-prefix:  /YYYY/MM/slug       → /slug/
 *
 * Only redirects if a published post with the matching slug actually exists,
 * to avoid redirecting to another 404.
 *
 * @since 0.7.0 Blogger-era .html redirects.
 * @since 0.8.6 Bare date-prefix redirects (no .html suffix).
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_404() ) {
			return;
		}

		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// Strip query string and fragment before matching.
		$request_path = strtok( $raw_uri, '?' );
		$request_path = strtok( $request_path, '#' );

		$slug = '';

		// Pattern 1: /YYYY/MM/slug.html (Blogger-era).
		if ( preg_match( '#^/\d{4}/\d{2}/(.+)\.html/?$#', $request_path, $matches ) ) {
			$slug = sanitize_title( $matches[1] );
		}

		// Pattern 2: /YYYY/MM/slug (bare date-prefix, no .html).
		if ( empty( $slug ) && preg_match( '#^/(\d{4})/(\d{2})/([^/.]+)/?$#', $request_path, $matches ) ) {
			$slug = sanitize_title( $matches[3] );
		}

		if ( empty( $slug ) ) {
			return;
		}

		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return;
		}

		$permalink = get_permalink( $posts[0] );

		if ( $permalink ) {
			wp_safe_redirect( $permalink, 301 );
			exit;
		}
	},
	5
);
