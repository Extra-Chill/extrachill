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

	$domain_map = ec_get_domain_map();
	$site_map = array();

	foreach ( $domain_map as $domain => $blog_id ) {
		// Skip duplicate mappings (extrachill.link, www.extrachill.link)
		if ( isset( $site_map[ $blog_id ] ) ) {
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
 * Blog ID 6 is unused. Blog ID 12 (horoscope) not yet provisioned.
 *
 * @return array<int, array<int, string>>
 */
function extrachill_get_site_post_types() {
	$site_post_types = array(
		1  => array( 'post', 'page' ),                         // extrachill.com (main blog)
		2  => array( 'topic', 'reply', 'forum' ),              // community.extrachill.com (bbPress)
		3  => array( 'product', 'page' ),                      // shop.extrachill.com (WooCommerce)
		4  => array( 'artist_profile', 'topic', 'reply' ),     // artist.extrachill.com
		5  => array( 'page' ),                                 // chat.extrachill.com
		7  => array( 'datamachine_events', 'page' ),           // events.extrachill.com (Data Machine)
		8  => array( 'page' ),                                 // stream.extrachill.com
		9  => array( 'newsletter' ),                           // newsletter.extrachill.com
		10 => array( 'ec_doc', 'page' ),                       // docs.extrachill.com
		11 => array( 'festival_wire', 'page' ),                // wire.extrachill.com
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

