<?php
/**
 * Shop Navigation Hooks
 *
 * Adds shop-specific navigation items to theme hooks.
 *
 * @package ExtraChillShop
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add Shipping & Returns Policy link to footer bottom menu.
 *
 * @param array $items Footer bottom menu items.
 * @return array Modified items with shipping policy link.
 */
function ec_shop_footer_shipping_link( $items ) {
	$items[] = array(
		'url'      => home_url( '/shipping-and-returns/' ),
		'label'    => 'Shipping & Returns Policy',
		'priority' => 30,
	);

	return $items;
}
add_filter( 'extrachill_footer_bottom_menu_items', 'ec_shop_footer_shipping_link' );
