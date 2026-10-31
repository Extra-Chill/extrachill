<?php
/**
 * Links from Events screens to their Extra Chill docs pages.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of an Events Calendar doc on the docs site.
 *
 * @param string $slug Doc page slug under the Events Calendar section.
 * @return string
 */
function extrachill_events_docs_url( string $slug ): string {
	$base = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'docs' ) : '';
	if ( ! is_string( $base ) || '' === $base ) {
		$base = 'https://docs.extrachill.com';
	}

	return trailingslashit( $base ) . 'events-calendar/' . trim( sanitize_title( $slug ), '/' ) . '/';
}
