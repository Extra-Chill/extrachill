<?php
/**
 * WooCommerce Product Helper Functions for ExtraChill Theme
 * 
 * Safe wrapper functions for WooCommerce product functionality
 * that prevent errors when WooCommerce is not available.
 * 
 * @package ExtraChill
 * @since 1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safely get product price HTML
 * 
 * Returns formatted price HTML for a product or empty string if WooCommerce is not available
 * 
 * @param int $product_id The product ID
 * @return string Formatted price HTML or empty string
 */
function extrachill_get_product_price_html( $product_id ) {
    // Check if WooCommerce is available
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
        return '';
    }
    
    try {
        $product = wc_get_product( $product_id );
        
        if ( ! $product || ! is_object( $product ) ) {
            return '';
        }
        
        return $product->get_price_html();
        
    } catch ( Exception $e ) {
        if ( WP_DEBUG ) {
            error_log( 'Error getting product price HTML: ' . $e->getMessage() );
        }
        return '';
    }
}

/**
 * Safely render add to cart button
 * 
 * Outputs the WooCommerce add to cart button or nothing if WooCommerce is not available
 */
function extrachill_render_add_to_cart_button() {
    // Check if WooCommerce is available
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'woocommerce_template_loop_add_to_cart' ) ) {
        return;
    }
    
    try {
        // Use WooCommerce's built-in loop add to cart function
        woocommerce_template_loop_add_to_cart();
        
    } catch ( Exception $e ) {
        if ( WP_DEBUG ) {
            error_log( 'Error rendering add to cart button: ' . $e->getMessage() );
        }
    }
}

/**
 * Safely get product categories for display
 * 
 * @param int $product_id The product ID
 * @return array Array of product category objects or empty array
 */
function extrachill_get_product_categories( $product_id ) {
    // Check if WooCommerce is available
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wp_get_post_terms' ) ) {
        return array();
    }
    
    try {
        $product_cats = wp_get_post_terms( $product_id, 'product_cat' );
        
        if ( is_wp_error( $product_cats ) ) {
            return array();
        }
        
        return $product_cats;
        
    } catch ( Exception $e ) {
        if ( WP_DEBUG ) {
            error_log( 'Error getting product categories: ' . $e->getMessage() );
        }
        return array();
    }
}

/**
 * Check if current post is a WooCommerce product
 * 
 * @param int|null $post_id Post ID (optional, uses current post if not provided)
 * @return bool True if post is a product, false otherwise
 */
function extrachill_is_product( $post_id = null ) {
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }
    
    return get_post_type( $post_id ) === 'product';
}

/**
 * Get product stock status safely
 * 
 * @param int $product_id The product ID
 * @return string Stock status or empty string
 */
function extrachill_get_product_stock_status( $product_id ) {
    // Check if WooCommerce is available
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
        return '';
    }
    
    try {
        $product = wc_get_product( $product_id );
        
        if ( ! $product || ! is_object( $product ) ) {
            return '';
        }
        
        return $product->get_stock_status();
        
    } catch ( Exception $e ) {
        if ( WP_DEBUG ) {
            error_log( 'Error getting product stock status: ' . $e->getMessage() );
        }
        return '';
    }
}

/**
 * Check if product is in stock
 * 
 * @param int $product_id The product ID
 * @return bool True if in stock, false otherwise
 */
function extrachill_is_product_in_stock( $product_id ) {
    $stock_status = extrachill_get_product_stock_status( $product_id );
    return $stock_status === 'instock';
}

/**
 * Get formatted product rating HTML
 * 
 * @param int $product_id The product ID
 * @return string Formatted rating HTML or empty string
 */
function extrachill_get_product_rating_html( $product_id ) {
    // Check if WooCommerce is available
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
        return '';
    }
    
    try {
        $product = wc_get_product( $product_id );
        
        if ( ! $product || ! is_object( $product ) ) {
            return '';
        }
        
        $rating_count = $product->get_rating_count();
        $average_rating = $product->get_average_rating();
        
        if ( $rating_count > 0 ) {
            return wc_get_rating_html( $average_rating, $rating_count );
        }
        
        return '';
        
    } catch ( Exception $e ) {
        if ( WP_DEBUG ) {
            error_log( 'Error getting product rating HTML: ' . $e->getMessage() );
        }
        return '';
    }
}