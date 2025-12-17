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
 * Register shop filter bar items.
 *
 * @param array $items Existing items.
 * @return array Modified items.
 */
function extrachill_shop_filter_bar_items( $items ) {
	if ( ! is_shop() && ! is_product_taxonomy() && ! is_front_page() ) {
		return $items;
	}

	$current_artist = isset( $_GET['artist'] ) ? sanitize_text_field( wp_unslash( $_GET['artist'] ) ) : '';
	$current_sort   = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'recent';

	// Artist dropdown (hidden on artist taxonomy archives).
	if ( ! is_tax( 'artist' ) ) {
		$artist_item = extrachill_shop_build_artist_dropdown( $current_artist );
		if ( $artist_item ) {
			$items[] = $artist_item;
		}
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
 * @param string $current_artist Current artist slug.
 * @return array|null Dropdown item or null.
 */
function extrachill_shop_build_artist_dropdown( $current_artist ) {
	$artists = get_terms(
		array(
			'taxonomy'   => 'artist',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $artists ) || empty( $artists ) ) {
		return null;
	}

	$options = array( '' => __( 'All Artists', 'extrachill-shop' ) );
	foreach ( $artists as $artist ) {
		$options[ $artist->slug ] = $artist->name;
	}

	return array(
		'type'    => 'dropdown',
		'id'      => 'filter-bar-artist',
		'name'    => 'artist',
		'options' => $options,
		'current' => $current_artist,
	);
}
