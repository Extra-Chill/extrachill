<?php
/**
 * Breadcrumb Integration
 *
 * Provides custom breadcrumb trails for WooCommerce pages.
 * Follows the same pattern as community and events plugins.
 *
 * Structure: Extra Chill › Merch Store › [Context]
 * - Root: "Extra Chill › Merch Store" (via extrachill_breadcrumbs_root filter)
 * - Trail: Product/Cart/Checkout context (via extrachill_breadcrumbs_override_trail filter)
 *
 * @package ExtraChillShop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Override breadcrumb root for shop site
 *
 * Sets root breadcrumb to "Extra Chill › Merch Store" on shop.extrachill.com.
 *
 * @param string $root_link Default root link
 * @return string Modified root link
 */
function extrachill_shop_breadcrumb_root( $root_link ) {
	// Only override on shop.extrachill.com (blog ID 3)
	if ( get_current_blog_id() !== 3 ) {
		return $root_link;
	}

	// Root structure: Extra Chill › Merch Store
	return '<a href="https://extrachill.com">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Merch Store</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'extrachill_shop_breadcrumb_root' );

/**
 * Override breadcrumb trail for WooCommerce pages
 *
 * Builds context-specific breadcrumb trail after root.
 * Handles: product categories, products, cart, checkout, account, shop.
 *
 * @param string $trail Default trail
 * @return string Modified trail
 */
function extrachill_shop_breadcrumb_trail( $trail ) {
	// Only override on shop.extrachill.com (blog ID 3)
	if ( get_current_blog_id() !== 3 ) {
		return $trail;
	}

	// Only apply to WooCommerce pages
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return $trail;
	}

	$breadcrumbs = array();

	// Product category pages
	if ( is_product_category() ) {
		$term = get_queried_object();
		if ( $term ) {
			// Add parent categories
			$ancestors = get_ancestors( $term->term_id, 'product_cat' );
			$ancestors = array_reverse( $ancestors );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$breadcrumbs[] = '<a href="' . esc_url( get_term_link( $ancestor ) ) . '">' . esc_html( $ancestor->name ) . '</a>';
				}
			}
			$breadcrumbs[] = esc_html( $term->name );
		}
	}
	// Single product
	elseif ( is_product() ) {
		$product_cats = get_the_terms( get_the_ID(), 'product_cat' );
		if ( $product_cats && ! is_wp_error( $product_cats ) ) {
			$product_cat = array_shift( $product_cats );
			// Add parent categories
			$ancestors = get_ancestors( $product_cat->term_id, 'product_cat' );
			$ancestors = array_reverse( $ancestors );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$breadcrumbs[] = '<a href="' . esc_url( get_term_link( $ancestor ) ) . '">' . esc_html( $ancestor->name ) . '</a>';
				}
			}
			$breadcrumbs[] = '<a href="' . esc_url( get_term_link( $product_cat ) ) . '">' . esc_html( $product_cat->name ) . '</a>';
		}
	}
	// Cart
	elseif ( is_cart() ) {
		$breadcrumbs[] = 'Cart';
	}
	// Checkout
	elseif ( is_checkout() ) {
		$breadcrumbs[] = 'Checkout';
	}
	// My Account
	elseif ( is_account_page() ) {
		$breadcrumbs[] = 'My Account';
	}

	// Return trail as space-separated string
	return ! empty( $breadcrumbs ) ? implode( ' › ', $breadcrumbs ) : $trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_shop_breadcrumb_trail' );

/**
 * Display breadcrumbs on WooCommerce pages
 *
 * Hooked to woocommerce_before_main_content at priority 5 (before WooCommerce default at 20).
 */
function extrachill_shop_display_breadcrumbs() {
	extrachill_breadcrumbs();
}
add_action( 'woocommerce_before_main_content', 'extrachill_shop_display_breadcrumbs', 5 );

/**
 * Remove WooCommerce default breadcrumbs
 *
 * We're using custom breadcrumbs instead via extrachill_breadcrumbs().
 */
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );

/**
 * Display breadcrumbs on all WooCommerce pages via theme hook
 *
 * Fallback for pages (cart, checkout) that might not call woocommerce_before_main_content hook.
 * Uses theme's extrachill_before_body_content hook at priority 5.
 */
function extrachill_shop_display_breadcrumbs_fallback() {
	// Only on shop.extrachill.com (blog ID 3)
	if ( get_current_blog_id() !== 3 ) {
		return;
	}

	// Only on WooCommerce pages
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return;
	}

	if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
		extrachill_breadcrumbs();
	}
}
add_action( 'extrachill_before_body_content', 'extrachill_shop_display_breadcrumbs_fallback', 5 );

/**
 * Override back-to-home link label for shop pages
 *
 * Changes "Back to Extra Chill" to "Back to Merch Store" on shop pages.
 * Uses theme's extrachill_back_to_home_label filter.
 * Only applies on blog ID 3 (shop.extrachill.com).
 *
 * @param string $label Default back-to-home link label
 * @param string $url   Back-to-home link URL
 * @return string Modified label
 */
function extrachill_shop_back_to_home_label( $label, $url ) {
	// Only apply on shop.extrachill.com (blog ID 3)
	if ( get_current_blog_id() !== 3 ) {
		return $label;
	}

	// Don't override on homepage (homepage should say "Back to Extra Chill")
	if ( is_front_page() ) {
		return $label;
	}

	return '← Back to Merch Store';
}
add_filter( 'extrachill_back_to_home_label', 'extrachill_shop_back_to_home_label', 10, 2 );
