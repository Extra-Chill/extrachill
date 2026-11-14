<?php
/**
 * Checkout Shipping Integration
 *
 * Custom WooCommerce shipping method: one flat rate per order (a cart holds a
 * single seller), free when every item ships free, US-only.
 *
 * @package ExtraChill\Shop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register custom shipping method.
 */
add_filter( 'woocommerce_shipping_methods', 'extrachill_shop_register_shipping_method' );

function extrachill_shop_register_shipping_method( $methods ) {
	$methods['extrachill_artist_shipping'] = 'ExtraChill_Artist_Shipping_Method';
	return $methods;
}

/**
 * Load shipping method class.
 */
add_action( 'woocommerce_shipping_init', 'extrachill_shop_shipping_method_init' );

function extrachill_shop_shipping_method_init() {
	if ( class_exists( 'ExtraChill_Artist_Shipping_Method' ) ) {
		return;
	}

	require_once __DIR__ . '/class-extrachill-artist-shipping-method.php';
}

/**
 * Check if cart contains only "ships free" products.
 *
 * @return bool True if all cart products have ships_free meta.
 */
function extrachill_shop_cart_ships_free() {
	if ( ! function_exists( 'WC' ) ) {
		return false;
	}
	/** @var WC_Cart|null $cart Null outside a cart-loaded request. */
	$cart = WC()->cart;
	if ( ! $cart ) {
		return false;
	}

	$cart_contents = $cart->get_cart();
	if ( empty( $cart_contents ) ) {
		return false;
	}

	foreach ( $cart_contents as $cart_item ) {
		$product_id = $cart_item['product_id'] ?? 0;
		if ( ! $product_id ) {
			continue;
		}

		if ( '1' !== get_post_meta( $product_id, '_ships_free', true ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Validate shipping address is US-only at checkout.
 */
add_action( 'woocommerce_after_checkout_validation', 'extrachill_shop_validate_us_shipping', 10, 2 );

function extrachill_shop_validate_us_shipping( $data, $errors ) {
	$shipping_country = $data['shipping_country'] ?? $data['billing_country'] ?? '';

	if ( ! empty( $shipping_country ) && 'US' !== $shipping_country ) {
		$errors->add(
			'shipping_country',
			__( 'Sorry, we currently only ship to addresses within the United States.', 'extrachill-shop' )
		);
	}
}

/**
 * Restrict shipping to US only in available countries.
 */
add_filter( 'woocommerce_countries_allowed_countries', 'extrachill_shop_restrict_shipping_countries' );

function extrachill_shop_restrict_shipping_countries( $countries ) {
	return array( 'US' => $countries['US'] ?? 'United States' );
}

/**
 * Restrict shipping zones to US only.
 */
add_filter( 'woocommerce_shipping_countries', 'extrachill_shop_restrict_shipping_countries' );
