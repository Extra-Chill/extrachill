<?php
/**
 * Canonical blog ID map for the Extra Chill multisite network.
 * Single source of truth for hardcoded site IDs and domains.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Blog IDs.
if ( ! defined( 'EC_BLOG_ID_MAIN' ) ) {
	define( 'EC_BLOG_ID_MAIN', 1 );
}
if ( ! defined( 'EC_BLOG_ID_COMMUNITY' ) ) {
	define( 'EC_BLOG_ID_COMMUNITY', 2 );
}
if ( ! defined( 'EC_BLOG_ID_SHOP' ) ) {
	define( 'EC_BLOG_ID_SHOP', 3 );
}
if ( ! defined( 'EC_BLOG_ID_ARTIST' ) ) {
	define( 'EC_BLOG_ID_ARTIST', 4 );
}
if ( ! defined( 'EC_BLOG_ID_EVENTS' ) ) {
	define( 'EC_BLOG_ID_EVENTS', 7 );
}
// Blog ID 8 was stream.extrachill.com (decommissioned April 2026).
if ( ! defined( 'EC_BLOG_ID_NEWSLETTER' ) ) {
	define( 'EC_BLOG_ID_NEWSLETTER', 9 );
}
if ( ! defined( 'EC_BLOG_ID_DOCS' ) ) {
	define( 'EC_BLOG_ID_DOCS', 10 );
}
if ( ! defined( 'EC_BLOG_ID_WIRE' ) ) {
	define( 'EC_BLOG_ID_WIRE', 11 );
}
if ( ! defined( 'EC_BLOG_ID_STUDIO' ) ) {
	define( 'EC_BLOG_ID_STUDIO', 12 );
}
if ( ! defined( 'EC_BLOG_ID_LINK_PAGES' ) ) {
	define( 'EC_BLOG_ID_LINK_PAGES', 13 );
}
if ( ! defined( 'EC_BLOG_ID_AUTH' ) ) {
	define( 'EC_BLOG_ID_AUTH', 14 );
}

// Platform Artist ID (Extra Chill artist profile on artist.extrachill.com).
// Dynamic lookup from network option with production fallback.
if ( ! defined( 'EC_PLATFORM_ARTIST_ID' ) ) {
	$ec_platform_artist_id = get_site_option( 'ec_platform_artist_id', 12114 );
	define( 'EC_PLATFORM_ARTIST_ID', (int) $ec_platform_artist_id );
}

/**
 * Return associative map of blog IDs keyed by logical slug.
 *
 * @return array
 */
function ec_get_blog_ids() {
	return array(
		'main'       => EC_BLOG_ID_MAIN,
		'community'  => EC_BLOG_ID_COMMUNITY,
		'shop'       => EC_BLOG_ID_SHOP,
		'artist'     => EC_BLOG_ID_ARTIST,
		'events'     => EC_BLOG_ID_EVENTS,
		'newsletter' => EC_BLOG_ID_NEWSLETTER,
		'docs'       => EC_BLOG_ID_DOCS,
		'wire'       => EC_BLOG_ID_WIRE,
		'studio'     => EC_BLOG_ID_STUDIO,
		'link_pages' => EC_BLOG_ID_LINK_PAGES,
		'auth'       => EC_BLOG_ID_AUTH,
	);
}

/**
 * Get a blog ID by logical key.
 *
 * @param string $key Logical site key, e.g. 'artist'.
 * @return int|null Blog ID or null if unknown.
 */
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $key ) {
		$map = ec_get_blog_ids();

		return isset( $map[ $key ] ) ? (int) $map[ $key ] : null;
	}
}

/**
 * Map of domains to blog IDs for routing.
 * Includes the dedicated Link Pages site at extrachill.link.
 *
 * @return array
 */
function ec_get_domain_map() {
	return array(
		'extrachill.com'            => EC_BLOG_ID_MAIN,
		'community.extrachill.com'  => EC_BLOG_ID_COMMUNITY,
		'shop.extrachill.com'       => EC_BLOG_ID_SHOP,
		'artist.extrachill.com'     => EC_BLOG_ID_ARTIST,
		'events.extrachill.com'     => EC_BLOG_ID_EVENTS,
		'newsletter.extrachill.com' => EC_BLOG_ID_NEWSLETTER,
		'docs.extrachill.com'       => EC_BLOG_ID_DOCS,
		'wire.extrachill.com'       => EC_BLOG_ID_WIRE,
		'studio.extrachill.com'     => EC_BLOG_ID_STUDIO,
		'extrachill.link'           => EC_BLOG_ID_LINK_PAGES,
		'www.extrachill.link'       => EC_BLOG_ID_LINK_PAGES,
		'auth.extrachill.com'       => EC_BLOG_ID_AUTH,
	);
}

/**
 * Network option that gates the extrachill.link Link Pages site cutover.
 *
 * The gate defaults to off, so merging and deploying this plugin never cuts
 * over by itself. Flipping it is an explicit operator action (Phase D of the
 * Link Pages migration epic).
 */
