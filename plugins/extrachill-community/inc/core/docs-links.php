<?php
/**
 * Links from Community screens to their Extra Chill docs pages.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of a Community doc on the docs site.
 *
 * @param string $slug Doc page slug under the Community section.
 * @return string
 */
function extrachill_community_docs_url( string $slug ): string {
	$base = ec_get_site_url( 'docs' );
	if ( ! is_string( $base ) || '' === $base ) {
		$base = 'https://docs.extrachill.com';
	}

	return trailingslashit( $base ) . 'community/' . trim( sanitize_title( $slug ), '/' ) . '/';
}
