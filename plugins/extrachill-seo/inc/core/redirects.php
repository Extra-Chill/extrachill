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

		if ( ! empty( $posts ) ) {
			$permalink = get_permalink( $posts[0] );

			if ( $permalink ) {
				wp_safe_redirect( $permalink, 301 );
				exit;
			}
		}

		// No published post found — check the redirect rules table for this slug.
		// This handles renamed posts where /YYYY/MM/old-slug.html needs to chain
		// through the redirect rule for /old-slug → /new-slug.
		if ( function_exists( __NAMESPACE__ . '\\extrachill_seo_get_redirect_by_url' ) ) {
			$rule = extrachill_seo_get_redirect_by_url( '/' . $slug );

			if ( $rule ) {
				extrachill_seo_record_redirect_hit( $rule->id );

				$to_url     = $rule->to_url;
				$status_code = in_array( (int) $rule->status_code, array( 301, 302, 307, 308 ), true )
					? (int) $rule->status_code
					: 301;

				if ( strpos( $to_url, 'http' ) !== 0 ) {
					$to_url = home_url( $to_url );
				}

				wp_safe_redirect( $to_url, $status_code );
				exit;
			}
		}
	},
	5
);
