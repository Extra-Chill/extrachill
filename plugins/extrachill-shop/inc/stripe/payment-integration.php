<?php
/** Plugin-owned WooCommerce Stripe Checkout gateway. */
defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_payment_gateways', 'extrachill_shop_register_merch_gateway' );
function extrachill_shop_register_merch_gateway( $gateways ) {
	require_once __DIR__ . '/class-merch-gateway.php';
	$gateways[] = 'ExtraChill_Merch_Gateway';
	return $gateways;
}
