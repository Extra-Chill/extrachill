<?php
/**
 * Core Search Functions for ExtraChill Search Plugin
 *
 * Provides network-wide search across all eight sites using domain-based resolution
 * and WordPress native multisite functions with automatic blog-id-cache.
 *
 * @package ExtraChill\Search
 * @since 1.0.0
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

	$site_map = array(
		1 => array(
			'name' => 'Extra Chill',
			'url'  => 'extrachill.com',
		),
		2 => array(
			'name' => 'Extra Chill Community',
			'url'  => 'community.extrachill.com',
		),
		3 => array(
			'name' => 'Extra Chill Shop',
			'url'  => 'shop.extrachill.com',
		),
		4 => array(
			'name' => 'Extra Chill Artist Platform',
			'url'  => 'artist.extrachill.com',
		),
		5 => array(
			'name' => 'Extra Chill Chat',
			'url'  => 'chat.extrachill.com',
		),
		6 => array(
			'name' => 'Extra Chill App Backend',
			'url'  => 'app.extrachill.com',
		),
		7 => array(
			'name' => 'Extra Chill Events',
			'url'  => 'events.extrachill.com',
		),
		8 => array(
			'name' => 'Extra Chill Stream',
			'url'  => 'stream.extrachill.com',
		),
		9 => array(
			'name' => 'Extra Chill Newsletter',
			'url'  => 'newsletter.extrachill.com',
		),
	);

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
 * Centralizes post type mapping for search queries and fallbacks.
 *
 * @return array<int, array<int, string>>
 */
function extrachill_get_site_post_types() {
	$site_post_types = array(
		1 => array( 'post', 'page', 'festival_wire', 'newsletter' ),
		2 => array( 'topic', 'reply', 'forum' ),
		3 => array( 'product', 'page' ),
		4 => array( 'artist_profile', 'topic', 'reply' ),
		5 => array( 'page' ),
		6 => array( 'page' ),
		7 => array( 'dm_events', 'page' ),
		8 => array( 'page' ),
		9 => array( 'newsletter' ),
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

