<?php
/**
 * WooCommerce Secondary Header for ExtraChill Theme
 * 
 * Displays a secondary header with product categories on WooCommerce pages
 * to improve navigation and user experience on the shop.
 * 
 * @package ExtraChill
 * @since 1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add secondary header with product categories to WooCommerce pages
 */
add_action('extrachill_after_header', 'add_secondary_header_with_categories');

function add_secondary_header_with_categories() {
    // Check if WooCommerce is active to prevent errors
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Display only on WooCommerce pages: Shop, Cart, Checkout, or Product pages
    if (is_shop() || is_cart() || is_checkout() || is_product() || is_product_category() || is_product_tag()) {

        // Get all product categories ordered by product count
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'orderby'    => 'count',
            'order'      => 'DESC',
            'hide_empty' => true, // Only show categories with products
        ]);

        // Output the secondary header
        if (!empty($categories) && !is_wp_error($categories)) {
            echo '<div class="woocommerce-secondary-header">';
            echo '<nav class="woocommerce-secondary-header-nav">';
            
            foreach ($categories as $category) {
                // Create a link for each category
                $category_link = get_term_link($category);
                echo '<a href="' . esc_url($category_link) . '">' . esc_html($category->name) . '</a>';
            }
            
            echo '</nav>';
            echo '</div>';
        }
    }
}