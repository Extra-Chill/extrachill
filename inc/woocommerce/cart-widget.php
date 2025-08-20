<?php
/**
 * WooCommerce Cart Widget - Modular cart functionality
 * 
 * This component handles cart display without triggering WooCommerce queries
 * on non-WooCommerce pages. Uses AJAX for on-demand cart loading.
 * 
 * @package ExtraChill
 * @since 1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display simple static cart icon linking to shop
 * 
 * This function outputs a simple cart icon that links directly to the shop
 * without any AJAX or dynamic functionality to maximize performance.
 */
function extrachill_display_cart_widget() {
    // Only show cart if WooCommerce is available
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Get shop URL - fallback to /shop if no shop page set
    $shop_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/shop');
    
    ?>
    <div class="cart-icon">
        <a href="<?php echo esc_url($shop_url); ?>" class="cart-link" title="Visit Shop">
            <svg class="cart-top">
                <use href="<?php echo get_template_directory_uri(); ?>/fonts/fontawesome.svg?v=1.5#cart-shopping"></use>
            </svg>
        </a>
    </div>
    <?php
}

/**
 * PERFORMANCE OPTIMIZATION: Dynamic cart functionality removed
 * 
 * Previously this file contained complex AJAX cart loading functionality
 * that required WooCommerce scripts and database queries on every page.
 * 
 * The cart widget now uses a simple static link to the shop for maximum
 * performance. Cart functionality is fully available once users visit
 * the actual store pages where it's needed.
 * 
 * Trade-off: Removed dynamic cart count display on non-store pages
 * Benefit: Massive performance improvement for all site visitors
 */