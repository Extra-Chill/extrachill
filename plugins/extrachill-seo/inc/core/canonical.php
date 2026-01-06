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
		// Login pages across the network canonicalize to community site.
		if ( is_page( 'login' ) ) {
			$canonical = ec_seo_get_login_canonical_url();
			if ( $canonical ) {
				printf(
					'<link rel="canonical" href="%s" />' . "\n",
					esc_url( $canonical )
				);
				return;
			}
		}

		// Taxonomy archives may have cross-site canonical authority.
		if ( is_tax() ) {
			$canonical = ec_seo_get_taxonomy_canonical_url();
			if ( $canonical ) {
				printf(
					'<link rel="canonical" href="%s" />' . "\n",
					esc_url( $canonical )
				);
				return;
			}
		}

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

/**
 * Get canonical URL for login page (community site is canonical).
 *
 * @return string|null Canonical login URL or null if helper unavailable.
 */
function ec_seo_get_login_canonical_url() {
	if ( ! function_exists( 'ec_get_site_url' ) ) {
		return null;
	}

	$community_url = ec_get_site_url( 'community' );
	if ( ! $community_url ) {
		return null;
	}

	return trailingslashit( $community_url ) . 'login/';
}

/**
 * Get canonical URL for taxonomy archives with cross-site authority.
 *
 * Delegates to extrachill-multisite canonical authority system.
 * Returns cross-domain URL when another site is authoritative,
 * or self-canonical URL when current site is authoritative.
 *
 * @return string Canonical URL for the taxonomy archive.
 */
function ec_seo_get_taxonomy_canonical_url() {
	$term = get_queried_object();
	if ( ! $term || ! isset( $term->taxonomy ) ) {
		return ec_seo_get_canonical_url();
	}

	// Check for cross-site canonical authority.
	if ( function_exists( 'ec_get_canonical_authority_url' ) ) {
		$authority_url = ec_get_canonical_authority_url( $term, $term->taxonomy );
		if ( $authority_url ) {
			return $authority_url;
		}
	}

	// No cross-site authority, use standard self-canonical.
	return ec_seo_get_canonical_url();
}
