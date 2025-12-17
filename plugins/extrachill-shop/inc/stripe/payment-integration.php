<?php
/**
 * WooCommerce Payment Integration for Stripe Connect
 *
 * Hooks into payment completion to process destination charges for artist products.
 * Uses Stripe Connect to split payments between platform and artists automatically.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_payment_complete', 'extrachill_shop_process_artist_payments', 10, 1 );

/**
 * Process artist payments after WooCommerce payment completes.
 *
 * @param int $order_id WooCommerce order ID.
 */
function extrachill_shop_process_artist_payments( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	// Skip if order only contains platform products (no artists).
	if ( extrachill_shop_order_is_platform_only( $order ) ) {
		return;
	}

	// Skip if already processed.
	if ( $order->get_meta( '_stripe_connect_processed' ) ) {
		return;
	}

	// Get the payment method ID from the order.
	// WooCommerce Stripe gateway stores this in various meta keys.
	$payment_method_id = $order->get_meta( '_stripe_source_id' );
	if ( ! $payment_method_id ) {
		$payment_method_id = $order->get_meta( '_stripe_payment_method' );
	}
	if ( ! $payment_method_id ) {
		$payment_method_id = $order->get_meta( '_stripe_card_id' );
	}

	if ( ! $payment_method_id ) {
		$order->add_order_note(
			__( 'Stripe Connect: Could not process artist payments - no payment method found.', 'extrachill-shop' )
		);
		return;
	}

	// Process destination charges.
	$result = extrachill_shop_process_destination_charges( $order, $payment_method_id );

	if ( $result['success'] ) {
		$order->update_meta_data( '_stripe_connect_processed', '1' );
		$order->add_order_note(
			sprintf(
				__( 'Stripe Connect: Artist payments processed successfully. %d charge(s) created.', 'extrachill-shop' ),
				count( $result['charges'] )
			)
		);
		$order->save();
	} else {
		$order->add_order_note(
			sprintf(
				__( 'Stripe Connect: Artist payment processing failed - %s', 'extrachill-shop' ),
				$result['error']
			)
		);
	}
}
