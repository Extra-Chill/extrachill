<?php
/**
 * Plugin-level functions: the singleton accessor and the shop homepage renderer.
 *
 * Kept out of extrachill-shop.php so the main file only declares the
 * ExtraChillShop class.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the ExtraChillShop singleton.
 *
 * @return ExtraChillShop
 */
function extrachill_shop() {
	return ExtraChillShop::instance();
}

/**
 * Render homepage content for shop.extrachill.com.
 *
 * Hooked via extrachill_homepage_content action.
 */
function extrachill_shop_render_homepage() {
	include EXTRACHILL_SHOP_PLUGIN_DIR . 'inc/templates/shop-homepage.php';
}
add_action( 'extrachill_homepage_content', 'extrachill_shop_render_homepage', 10 );
