<?php
/**
 * Seller refunds for marketplace orders.
 *
 * Orders use Stripe "separate charges and transfers": the platform charges the
 * customer once, then transfers each seller's payout. Refunding one seller's
 * portion therefore takes two steps:
 *
 * 1. Reverse that seller's transfer, so the money comes back from the seller.
 * 2. Refund the customer through WooCommerce (`wc_create_refund` with
 *    `refund_payment`), so the payment gateway issues the Stripe refund and
 *    keeps its own refund bookkeeping consistent with its webhooks.
 *
 * Each completed step is recorded on the order under `_seller_refunds`, keyed
 * by seller key, so a retry after a partial failure resumes instead of
 * reversing or refunding twice.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payout record for one seller on an order.
 *
 * Artists live in `_artist_payouts` keyed by artist ID; venues and promoters in
 * `_seller_payouts` keyed by `type:id`.
 *
 * @param WC_Order $order  Order.
 * @param array    $seller Seller reference.
 * @return array|null
 */
function extrachill_shop_get_order_seller_payout( $order, array $seller ) {
	$key     = extrachill_shop_seller_key( $seller );
	$payouts = $order->get_meta( 'artist' === $seller['type'] ? '_artist_payouts' : '_seller_payouts' );
	$payouts = is_array( $payouts ) ? $payouts : array();

	return isset( $payouts[ $key ] ) && is_array( $payouts[ $key ] ) ? $payouts[ $key ] : null;
}

/**
 * Reverse a seller transfer in Stripe.
 *
 * @param string $transfer_id     Stripe transfer ID.
 * @param int    $amount_cents    Amount to reverse.
 * @param string $idempotency_key Idempotency key.
 * @param array  $metadata        Reversal metadata.
 * @return string|WP_Error Reversal ID.
 */
function extrachill_shop_stripe_reverse_transfer( $transfer_id, $amount_cents, $idempotency_key, array $metadata ) {
	if ( ! function_exists( 'extrachill_shop_stripe_init' ) || ! extrachill_shop_stripe_init() ) {
		return new WP_Error( 'stripe_not_configured', 'Stripe is not configured.', array( 'status' => 500 ) );
	}

	try {
		$reversal = \Stripe\Transfer::createReversal(
			$transfer_id,
			array(
				'amount'   => $amount_cents,
				'metadata' => $metadata,
			),
			array( 'idempotency_key' => $idempotency_key )
		);
	} catch ( \Exception $e ) {
		return new WP_Error( 'transfer_reversal_failed', 'Could not reverse the seller payout: ' . $e->getMessage(), array( 'status' => 502 ) );
	}

	return (string) $reversal->id;
}

/**
 * Refund one seller's portion of an order.
 *
 * Refunds the seller's item total (what the customer paid for that seller's
 * items) and reverses the seller's payout. Shipping and tax lines are not
 * included; an admin can refund those from the order screen.
 *
 * @param WC_Order      $order            Order.
 * @param array         $seller           Seller reference.
 * @param string        $reason           Refund reason shown on the order.
 * @param callable|null $reverse_transfer Transfer reverser, defaults to Stripe.
 * @return array|WP_Error
 */
function extrachill_shop_refund_seller_portion( $order, array $seller, $reason = '', $reverse_transfer = null ) {
	$reverse_transfer = $reverse_transfer ?? 'extrachill_shop_stripe_reverse_transfer';

	$payout = extrachill_shop_get_order_seller_payout( $order, $seller );
	if ( null === $payout ) {
		return new WP_Error( 'invalid_seller', 'This order does not contain products from this seller.', array( 'status' => 400 ) );
	}

	$refund_amount = round( (float) ( $payout['total'] ?? 0 ), 2 );
	$payout_amount = round( (float) ( $payout['artist_payout'] ?? 0 ), 2 );
	if ( $refund_amount <= 0 ) {
		return new WP_Error( 'invalid_refund_amount', 'No refundable amount found for this seller.', array( 'status' => 400 ) );
	}

	$key      = extrachill_shop_seller_key( $seller );
	$order_id = (int) $order->get_id();
	$refunds  = $order->get_meta( '_seller_refunds' );
	$refunds  = is_array( $refunds ) ? $refunds : array();
	$state    = isset( $refunds[ $key ] ) && is_array( $refunds[ $key ] ) ? $refunds[ $key ] : array();

	if ( ! empty( $state['wc_refund_id'] ) ) {
		return new WP_Error( 'already_refunded', 'This seller\'s portion of the order has already been refunded.', array( 'status' => 409 ) );
	}

	$save_state = static function ( array $state ) use ( $order, $key ) {
		$refunds         = $order->get_meta( '_seller_refunds' );
		$refunds         = is_array( $refunds ) ? $refunds : array();
		$refunds[ $key ] = $state;
		$order->update_meta_data( '_seller_refunds', $refunds );
		$order->save();
	};

	$transfers   = $order->get_meta( '_stripe_transfers' );
	$transfer_id = is_array( $transfers ) ? (string) ( $transfers[ $key ]['transfer_id'] ?? '' ) : '';

	// No transfer means the payout never left the platform; there is nothing to reverse.
	if ( '' !== $transfer_id && empty( $state['reversal_id'] ) && $payout_amount > 0 ) {
		$reversal_id = call_user_func(
			$reverse_transfer,
			$transfer_id,
			(int) round( $payout_amount * 100 ),
			'ec-refund-reversal-' . $order_id . '-' . $key,
			array(
				'order_id'         => $order_id,
				'seller_reference' => (string) $seller['type'] . ':' . (int) $seller['id'],
				'platform'         => 'extrachill',
			)
		);
		if ( is_wp_error( $reversal_id ) ) {
			return $reversal_id;
		}
		$state['reversal_id'] = (string) $reversal_id;
		$save_state( $state );
	}

	$wc_refund = wc_create_refund(
		array(
			'order_id'       => $order_id,
			'amount'         => $refund_amount,
			'reason'         => '' !== $reason ? $reason : 'Refund issued by seller.',
			'refund_payment' => true,
		)
	);
	if ( is_wp_error( $wc_refund ) ) {
		$order->add_order_note(
			sprintf(
				/* translators: 1: seller reference, 2: error message */
				__( 'Refund for seller %1$s failed: %2$s. Retrying resumes without reversing the payout twice.', 'extrachill-shop' ),
				$seller['type'] . ':' . (int) $seller['id'],
				$wc_refund->get_error_message()
			)
		);
		return new WP_Error( 'refund_failed', 'Refund failed: ' . $wc_refund->get_error_message(), array( 'status' => 502 ) );
	}

	$state['wc_refund_id'] = (int) $wc_refund->get_id();
	$state['amount']       = $refund_amount;
	$state['refunded_at']  = gmdate( 'c' );
	$save_state( $state );

	return array(
		'success'       => true,
		'order_id'      => $order_id,
		'refund_amount' => $refund_amount,
		'reversed'      => ! empty( $state['reversal_id'] ),
	);
}
