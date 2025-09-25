<?php
/**
 * Plugin Helper Functions
 *
 * Core utility functions for Extra Chill Shop plugin
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Check if plugin is properly loaded
 *
 * @return bool
 */
function extrachill_shop_is_loaded() {
    return class_exists( 'ExtraChillShop' ) && class_exists( 'WooCommerce' );
}

/**
 * Get plugin instance
 *
 * @return ExtraChillShop|null
 */
function extrachill_shop_instance() {
    return function_exists( 'extrachill_shop' ) ? extrachill_shop() : null;
}

/**
 * Safely execute WooCommerce function calls
 *
 * @param callable $callback Function to call
 * @param mixed $fallback Fallback value if function fails
 * @return mixed
 */
function extrachill_shop_safe_call( $callback, $fallback = '' ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return $fallback;
    }

    try {
        return is_callable( $callback ) ? call_user_func( $callback ) : $fallback;
    } catch ( Exception $e ) {
        if ( WP_DEBUG ) {
            error_log( 'ExtraChill Shop function call failed: ' . $e->getMessage() );
        }
        return $fallback;
    }
}

/**
 * Check if we're on a WooCommerce page
 *
 * @return bool
 */
function extrachill_shop_is_woocommerce_page() {
    if ( ! function_exists( 'is_woocommerce' ) ) {
        return false;
    }

    return is_woocommerce() || is_cart() || is_checkout() || is_account_page();
}

/**
 * Log messages for debugging (respects WP_DEBUG)
 *
 * @param string $message
 * @param string $level
 */
function extrachill_shop_log( $message, $level = 'info' ) {
    if ( ! WP_DEBUG ) {
        return;
    }

    $prefix = '[ExtraChill Shop] ';
    error_log( $prefix . $message );
}