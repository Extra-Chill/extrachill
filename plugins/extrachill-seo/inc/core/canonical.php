<?php
/**
 * Canonical URL Output
 *
 * WordPress core's rel_canonical() only outputs for singular contexts.
 * This outputs a canonical URL for all page types using the same URL
 * logic as Open Graph.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output canonical link tag
 */
add_action(
	'wp_head',
	function () {
		// Avoid duplicate canonical on singular content (WordPress core outputs this).
		if ( is_singular() ) {
			return;
		}

		$canonical = ec_seo_get_canonical_url();

		if ( empty( $canonical ) ) {
			return;
		}

		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( $canonical )
		);
	},
	5
);
