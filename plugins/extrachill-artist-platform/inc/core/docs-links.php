<?php
/**
 * Links from Artist Platform screens to their Extra Chill docs pages.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of an Artist Platform doc on the docs site.
 *
 * @param string $slug Doc page slug under the Artist Platform section.
 * @return string
 */
function extrachill_artist_platform_docs_url( string $slug ): string {
	$base = ec_get_site_url( 'docs' );
	if ( ! is_string( $base ) || '' === $base ) {
		$base = 'https://docs.extrachill.com';
	}

	return trailingslashit( $base ) . 'artist-platform/' . trim( sanitize_title( $slug ), '/' ) . '/';
}
