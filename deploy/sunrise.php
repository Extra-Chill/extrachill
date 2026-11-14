<?php
/**
 * extrachill.link Domain Mapping
 *
 * Canonical source: the Extra-Chill/.github repository. WordPress includes
 * wp-content/sunrise.php when wp-config.php defines SUNRISE; this file is the
 * drop-in template shipped by extrachill-network for the Link Pages site
 * (network issue #174). Deploy by copying it to the canonical source and then
 * to wp-content/sunrise.php on the live install.
 *
 * - www.extrachill.link 301s to the apex.
 * - extrachill.link serves the dedicated Link Pages site (blog 13); the
 *   standalone Link Pages runtime owns routing on that site. If the site is
 *   unavailable, WordPress core resolves the host normally.
 */

// Only run if this is a multisite install
if ( ! defined( 'MULTISITE' ) || ! MULTISITE ) {
	return;
}

if ( ! defined( 'EC_LINK_PAGES_SITE_BLOG_ID' ) ) {
	define( 'EC_LINK_PAGES_SITE_BLOG_ID', 13 );
}

$ec_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against literal hosts; WordPress sanitization helpers are not loaded at sunrise time.

// Canonicalize www to the apex.
if ( 'www.extrachill.link' === $ec_host ) {
	$ec_request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- forwarded verbatim as a URL path; forced below to start with '/' so no external origin can be injected.
	if ( '' === $ec_request_uri || '/' !== $ec_request_uri[0] ) {
		$ec_request_uri = '/';
	}
	header( 'Location: https://extrachill.link' . $ec_request_uri, true, 301 );
	exit;
}

// The apex serves the dedicated Link Pages site.
if ( 'extrachill.link' === $ec_host ) {
	$ec_link_pages_site = get_site( EC_LINK_PAGES_SITE_BLOG_ID );
	if ( $ec_link_pages_site && empty( $ec_link_pages_site->deleted ) && empty( $ec_link_pages_site->archived ) && empty( $ec_link_pages_site->spam ) ) {
		// Force WordPress to use the correct blog ID
		global $blog_id;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current blog before WordPress loads.
		$blog_id = EC_LINK_PAGES_SITE_BLOG_ID;

		// Set the current blog globals
		global $current_site, $current_blog;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current site before WordPress loads.
		$current_site = get_network( 1 );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberate: sunrise domain mapping must set the current blog before WordPress loads.
		$current_blog = $ec_link_pages_site;
	}
}
