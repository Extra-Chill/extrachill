<?php
/**
 * Asset Management for ExtraChill Shop
 *
 * Handles CSS/JS enqueuing for WooCommerce integration.
 * Assets only load on WooCommerce-related pages for optimal performance.
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue WooCommerce CSS on relevant pages
 *
 * Conditionally loads WooCommerce styling only on WooCommerce pages
 * to minimize asset loading on non-shop pages.
 *
 * @since 1.0.0
 */
add_action( 'wp_enqueue_scripts', 'extrachill_shop_enqueue_assets' );
function extrachill_shop_enqueue_assets() {
    // Only load on WooCommerce pages (including when shop is homepage)
    if ( ! is_front_page() && ! is_shop() && ! is_product() && ! is_product_category() && ! is_product_tag() && ! is_cart() && ! is_checkout() && ! is_account_page() ) {
        return;
    }

    // Path to CSS file
    $css_file = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/css/woocommerce.css';

    // Verify file exists before enqueuing
    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'extrachill-shop-woocommerce',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/css/woocommerce.css',
            array(),
            filemtime( $css_file )
        );
    }
}

/**
 * Enqueue raffle frontend assets
 *
 * Conditionally loads raffle progress bar CSS only on product pages
 * with "raffle" tag.
 *
 * @since 1.0.0
 */
add_action( 'wp_enqueue_scripts', 'extrachill_shop_enqueue_raffle_frontend_assets' );
function extrachill_shop_enqueue_raffle_frontend_assets() {
    global $post;

    // Only load on single product pages with raffle tag
    if ( ! is_product() ) {
        return;
    }

    // Check if product has raffle tag
    if ( ! has_term( 'raffle', 'product_tag', $post->ID ) ) {
        return;
    }

    // Path to CSS file
    $css_file = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/css/raffle-frontend.css';

    // Verify file exists before enqueuing
    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'extrachill-shop-raffle-frontend',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/css/raffle-frontend.css',
            array(),
            filemtime( $css_file )
        );
    }
}

/**
 * Enqueue raffle admin assets
 *
 * Conditionally loads raffle admin field assets only on product edit screen.
 *
 * @since 1.0.0
 */
add_action( 'admin_enqueue_scripts', 'extrachill_shop_enqueue_raffle_admin_assets' );
function extrachill_shop_enqueue_raffle_admin_assets( $hook ) {
    // Only load on product edit screen
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
        return;
    }

    // Check if we're editing a product
    global $post;
    if ( ! $post || 'product' !== $post->post_type ) {
        return;
    }

    // Path to CSS file
    $css_file = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/css/raffle-admin.css';
    $js_file  = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/js/raffle-admin.js';

    // Enqueue CSS
    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'extrachill-shop-raffle-admin',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/css/raffle-admin.css',
            array(),
            filemtime( $css_file )
        );
    }

    // Enqueue JS
    if ( file_exists( $js_file ) ) {
        wp_enqueue_script(
            'extrachill-shop-raffle-admin',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/js/raffle-admin.js',
            array( 'jquery' ),
            filemtime( $js_file ),
            true
        );
    }
}
