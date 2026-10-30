<?php
/**
 * Filter Bar Integration
 *
 * Adds EC-specific filter bar items for music categories.
 * Artist dropdown appears on song-meanings and music-history categories.
 *
 * @package ExtraChill\Network
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add artist dropdown for music-specific categories.
 *
 * @param array $items Existing filter bar items.
 * @return array Modified items.
 */
function extrachill_network_filter_bar_artist_dropdown( $items ) {
	if ( ! is_category( 'song-meanings' ) && ! is_category( 'music-history' ) ) {
		return $items;
	}

	if ( ! function_exists( 'extrachill_build_artist_dropdown' ) ) {
		return $items;
	}

	$artist_item = extrachill_build_artist_dropdown();
	if ( $artist_item ) {
		$items[] = $artist_item;
	}

	return $items;
}
add_filter( 'extrachill_filter_bar_category_items', 'extrachill_network_filter_bar_artist_dropdown' );
