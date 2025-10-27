<?php
/**
 * Theme Breadcrumb Integration for ExtraChill Shop
 *
 * Integrates with theme's unified breadcrumb system via extrachill_breadcrumbs_override_trail filter.
 * Provides consistent breadcrumb structure: Extra Chill › Merch Store › [context]
 *
 * @package ExtraChillShop
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Override theme breadcrumb trail for WooCommerce pages
 *
 * Integrates with theme's unified breadcrumb system to provide consistent
 * breadcrumb structure across all WooCommerce pages.
 *
 * @param string $trail Existing breadcrumb trail (empty if not overridden).
 * @return string Breadcrumb trail HTML for WooCommerce pages.
 */
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_shop_breadcrumb_trail', 10, 1 );
function extrachill_shop_breadcrumb_trail( $trail ) {
    // Only override on WooCommerce pages
    if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_account_page() && ! is_shop() ) {
        return $trail;
    }

    $breadcrumbs = array();

    // Always add Merch Store link after "Extra Chill" root
    $breadcrumbs[] = '<a href="' . esc_url( home_url( '/shop' ) ) . '">Merch Store</a>';

    // Add contextual breadcrumbs based on page type
    if ( is_product_category() || is_product_tag() ) {
        $term = get_queried_object();

        // Add parent categories if hierarchical
        if ( is_product_category() && $term->parent ) {
            $parents = get_ancestors( $term->term_id, 'product_cat' );
            $parents = array_reverse( $parents );
            foreach ( $parents as $parent_id ) {
                $parent = get_term( $parent_id, 'product_cat' );
                if ( $parent && ! is_wp_error( $parent ) ) {
                    $breadcrumbs[] = '<a href="' . esc_url( get_term_link( $parent ) ) . '">' . esc_html( $parent->name ) . '</a>';
                }
            }
        }

        $breadcrumbs[] = '<span>' . esc_html( $term->name ) . '</span>';
    } elseif ( is_product() ) {
        // Product page - show category if exists
        global $post;
        $categories = get_the_terms( $post->ID, 'product_cat' );
        if ( $categories && ! is_wp_error( $categories ) ) {
            $category = reset( $categories );
            $breadcrumbs[] = '<a href="' . esc_url( get_term_link( $category ) ) . '">' . esc_html( $category->name ) . '</a>';
        }
        $breadcrumbs[] = '<span>' . esc_html( get_the_title() ) . '</span>';
    } elseif ( is_cart() ) {
        $breadcrumbs[] = '<span>Cart</span>';
    } elseif ( is_checkout() ) {
        $breadcrumbs[] = '<span>Checkout</span>';
    } elseif ( is_account_page() ) {
        $breadcrumbs[] = '<span>My Account</span>';
    } elseif ( is_shop() ) {
        $breadcrumbs[] = '<span>Shop</span>';
    }

    // Join with delimiter matching theme's style
    return implode( ' › ', $breadcrumbs );
}
