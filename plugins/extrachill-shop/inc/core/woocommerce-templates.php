<?php
/**
 * WooCommerce Template Integration
 *
 * Provides minimal WooCommerce template overrides for single products and template parts.
 *
 * Architecture:
 * - Shop Homepage: Handled by extrachill_homepage_content action (see main plugin file)
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
 * Remove default WooCommerce single product hooks
 *
 * We provide our own gallery and summary layout in content-single-product.php,
 * so we remove the default hooks that output duplicate/conflicting content.
 */
add_action( 'init', 'extrachill_shop_remove_default_product_hooks' );
function extrachill_shop_remove_default_product_hooks() {
	// Remove WooCommerce default result count and ordering on archives (we use filter bar instead)
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
	// Remove default gallery (we have our own)
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );

	// Remove default sale flash (we handle it in our template)
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );

	// Remove default title (we output it directly)
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );

	// Remove default rating (we'll handle if needed)
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );

	// Remove default price (we output it directly)
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );

	// Remove default excerpt (we output it directly)
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );

	// Remove default meta (we output it directly)
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );

	// Remove default sharing (not using)
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );
}

/**
 * Override WooCommerce page templates from plugin
 *
 * @param string $template Current template path
 * @return string Modified template path
 */
function extrachill_shop_woocommerce_template_loader( $template ) {
	// Product taxonomy archives (artist storefronts, product categories, etc.)
	if ( is_product_taxonomy() ) {
		$plugin_template = EXTRACHILL_SHOP_PLUGIN_DIR . 'woocommerce/archive-product.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}
	}

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

/**
 * Override WooCommerce template parts
 *
 * Handles template parts like content-single-product.php and content-product.php
 * which use wc_get_template_part() instead of wc_get_template().
 *
 * @param string $template Template path
 * @param string $slug     Template slug
 * @param string $name     Template name
 * @return string Modified template path if plugin template exists
 */
function extrachill_shop_get_template_part( $template, $slug, $name ) {
	if ( $name ) {
		$plugin_template = EXTRACHILL_SHOP_PLUGIN_DIR . 'woocommerce/' . $slug . '-' . $name . '.php';
	} else {
		$plugin_template = EXTRACHILL_SHOP_PLUGIN_DIR . 'woocommerce/' . $slug . '.php';
	}

	if ( file_exists( $plugin_template ) ) {
		return $plugin_template;
	}

	return $template;
}
add_filter( 'wc_get_template_part', 'extrachill_shop_get_template_part', 10, 3 );


