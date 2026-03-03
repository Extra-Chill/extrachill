<?php
/**
 * Canonical URL Output
 *
 * Single output point for <link rel="canonical">. Computes a default
 * canonical URL, applies the extrachill_seo_canonical_url filter, and
 * renders.
 *
 * Plugins should NOT output their own <link rel="canonical"> tags.
 * Instead, hook into the extrachill_seo_canonical_url filter.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output canonical link tag.
 *
 * Single output point. Computes default canonical, applies filter, renders.
 */
add_action(
	'wp_head',
	function () {
		$canonical = ec_seo_get_final_canonical_url();

		if ( ! empty( $canonical ) ) {
			printf(
				'<link rel="canonical" href="%s" />' . "\n",
				esc_url( $canonical )
			);
		}
	},
	5
);

/**
 * Remove default wp_head output that extrachill-seo replaces or doesn't need.
 *
 * - rel_canonical: We handle all canonical output ourselves.
 * - rsd_link: RSD (Really Simple Discoverability) advertises xmlrpc.php,
 *   which is blocked by nginx. Removing it stops bots from hitting the
 *   blocked endpoint (~100 requests/day of log noise).
 */
add_action(
	'wp',
	function () {
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'rsd_link' );
	}
);

/**
 * Get the final canonical URL for the current page.
 *
 * Computes a default, then applies the extrachill_seo_canonical_url filter.
 *
 * @return string Canonical URL or empty string.
 */
function ec_seo_get_final_canonical_url() {
	$canonical = ec_seo_get_default_canonical_url();

	/**
	 * Filter the canonical URL before output.
	 *
	 * Plugins should return a URL string to override the default canonical.
	 * Return empty string to suppress canonical output entirely.
	 *
	 * @param string $canonical Default canonical URL.
	 */
	$canonical = apply_filters( 'extrachill_seo_canonical_url', $canonical );

	return $canonical;
}

/**
 * Compute default canonical URL from WordPress context.
 *
 * Handles login pages, bbPress subpages, taxonomy cross-site authority,
 * and standard WordPress page types.
 *
 * @return string Canonical URL.
 */
function ec_seo_get_default_canonical_url() {
	// Login pages across the network canonicalize to community site.
	if ( is_page( 'login' ) ) {
		$canonical = ec_seo_get_login_canonical_url();
		if ( $canonical ) {
			return $canonical;
		}
	}

	// bbPress user subpages canonicalize to main user profile.
	if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
		$canonical = ec_seo_get_bbp_user_subpage_canonical();
		if ( $canonical ) {
			return $canonical;
		}
	}

	// Taxonomy archives may have cross-site canonical authority.
	if ( is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof \WP_Term && isset( $term->taxonomy ) ) {
			// Location archives must be indexable on all sites, so keep them self-canonical.
			if ( 'location' !== $term->taxonomy ) {
				$canonical = ec_seo_get_taxonomy_canonical_url();
				if ( $canonical ) {
					return $canonical;
				}
			}
		}
	}

	// Standard canonical from shared helper.
	return ec_seo_get_canonical_url();
}

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

/**
 * Get canonical URL for bbPress user subpages.
 *
 * User subpages (replies, topics, favorites, etc.) canonicalize to the
 * main user profile to consolidate link equity.
 *
 * @return string|null Canonical URL or null if not a user subpage.
 */
function ec_seo_get_bbp_user_subpage_canonical() {
	if ( ! function_exists( 'bbp_get_displayed_user_id' ) ) {
		return null;
	}

	// Check if we're on a user subpage (not the main profile).
	$is_user_subpage = (
		( function_exists( 'bbp_is_single_user_replies' ) && bbp_is_single_user_replies() ) ||
		( function_exists( 'bbp_is_single_user_topics' ) && bbp_is_single_user_topics() ) ||
		( function_exists( 'bbp_is_single_user_engagements' ) && bbp_is_single_user_engagements() ) ||
		( function_exists( 'bbp_is_favorites' ) && bbp_is_favorites() ) ||
		( function_exists( 'bbp_is_subscriptions' ) && bbp_is_subscriptions() ) ||
		( function_exists( 'bbp_is_single_user_edit' ) && bbp_is_single_user_edit() )
	);

	if ( ! $is_user_subpage ) {
		return null;
	}

	$user_id = bbp_get_displayed_user_id();
	if ( ! $user_id ) {
		return null;
	}

	// Return main user profile URL.
	if ( function_exists( 'bbp_get_user_profile_url' ) ) {
		return bbp_get_user_profile_url( $user_id );
	}

	return null;
}
