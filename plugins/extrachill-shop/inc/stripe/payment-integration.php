<?php
/**
 * Plugin-owned WooCommerce Stripe Checkout gateway and its blocks payment method.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_payment_gateways', 'extrachill_shop_register_merch_gateway' );
add_action( 'woocommerce_blocks_payment_method_type_registration', 'extrachill_shop_register_merch_blocks_payment_method' );

/**
 * Register the classic gateway.
 *
 * @param array $gateways Gateway class names.
 * @return array
 */
function extrachill_shop_register_merch_gateway( $gateways ) {
	require_once __DIR__ . '/class-merch-gateway.php';
	$gateways[] = 'ExtraChill_Merch_Gateway';
	return $gateways;
}

/**
 * Register the blocks payment method so block checkout can select the gateway.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Payment method registry.
 */
function extrachill_shop_register_merch_blocks_payment_method( $registry ) {
	require_once __DIR__ . '/class-merch-blocks-payment-method.php';
	$registry->register( new ExtraChill_Merch_Blocks_Payment_Method() );
}
