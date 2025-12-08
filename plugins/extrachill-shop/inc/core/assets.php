<?php
/**
 * Asset Management
 *
 * Conditional loading for optimal performance:
 * - WooCommerce CSS: All pages (includes cart icon, WooCommerce styling)
 * - Raffle frontend CSS: Product pages with "raffle" tag
 * - Raffle admin CSS/JS: Product edit screen only
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'extrachill_shop_enqueue_assets' );
function extrachill_shop_enqueue_assets() {
    $css_file = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/css/woocommerce.css';

    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'extrachill-shop-woocommerce',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/css/woocommerce.css',
            array(),
            filemtime( $css_file )
        );
    }
}

add_action( 'wp_enqueue_scripts', 'extrachill_shop_enqueue_artist_dashboard_assets' );
function extrachill_shop_enqueue_artist_dashboard_assets() {
    if ( ! is_account_page() ) {
        return;
    }

    if ( ! function_exists( 'extrachill_shop_user_is_artist' ) || ! extrachill_shop_user_is_artist() ) {
        return;
    }

    $css_file = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/css/artist-dashboard.css';

    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'extrachill-shop-artist-dashboard',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/css/artist-dashboard.css',
            array(),
            filemtime( $css_file )
        );
    }
}

add_action( 'wp_enqueue_scripts', 'extrachill_shop_enqueue_raffle_frontend_assets' );
function extrachill_shop_enqueue_raffle_frontend_assets() {
    global $post;

    if ( ! is_product() || ! has_term( 'raffle', 'product_tag', $post->ID ) ) {
        return;
    }

    $css_file = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/css/raffle-frontend.css';

    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'extrachill-shop-raffle-frontend',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/css/raffle-frontend.css',
            array(),
            filemtime( $css_file )
        );
    }
}

add_action( 'admin_enqueue_scripts', 'extrachill_shop_enqueue_raffle_admin_assets' );
function extrachill_shop_enqueue_raffle_admin_assets( $hook ) {
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
        return;
    }

    global $post;
    if ( ! $post || 'product' !== $post->post_type ) {
        return;
    }

    $css_file = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/css/raffle-admin.css';
    $js_file  = EXTRACHILL_SHOP_PLUGIN_DIR . 'assets/js/raffle-admin.js';

    if ( file_exists( $css_file ) ) {
        wp_enqueue_style(
            'extrachill-shop-raffle-admin',
            EXTRACHILL_SHOP_PLUGIN_URL . 'assets/css/raffle-admin.css',
            array(),
            filemtime( $css_file )
        );
    }

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
