<?php
/**
 * Core Search Functions for ExtraChill Search Plugin
 *
 * Provides network-wide search across all nine sites using domain-based resolution
 * and WordPress native multisite functions with automatic blog-id-cache.
 *
 * @package ExtraChill\Search
 * @since 0.1.0
 */

/**
 * Blog IDs deliberately excluded from public network search.
 *
 * These are real, active network sites (present in ec_get_domain_map()) that
 * are intentionally NOT searchable from the reader-facing network scope.
 * This is a documented decision, not an omission — see the per-entry reason.
 *
 * - Studio (blog 12, studio.extrachill.com): internal editorial tool site
 *   (compose, media proxy, autosave, review queue, social drafts). Its
 *   `post`/`page` content is staff-facing working material, not published
 *   editorial, and should not surface in public search results.
 *
 * @return int[] Excluded blog IDs.
 */
function extrachill_get_network_search_excluded_blog_ids() {
	$excluded = array();

	if ( function_exists( 'ec_get_blog_id' ) ) {
		$studio_blog_id = ec_get_blog_id( 'studio' );
		if ( null !== $studio_blog_id ) {
			$excluded[] = $studio_blog_id;
		}
	}

	/**
	 * Filter the blog IDs excluded from public network search.
	 *
	 * @param int[] $excluded Excluded blog IDs.
	 */
	return apply_filters( 'extrachill_search_excluded_blog_ids', $excluded );
}

/**
 * Retrieve hardcoded multisite map keyed by blog ID.
 *
 * Mirrors the documented network architecture to avoid runtime discovery.
 *
 * @return array<int, array{name:string,url:string}>
 */
function extrachill_get_network_site_map() {
	static $site_map = null;

	if ( $site_map !== null ) {
		return $site_map;
	}

	// Use canonical source from extrachill-multisite plugin
	if ( ! function_exists( 'ec_get_domain_map' ) ) {
		return array();
	}

	$domain_map        = ec_get_domain_map();
	$excluded_blog_ids = extrachill_get_network_search_excluded_blog_ids();
	$site_map          = array();

	foreach ( $domain_map as $domain => $blog_id ) {
		// Skip duplicate mappings (extrachill.link, www.extrachill.link)
		if ( isset( $site_map[ $blog_id ] ) ) {
			continue;
		}

		// Skip sites deliberately excluded from public search (e.g. Studio).
		if ( in_array( (int) $blog_id, $excluded_blog_ids, true ) ) {
			continue;
		}

		// Verify blog exists before adding to map
		$blog_details = get_blog_details( $blog_id );
		if ( ! $blog_details ) {
			continue;
		}

		$site_map[ $blog_id ] = array(
			'name' => $blog_details->blogname,
			'url'  => $domain,
		);
	}

	$site_map = apply_filters( 'extrachill_search_site_map', $site_map );

	return $site_map;
}

function extrachill_get_network_sites() {
	static $sites_cache = null;

	if ( $sites_cache !== null ) {
		return $sites_cache;
	}

	if ( ! is_multisite() ) {
		return array();
	}

	$site_map = extrachill_get_network_site_map();
	$sites = array();

	foreach ( $site_map as $blog_id => $site ) {
		$sites[] = array(
			'id'   => (int) $blog_id,
			'name' => $site['name'],
			'url'  => $site['url'],
		);
	}

	$sites_cache = $sites;
	return $sites_cache;
}

function extrachill_resolve_site_urls( $site_urls ) {
	if ( ! is_array( $site_urls ) || empty( $site_urls ) ) {
		return array();
	}

	$site_map   = extrachill_get_network_site_map();
	$host_index = array();

	foreach ( $site_map as $blog_id => $site ) {
		$host_index[ strtolower( $site['url'] ) ] = (int) $blog_id;
	}

	$blog_ids = array();

	foreach ( $site_urls as $url ) {
		if ( is_numeric( $url ) ) {
			$blog_id = (int) $url;
			if ( isset( $site_map[ $blog_id ] ) ) {
				$blog_ids[] = $blog_id;
			}
			continue;
		}

		if ( empty( $url ) ) {
			continue;
		}

		$normalized_url = strtolower( trim( $url ) );

		if ( strpos( $normalized_url, '://' ) === false ) {
			$normalized_url = 'https://' . $normalized_url;
		}

		$host = parse_url( $normalized_url, PHP_URL_HOST );

		if ( ! $host ) {
			$host = strtolower( preg_replace( '#^https?://#', '', trim( $url ) ) );
			$host = preg_replace( '#/.*$#', '', $host );
		}

		$host = trim( $host, " /" );

		if ( isset( $host_index[ $host ] ) ) {
			$blog_ids[] = $host_index[ $host ];
		}
	}

	return array_values( array_unique( $blog_ids, SORT_NUMERIC ) );
}

/**
 * Return post type allowances for each site ID.
 *
 * Centralizes post type mapping for search queries, SEO audits, and fallbacks.
 * Blog ID 6 is unused. Blog ID 8 was stream.extrachill.com (decommissioned April 2026).
 * Studio (blog 12) has no entry here on purpose — see
 * extrachill_get_network_search_excluded_blog_ids() for why it never reaches
 * this map's fallback path in the first place. If a blog is neither excluded
 * nor mapped here, extrachill_search_post_type_map_site_health_test() flags it.
 *
 * @return array<int, array<int, string>>
 */
function extrachill_get_site_post_types() {
	// Derive blog-ID keys from canonical slug map so a blog-ID shift tracks
	// automatically instead of silently mis-routing via literal integers.
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return apply_filters( 'extrachill_search_site_post_types_map', array() );
	}

	$site_post_types = array(
		ec_get_blog_id( 'main' )       => array( 'post', 'page' ),                     // extrachill.com (main blog)
		ec_get_blog_id( 'community' )  => array( 'topic', 'reply', 'forum' ),          // community.extrachill.com (bbPress)
		ec_get_blog_id( 'shop' )       => array( 'product', 'page' ),                  // shop.extrachill.com (WooCommerce)
		ec_get_blog_id( 'artist' )     => array( 'artist_profile', 'topic', 'reply' ), // artist.extrachill.com
		ec_get_blog_id( 'events' )     => array( 'data_machine_events', 'page' ),       // events.extrachill.com (Data Machine)
		ec_get_blog_id( 'newsletter' ) => array( 'newsletter' ),                       // newsletter.extrachill.com
		ec_get_blog_id( 'docs' )       => array( 'ec_doc', 'page' ),                    // docs.extrachill.com
		ec_get_blog_id( 'wire' )       => array( 'festival_wire', 'page' ),             // wire.extrachill.com
	);

	return apply_filters( 'extrachill_search_site_post_types_map', $site_post_types );
}

/**
 * Normalize search term to ASCII for consistent matching
 *
 * Converts typographic quotes/apostrophes/dashes to ASCII equivalents.
 */
function extrachill_normalize_search_term( $term ) {
	$term = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98" ), "'", $term );
	$term = str_replace( array( "\xE2\x80\x9D", "\xE2\x80\x9C" ), '"', $term );
	$term = str_replace( array( "\xE2\x80\x93", "\xE2\x80\x94" ), '-', $term );
	return $term;
}

