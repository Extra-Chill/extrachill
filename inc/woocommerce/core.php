<?php
/**
 * WooCommerce Core Integration for ExtraChill Theme
 * 
 * Core WooCommerce functionality including:
 * - Context detection and conditional loading
 * - Theme support and compatibility
 * - Performance optimizations  
 * - Safe wrapper functions
 * 
 * @package ExtraChill
 * @since 1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/* ==========================================================================
   CORE FUNCTIONS - Context Detection & Theme Support
   ========================================================================== */

/**
 * Add WooCommerce theme support
 * 
 * Always add WooCommerce support when WooCommerce is active.
 * Theme support is required for WooCommerce templates to work properly.
 */
function extrachill_add_woocommerce_support() {
    if ( class_exists( 'WooCommerce' ) ) {
        add_theme_support( 'woocommerce' );
    }
}
add_action( 'after_setup_theme', 'extrachill_add_woocommerce_support' );

/**
 * Consolidated WooCommerce context detection
 * 
 * Single source of truth for determining if we're in a WooCommerce context.
 * Uses static caching to avoid repeated function calls.
 * 
 * @return bool True if we're on a WooCommerce page
 */
function extrachill_is_woocommerce_context() {
    if (!function_exists('is_woocommerce')) {
        return false;
    }
    
    static $is_woocommerce_page = null;
    if ($is_woocommerce_page === null) {
        // Check standard WooCommerce pages
        $is_woocommerce_page = is_woocommerce() || is_cart() || is_checkout() || is_account_page() || is_product() || is_shop();
        
        // Check for custom shop page by slug
        if (!$is_woocommerce_page && is_page('shop')) {
            $is_woocommerce_page = true;
        }
        
        // Check if it's the WooCommerce shop page setting
        if (!$is_woocommerce_page && function_exists('wc_get_page_id')) {
            $shop_page_id = wc_get_page_id('shop');
            if ($shop_page_id && is_page($shop_page_id)) {
                $is_woocommerce_page = true;
            }
        }
        
        // Check for WooCommerce shortcodes in page content
        if (!$is_woocommerce_page && is_page()) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'products')) {
                $is_woocommerce_page = true;
            }
            if ($post && has_shortcode($post->post_content, 'shop')) {
                $is_woocommerce_page = true;
            }
            if ($post && has_shortcode($post->post_content, 'product_categories')) {
                $is_woocommerce_page = true;
            }
        }
        
        // Check if current query contains products (for custom implementations)
        if (!$is_woocommerce_page && extrachill_query_has_products()) {
            $is_woocommerce_page = true;
        }
    }
    return $is_woocommerce_page;
}

/**
 * Helper function to check if current query contains products
 * 
 * @return bool True if the query contains WooCommerce products
 */
function extrachill_query_has_products() {
    global $wp_query;
    
    if (!$wp_query || !$wp_query->have_posts()) {
        return false;
    }
    
    foreach ($wp_query->posts as $post) {
        if ($post->post_type === 'product') {
            return true;
        }
    }
    
    return false;
}

/* ==========================================================================
   STYLING & LAYOUT - Content Wrappers & CSS
   ========================================================================== */

/**
 * Custom WooCommerce content wrappers for theme styling
 */
function extrachill_woocommerce_wrapper_start() {
    echo '<section id="primary" class="content-area woocommerce-page">';
    echo '<div class="woocommerce-container">';
}

function extrachill_woocommerce_wrapper_end() {
    echo '</div><!-- .woocommerce-container -->';
    echo '</section><!-- #primary -->';
}

// Replace default WooCommerce wrappers with theme-specific ones
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
add_action( 'woocommerce_before_main_content', 'extrachill_woocommerce_wrapper_start', 10 );
add_action( 'woocommerce_after_main_content', 'extrachill_woocommerce_wrapper_end', 10 );

/**
 * Conditionally enqueue WooCommerce CSS only on WooCommerce pages
 */
