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
 * Redirect old Blogger-era .html permalinks to current slug-based URLs.
 *
 * Old format: /YYYY/MM/slug.html
 * New format: /slug/
 *
 * Only redirects if a published post with the matching slug actually exists,
 * to avoid redirecting to another 404.
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

		// Match /YYYY/MM/slug.html with optional trailing slash.
		if ( ! preg_match( '#^/\d{4}/\d{2}/(.+)\.html/?$#', $request_path, $matches ) ) {
			return;
		}

		$slug = sanitize_title( $matches[1] );

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
