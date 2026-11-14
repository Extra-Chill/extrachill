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

	$current_seller = extrachill_shop_get_requested_storefront_seller();
	$current_sort   = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'recent';

	$seller_item = extrachill_shop_build_seller_dropdown( $current_seller ? extrachill_shop_seller_ref_key( $current_seller ) : '' );
	if ( $seller_item ) {
		$items[] = $seller_item;
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
 * Build the seller filter dropdown: every artist, venue and promoter with
 * published products. Values are `type:id`, the storefront key.
 *
 * @param string $current_seller Current `type:id`, or ''.
 * @return array|null Dropdown item or null.
 */
function extrachill_shop_build_seller_dropdown( string $current_seller ) {
	$options = array();
	foreach ( extrachill_shop_get_sellers_with_products() as $seller ) {
		$public = extrachill_shop_get_public_seller( $seller );
		if ( $public ) {
			$options[ extrachill_shop_seller_ref_key( $seller ) ] = $public['name'];
		}
	}
	if ( empty( $options ) ) {
		return null;
	}
	asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

	return array(
		'type'    => 'dropdown',
		'id'      => 'filter-bar-seller',
		'name'    => 'seller',
		'options' => array( '' => __( 'All Sellers', 'extrachill-shop' ) ) + $options,
		'current' => $current_seller,
	);
}
