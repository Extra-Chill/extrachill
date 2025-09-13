<?php
/**
 * WooCommerce Breadcrumb Integration for ExtraChill Theme
 * 
 * Simple customizations for WooCommerce's native breadcrumb system.
 * Lets WooCommerce handle breadcrumbs naturally with theme-specific tweaks.
 * 
 * @package ExtraChill
 * @since 1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Merch Store to WooCommerce breadcrumb structure
 */
add_filter('woocommerce_get_breadcrumb', 'add_merch_store_to_breadcrumb', 10, 2);
function add_merch_store_to_breadcrumb($crumbs, $breadcrumb) {
    // Construct the Merch Store breadcrumb
    $shop_crumb = ['Merch Store', home_url('/shop')];

    // Insert "Merch Store" directly after "Home" for relevant WooCommerce pages
    if (is_product_category() || is_product_tag() || is_product() || is_cart() || is_checkout()) {
        array_splice($crumbs, 1, 0, [$shop_crumb]);
    }

    return $crumbs;
}

/**
 * Customize WooCommerce breadcrumb delimiter to match theme
 */
add_filter( 'woocommerce_breadcrumb_defaults', 'wps_breadcrumb_delimiter' );
function wps_breadcrumb_delimiter( $defaults ) {
  $defaults['delimiter'] = ' › ';
  return $defaults;
}