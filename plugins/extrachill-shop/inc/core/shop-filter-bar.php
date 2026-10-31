<?php
/**
 * Shop Filter Bar Items
 *
 * Hooks into theme's universal filter bar to provide shop-specific
 * artist filter and sort options with price sorting.
 *
 * @package ExtraChillShop
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'extrachill_filter_bar_items', 'extrachill_shop_filter_bar_items' );

/**
 * Display filter bar on product taxonomy archives.
 *
 * Hooked to woocommerce_before_shop_loop at priority 5 (before WooCommerce defaults).
 */
function extrachill_shop_display_filter_bar() {
	if ( ! is_product_taxonomy() ) {
		return;
	}

	if ( function_exists( 'extrachill_filter_bar' ) ) {
		extrachill_filter_bar();
	}
}
add_action( 'woocommerce_before_shop_loop', 'extrachill_shop_display_filter_bar', 5 );

/**
 * Register shop filter bar items.
 *
 * @param array $items Existing items.
 * @return array Modified items.
 */
function extrachill_shop_filter_bar_items( $items ) {
	if ( ! is_shop() && ! is_product_taxonomy() && ! is_front_page() ) {
		return $items;
	}

	$current_artist = isset( $_GET['artist'] ) ? absint( wp_unslash( $_GET['artist'] ) ) : 0;
	$current_sort   = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'recent';

	$artist_item = extrachill_shop_build_artist_dropdown( $current_artist );
	if ( $artist_item ) {
		$items[] = $artist_item;
	}

	// Sort dropdown with price options.
	$items[] = array(
		'type'    => 'dropdown',
		'id'      => 'filter-bar-sort',
		'name'    => 'sort',
		'options' => array(
			'recent'     => __( 'Sort by Recent', 'extrachill-shop' ),
			'oldest'     => __( 'Sort by Oldest', 'extrachill-shop' ),
			'price-asc'  => __( 'Price: Low to High', 'extrachill-shop' ),
			'price-desc' => __( 'Price: High to Low', 'extrachill-shop' ),
			'random'     => __( 'Sort by Random', 'extrachill-shop' ),
			'popular'    => __( 'Sort by Popular', 'extrachill-shop' ),
		),
		'current' => $current_sort,
	);

	// Search input.
	$items[] = array(
		'type'        => 'search',
		'id'          => 'filter-bar-search',
		'name'        => 's',
		'placeholder' => __( 'Search...', 'extrachill-shop' ),
		'current'     => get_search_query(),
	);

	return $items;
}

/**
 * Build artist filter dropdown for shop.
 *
 * @param int $current_artist Current canonical artist ID.
 * @return array|null Dropdown item or null.
 */
function extrachill_shop_build_artist_dropdown( $current_artist ) {
	$artist_ids = extrachill_shop_get_artists_with_products();
	if ( empty( $artist_ids ) ) {
		return null;
	}

	$artist_options = array();
	foreach ( $artist_ids as $artist_id ) {
		$artist = extrachill_shop_get_canonical_artist( $artist_id );
		if ( ! is_wp_error( $artist ) ) {
			$artist_options[ (string) $artist_id ] = (string) $artist['name'];
		}
	}
	asort( $artist_options, SORT_NATURAL | SORT_FLAG_CASE );
	$options = array( '' => __( 'All Artists', 'extrachill-shop' ) ) + $artist_options;

	return array(
		'type'    => 'dropdown',
		'id'      => 'filter-bar-artist',
		'name'    => 'artist',
		'options' => $options,
		'current' => $current_artist,
	);
}
