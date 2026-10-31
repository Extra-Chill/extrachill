<?php
/**
 * WooCommerce Payment Integration for Stripe Connect
 *
 * Hooks into payment completion to process transfers to artist connected accounts.
 * Uses Stripe's "Separate Charges and Transfers" pattern for marketplace payments.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_payment_complete', 'extrachill_shop_process_artist_payments', 10, 1 );

/**
 * Process artist transfers after WooCommerce payment completes.
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

	// Get the charge ID from WooCommerce Stripe gateway.
	$charge_id = $order->get_meta( '_stripe_charge_id' );
	if ( ! $charge_id ) {
		$charge_id = $order->get_transaction_id();
	}

	if ( ! $charge_id ) {
		$order->add_order_note(
			__( 'Stripe Connect: Could not process artist transfers - no charge ID found.', 'extrachill-shop' )
		);
		return;
	}

	// Process transfers to artist connected accounts.
	$result = extrachill_shop_process_artist_transfers( $order, $charge_id );

	if ( $result['success'] ) {
		$order->update_meta_data( '_stripe_connect_processed', '1' );
		$order->add_order_note(
			sprintf(
				__( 'Stripe Connect: Artist transfers completed. %d transfer(s) created.', 'extrachill-shop' ),
				count( $result['transfers'] )
			)
		);
		$order->save();
	} else {
		$order->add_order_note(
			sprintf(
				__( 'Stripe Connect: Artist transfer failed - %s', 'extrachill-shop' ),
				$result['error']
			)
		);
	}
}
