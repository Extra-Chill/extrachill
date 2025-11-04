<?php
/**
 * WooCommerce Template Integration
 *
 * Provides minimal WooCommerce template overrides for single products and template parts.
 * Shop homepage uses theme's extrachill_template_homepage filter instead of template_include.
 *
 * Architecture:
 * - Shop Homepage: Handled by extrachill_template_homepage filter (see main plugin file)
 * - Single Products: Handled by template_include filter (this file)
 * - Template Parts: Handled by woocommerce_locate_template filter (this file)
 * - Cart/Checkout: Use WooCommerce defaults with woocommerce_locate_template support
 *
 * @package ExtraChillShop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Override single product template from plugin
 *
 * Shop homepage is handled by extrachill_template_homepage filter,
 * but single products need this template_include filter.
 *
 * @param string $template Current template path
 * @return string Modified template path for single product pages
 */
function extrachill_shop_woocommerce_template_loader( $template ) {
	// Single product pages
	if ( is_singular( 'product' ) ) {
		$plugin_template = EXTRACHILL_SHOP_PLUGIN_DIR . 'woocommerce/single-product.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}
	}

	// Cart page
	if ( is_cart() ) {
		$plugin_template = EXTRACHILL_SHOP_PLUGIN_DIR . 'woocommerce/cart.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}
	}

	// Checkout page
	if ( is_checkout() ) {
		$plugin_template = EXTRACHILL_SHOP_PLUGIN_DIR . 'woocommerce/checkout.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'extrachill_shop_woocommerce_template_loader', 99 );

/**
 * Override WooCommerce template location
 *
 * Tells WooCommerce to look in plugin's /woocommerce/ directory for template files.
 * Handles: content-product.php, content-single-product.php, and other WooCommerce templates.
 *
 * @param string $template      Located template path
 * @param string $template_name Template file name
 * @param string $template_path Template directory path
 * @return string Modified template path if plugin template exists, otherwise original path
 */
function extrachill_shop_locate_woocommerce_template( $template, $template_name, $template_path ) {
	$plugin_template = EXTRACHILL_SHOP_PLUGIN_DIR . 'woocommerce/' . $template_name;

	if ( file_exists( $plugin_template ) ) {
		return $plugin_template;
	}

	return $template;
}
add_filter( 'woocommerce_locate_template', 'extrachill_shop_locate_woocommerce_template', 10, 3 );