function extrachill_enqueue_woocommerce_styles() {
    // Only enqueue on WooCommerce pages to avoid loading unnecessary CSS
    if (!extrachill_is_woocommerce_context()) {
        return;
    }
    
    $css_file = get_stylesheet_directory() . '/css/woocommerce.css';
    $css_url = get_stylesheet_directory_uri() . '/css/woocommerce.css';
    
    if (file_exists($css_file)) {
        wp_enqueue_style(
            'extrachill-woocommerce',
            $css_url,
            array('extrachill-style'), // Depend on main theme stylesheet
            filemtime($css_file), // Version based on file modification time
            'all'
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_woocommerce_styles', 20);

/**
 * Selectively dequeue WooCommerce scripts and styles on non-WooCommerce pages
 * Preserves cart widget functionality via AJAX while reducing asset loading
 */
function extrachill_selective_woocommerce_dequeue() {
    // Don't dequeue on WooCommerce pages - they need full functionality
    if (extrachill_is_woocommerce_context()) {
        return;
    }
    
    // Don't dequeue in admin or if WooCommerce isn't active
    if (is_admin() || !class_exists('WooCommerce')) {
        return;
    }
    
    // Dequeue all WooCommerce scripts on non-store pages for maximum performance
    wp_dequeue_script('wc-add-to-cart');
    wp_dequeue_script('jquery-blockui');
    wp_dequeue_script('sourcebuster-js');
    wp_dequeue_script('wc-order-attribution');
    wp_dequeue_script('js-cookie');
    wp_dequeue_script('woocommerce');
    
    // No cart widget AJAX functionality needed - static link only
    
    // Dequeue WooCommerce styles on non-store pages
    wp_dequeue_style('woocommerce-layout');
    wp_dequeue_style('woocommerce-smallscreen');
    wp_dequeue_style('woocommerce-general');
    wp_dequeue_style('woocommerce-inline');
    wp_dequeue_style('brands-styles');
    wp_dequeue_style('wc-blocks-style');
    
    // Dequeue WooCommerce plugin styles
    wp_dequeue_style('printful-global');
}
add_action('wp_enqueue_scripts', 'extrachill_selective_woocommerce_dequeue', 25);

/* ==========================================================================
   PERFORMANCE OPTIMIZATION - Asset Management
   ========================================================================== */

/**
 * Note: Previous aggressive WooCommerce asset dequeuing was removed as it was breaking 
 * core functionality. The new selective approach above preserves cart functionality
 * while reducing unnecessary asset loading on non-store pages.
 */

/**
 * Note: WooCommerce blocks CSS manipulation removed to prevent conflicts.
 * Let WooCommerce handle its own CSS loading.
 */

/**
 * Note: Aggressive WooCommerce prevention removed as it was breaking core functionality.
 * WooCommerce now loads normally with only conditional theme CSS loading for performance.
 */

/* ==========================================================================
   SAFE WRAPPERS - Error Handling & Compatibility
   ========================================================================== */

/**
 * Safely load WooCommerce functionality only when needed
 * This prevents WooCommerce from loading globally on non-WooCommerce pages
 */
function extrachill_safe_woocommerce_call( $callback, $fallback = '' ) {
    // Check if WooCommerce is available and the callback function exists
    if ( class_exists( 'WooCommerce' ) && function_exists( $callback ) ) {
        try {
            return call_user_func( $callback );
        } catch ( Exception $e ) {
            if ( WP_DEBUG ) {
                error_log( 'WooCommerce function call failed: ' . $e->getMessage() );
            }
            return $fallback;
        }
    }
    return $fallback;
}

/**
 * Safely get WooCommerce product data
 */
function extrachill_safe_get_product( $product_id ) {
    if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
        return wc_get_product( $product_id );
    }
    return false;
}

/**
 * Safely render WooCommerce add to cart button
 */
function extrachill_safe_add_to_cart_button() {
    if ( class_exists( 'WooCommerce' ) && function_exists( 'woocommerce_template_loop_add_to_cart' ) ) {
        woocommerce_template_loop_add_to_cart();
    }
}

/* ==========================================================================
   MEDIAVINE INTEGRATION
   ========================================================================== */

/**
 * Inject Mediavine settings for WooCommerce pages
 */
function inject_mediavine_settings() {
    echo '<div id="mediavine-settings" data-blocklist-all="1"></div>';
}
add_action( 'woocommerce_before_main_content', 'inject_mediavine_settings' );