<?php
/**
 * WooCommerce Button Class Filters
 *
 * Adds theme button classes to WooCommerce buttons using native filters.
 * This ensures all WooCommerce buttons use the theme's design system.
 *
 * @package ExtraChillShop
 * @since 0.2.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add theme button classes to product loop add-to-cart buttons.
 *
 * @param array      $args    Button arguments.
 * @param WC_Product $product Product object.
 * @return array Modified arguments.
 */
add_filter(
	'woocommerce_loop_add_to_cart_args',
	function ( $args, $product ) {
		$args['class'] .= ' button-1 button-medium';
		return $args;
	},
	10,
	2
);

/**
 * Add theme button classes to checkout Place Order button.
 *
 * @param string $html Button HTML.
 * @return string Modified HTML.
 */
add_filter(
	'woocommerce_order_button_html',
	function ( $html ) {
		return str_replace( 'class="button alt', 'class="button alt button-1 button-medium', $html );
	}
);