if ( ! defined( 'EC_LINK_PAGES_SITE_CUTOVER_OPTION' ) ) {
	define( 'EC_LINK_PAGES_SITE_CUTOVER_OPTION', 'ec_link_pages_site_cutover' );
}

/**
 * Whether the extrachill.link Link Pages site cutover is enabled.
 *
 * Gate on: sunrise maps extrachill.link to the dedicated Link Pages site
 * (blog 13), www.extrachill.link canonicalizes to the apex, and the Link
 * Pages storage blog resolves to 13. Gate off (default): the pre-cutover
 * behavior is preserved — extrachill.link maps to the artist site (blog 4)
 * and Link Page storage stays on blog 4.
 *
 * Flip:   wp site option update ec_link_pages_site_cutover 1
 * Revert: wp site option update ec_link_pages_site_cutover 0
 *
 * @return bool
 */
function ec_link_pages_site_cutover_enabled() {
	return (bool) get_site_option( EC_LINK_PAGES_SITE_CUTOVER_OPTION, false );
}

/**
 * Configure the canonical Link Pages storage site for the standalone runtime.
 *
 * Both branch constants are overridable from wp-config (see the defined()
 * guards above), so a non-positive value is a real runtime possibility even
 * though the in-repo defaults fold to constants for static analysis.
 *
 * @param int $blog_id Existing configured blog ID.
 * @return int
 */
function ec_filter_link_page_storage_blog_id( $blog_id ) {
	if ( ec_link_pages_site_cutover_enabled() ) {
		$configured = (int) EC_BLOG_ID_LINK_PAGES;

		// @phpstan-ignore greater.alwaysTrue (configurable constant; default folds to 13)
		return $configured > 0 ? $configured : absint( $blog_id );
	}

	// Cutover gated off: Link Pages keep living on the historical artist site.
	$legacy = (int) EC_BLOG_ID_ARTIST;

	// @phpstan-ignore greater.alwaysTrue (configurable constant; default folds to 4)
	return $legacy > 0 ? $legacy : absint( $blog_id );
}
add_filter( 'ec_link_page_storage_blog_id', 'ec_filter_link_page_storage_blog_id' );

/**
 * Reverse lookup: get logical slug by blog ID.
 *
 * @param int $blog_id Blog ID to resolve.
 * @return string|null Slug or null if unknown.
 */
function ec_get_blog_slug_by_id( $blog_id ) {
	foreach ( ec_get_blog_ids() as $slug => $id ) {
		if ( (int) $blog_id === (int) $id ) {
			return $slug;
		}
	}

	return null;
}

/**
 * Get a site URL by logical key.
 *
 * @param string $key Logical site key, e.g. 'main'.
 * @return string|null Site URL or null if unknown.
 */
function ec_get_site_url( $key ) {
	$domain_map = ec_get_domain_map();
	$blog_id    = ec_get_blog_id( $key );

	if ( null === $blog_id ) {
		return null;
	}

	// Allow override via filter (for dev environments, etc.)
	$override_url = apply_filters( 'ec_site_url_override', null, $key, $blog_id );
	if ( $override_url ) {
		return $override_url;
	}

	// Default: return production domains
	foreach ( $domain_map as $domain => $id ) {
		if ( (int) $id === (int) $blog_id ) {
			return 'https://' . $domain;
		}
	}

	return null;
}

/**
 * Get all network domains as allowed redirect hosts.
 *
 * Derives hosts from ec_get_domain_map() for single source of truth.
 *
 * @return array List of allowed host domains.
 */
function ec_get_allowed_redirect_hosts() {
	$domain_map = ec_get_domain_map();
	$hosts      = array_keys( $domain_map );

	return apply_filters( 'ec_allowed_redirect_hosts', $hosts );
}

/**
 * Get all active site IDs on the network dynamically.
 *
 * Unlike ec_get_blog_ids() which returns a hardcoded map of known sites,
 * this queries WordPress for ALL active (non-archived, non-deleted, non-spam)
 * sites. Use this for operations that should apply to every site on the network,
 * such as creating required pages or running maintenance tasks.
 *
 * @return int[] Array of blog IDs.
 */
function ec_get_all_site_ids() {
	return get_sites(
		array(
			'fields'   => 'ids',
			'number'   => 0,
			'archived' => 0,
			'deleted'  => 0,
			'spam'     => 0,
		)
	);
}

/**
 * Register network domains as allowed redirect targets.
 *
 * Enables wp_safe_redirect() to work across all network subdomains.
 *
 * @param array $hosts Existing allowed hosts.
 * @return array Modified allowed hosts including network domains.
 */
function ec_filter_allowed_redirect_hosts( $hosts ) {
	return array_unique( array_merge( $hosts, ec_get_allowed_redirect_hosts() ) );
}
add_filter( 'allowed_redirect_hosts', 'ec_filter_allowed_redirect_hosts' );
