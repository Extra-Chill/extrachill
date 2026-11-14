<?php
/**
 * Merch order refunds.
 *
 * Merch orders are Stripe direct charges. A seller order is charged on the
 * seller's connected account (stored on the order as
 * `_extrachill_stripe_account`) with an application fee for the platform.
 * A platform-owned product order is charged on the platform account and has
 * no connected account. A refund therefore takes a single Stripe call: refund
 * the PaymentIntent on the account that holds the charge, and return the
 * platform's application fee proportionally (`refund_application_fee`).
 *
 * All refunds go through WooCommerce (`wc_create_refund` with
 * `refund_payment`). WooCommerce validates the amount, saves the refund row,
 * then calls the gateway's `process_refund()`, which issues the Stripe refund.
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
 * Refund a seller's order in full.
 *
 * A cart holds products from one seller, so the seller's portion is the whole
 * order. This refunds whatever is still refundable through WooCommerce, which
 * calls the gateway to issue the direct-charge Stripe refund.
 *
 * @param WC_Order $order  Order.
 * @param array    $seller Seller reference.
 * @param string   $reason Refund reason shown on the order.
 * @return array|WP_Error
 */
function extrachill_shop_refund_seller_portion( $order, array $seller, $reason = '' ) {
	if ( null === extrachill_shop_get_order_seller_payout( $order, $seller ) ) {
		return new WP_Error( 'invalid_seller', 'This order does not contain products from this seller.', array( 'status' => 400 ) );
	}

	$refund_amount = round( (float) $order->get_remaining_refund_amount(), 2 );
	if ( $refund_amount <= 0 ) {
		return new WP_Error( 'already_refunded', 'This order has already been refunded.', array( 'status' => 409 ) );
	}

	$wc_refund = wc_create_refund(
		array(
			'order_id'       => (int) $order->get_id(),
			'amount'         => $refund_amount,
			'reason'         => '' !== $reason ? $reason : 'Refund issued by seller.',
			'refund_payment' => true,
		)
	);
	if ( is_wp_error( $wc_refund ) ) {
		return new WP_Error( 'refund_failed', 'Refund failed: ' . $wc_refund->get_error_message(), array( 'status' => 502 ) );
	}

	return array(
		'success'       => true,
		'order_id'      => (int) $order->get_id(),
		'refund_amount' => $refund_amount,
	);
}

/**
 * Issue the Stripe refund for a merch order.
 *
 * Called from the gateway's `process_refund()`. WooCommerce has already
 * validated the amount and saved the refund row, so the order's remaining
 * refundable amount and total refunded already include this refund.
 *
 * @param WC_Order|false $order  Order.
 * @param float|string   $amount Refund amount in major units.
 * @param string         $reason Refund reason.
 * @return true|WP_Error
 */
function extrachill_shop_refund_merch_payment( $order, $amount, $reason = '' ) {
	if ( ! $order || ! $order->get_meta( '_extrachill_payment_intent' ) || ! extrachill_shop_stripe_init() ) {
		return new WP_Error( 'refund_unavailable', 'No refundable Stripe payment found.' );
	}
	$minor = (int) round( (float) $amount * 100 );
	if ( $minor <= 0 ) {
		return new WP_Error( 'invalid_refund_amount', 'Invalid refund amount.' );
	}
	$params = array(
		'payment_intent' => $order->get_meta( '_extrachill_payment_intent' ),
		'amount'         => $minor,
		'metadata'       => array(
			'order_id' => (string) $order->get_id(),
			'reason'   => (string) $reason,
		),
	);
	// The total refunded already includes this refund. WooCommerce deletes the
	// refund row when this call fails, so a retry reproduces the same key, and
	// a later refund of the same amount gets a different one.
	$options = array( 'idempotency_key' => 'ec-merch-refund-' . $order->get_id() . '-' . $minor . '-' . (int) round( (float) $order->get_total_refunded() * 100 ) );
	$account = (string) $order->get_meta( '_extrachill_stripe_account' );
	if ( '' !== $account ) {
		// Seller order: the charge and the platform's application fee live on
		// the connected account. Platform products have no account.
		$options['stripe_account']        = $account;
		$params['refund_application_fee'] = true;
	}
	try {
		$refund = \Stripe\Refund::create( $params, $options );
		if ( ! in_array( $refund->status ?? 'succeeded', array( 'succeeded', 'pending' ), true ) ) {
			return new WP_Error( 'refund_failed', 'Stripe refund failed.' );
		}
		return true;
	} catch ( \Exception $e ) {
		return new WP_Error( 'refund_failed', 'Stripe refund failed. Please retry.' );
	}
}
