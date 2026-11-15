<?php
/**
 * Merch order refunds.
 *
 * Merch orders are Stripe direct charges. A seller order is charged on the
 * seller's connected account (stored on the order as
 * `_extrachill_stripe_account`) with an application fee for the platform.
 * A platform-owned product order is charged on the platform account and has
 * no connected account. A refund refunds the PaymentIntent on the account that
 * holds the charge. For a seller order the platform then returns its
 * application fee explicitly (`extrachill_shop_merch_fee_refund_target()`),
 * because the fee also holds the buyer's shipping, and shipping already spent
 * on an Extra Chill label is not given back:
 *
 * - Commission (fee minus shipping hold) is returned in proportion to the
 *   share of the charge refunded so far.
 * - Shipping hold: no label bought, returned when the order is fully refunded;
 *   label bought, kept (it paid for the postage); returned to the seller
 *   already when they shipped themselves, so nothing more.
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
 * Reads the canonical order seller record (`extrachill_shop_get_order_seller_record()`).
 *
 * @param WC_Order $order  Order.
 * @param array    $seller Seller reference.
 * @return array|null
 */
function extrachill_shop_get_order_seller_payout( $order, array $seller ) {
	return extrachill_shop_order_has_seller( $order, $seller ) ? extrachill_shop_get_order_seller_record( $order ) : null;
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
		// Seller order: the charge lives on the connected account. The
		// platform's application fee is returned explicitly below so shipping
		// already spent on a label stays with the platform. Platform products
		// have no account and no fee.
		$options['stripe_account']        = $account;
		$params['refund_application_fee'] = false;
	}
	try {
		$refund = \Stripe\Refund::create( $params, $options );
		if ( ! in_array( $refund->status ?? 'succeeded', array( 'succeeded', 'pending' ), true ) ) {
			return new WP_Error( 'refund_failed', 'Stripe refund failed.' );
		}
	} catch ( \Exception $e ) {
		return new WP_Error( 'refund_failed', 'Stripe refund failed. Please retry.' );
	}
	if ( '' !== $account ) {
		// The buyer has their money, so the WooCommerce refund stands even if
		// the fee refund fails; the order note tells an operator to finish it.
		$fee_refund = extrachill_shop_refund_merch_application_fee( $order );
		if ( is_wp_error( $fee_refund ) ) {
			$order->add_order_note( 'Buyer refunded, but returning the Extra Chill application fee to the seller failed: ' . $fee_refund->get_error_message() );
		}
	}
	return true;
}

/**
 * Application fee (minor units) the platform should have returned to the
 * seller once the order's refunds so far are applied.
 *
 * Cumulative, so every refund (and a retry) tops the fee refund up to this
 * target instead of adding to it.
 *
 * @param WC_Order $order     Order. Its total refunded already includes the refund being processed.
 * @param int      $fee_minor Application fee collected on the charge.
 * @return int
 */
function extrachill_shop_merch_fee_refund_target( $order, $fee_minor ) {
	$fee_minor  = max( 0, (int) $fee_minor );
	$hold_minor = min( $fee_minor, max( 0, (int) $order->get_meta( EXTRACHILL_SHOP_SHIPPING_HOLD_META ) ) );
	$commission = $fee_minor - $hold_minor;
	$total      = max( 0, (int) round( (float) $order->get_total() * 100 ) );
	$refunded   = min( $total, max( 0, (int) round( (float) $order->get_total_refunded() * 100 ) ) );
	$full       = $total > 0 && $refunded >= $total;

	$commission_target = $full ? $commission : ( $total > 0 ? (int) floor( $commission * $refunded / $total ) : 0 );

	$released = (string) $order->get_meta( EXTRACHILL_SHOP_SHIPPING_RELEASED_META );
	if ( '' !== $released ) {
		// Already refunded to the seller, who shipped without a label.
		$shipping_target = min( $hold_minor, max( 0, (int) $released ) );
	} elseif ( '' !== extrachill_shop_get_order_shipment( $order )['label_url'] ) {
		// Spent on the Extra Chill label.
		$shipping_target = 0;
	} else {
		// Unspent: returned once nothing is left to ship.
		$shipping_target = $full ? $hold_minor : 0;
	}

	return min( $fee_minor, $commission_target + $shipping_target );
}

/**
 * The platform application fee on a seller order's charge.
 *
 * The charge is read on the seller's connected account; the fee object lives
 * on the platform. Throws on Stripe errors.
 *
 * @param WC_Order $order Order.
 * @return array{id: string, amount: int, amount_refunded: int}|null Null when the charge has none.
 */
function extrachill_shop_get_order_application_fee( $order ) {
	$payment = \Stripe\PaymentIntent::retrieve(
		array(
			'id'     => (string) $order->get_meta( '_extrachill_payment_intent' ),
			'expand' => array( 'latest_charge' ),
		),
		array( 'stripe_account' => (string) $order->get_meta( '_extrachill_stripe_account' ) )
	);
	$fee_id  = (string) ( $payment->latest_charge->application_fee ?? '' );
	if ( '' === $fee_id ) {
		return null;
	}
	$fee = \Stripe\ApplicationFee::retrieve( $fee_id );
	return array(
		'id'              => $fee_id,
		'amount'          => (int) $fee->amount,
		'amount_refunded' => (int) $fee->amount_refunded,
	);
}

/**
 * Return the platform application fee owed to the seller after a refund.
 *
 * @param WC_Order $order Order, its total refunded including the refund just issued.
 * @return int|WP_Error Minor units returned now.
 */
function extrachill_shop_refund_merch_application_fee( $order ) {
	try {
		$fee = extrachill_shop_get_order_application_fee( $order );
		if ( null === $fee ) {
			return 0;
		}
		$target = extrachill_shop_merch_fee_refund_target( $order, $fee['amount'] );
		$amount = min( $fee['amount'] - $fee['amount_refunded'], $target - $fee['amount_refunded'] );
		if ( $amount <= 0 ) {
			return 0;
		}
		\Stripe\ApplicationFee::createRefund(
			$fee['id'],
			array(
				'amount'   => $amount,
				'metadata' => array(
					'order_id' => (string) $order->get_id(),
					'reason'   => 'order_refund',
				),
			),
			array( 'idempotency_key' => 'ec-merch-fee-refund-' . $order->get_id() . '-' . $target . '-' . $fee['amount_refunded'] )
		);
		return $amount;
	} catch ( \Exception $e ) {
		return new WP_Error( 'fee_refund_failed', $e->getMessage() );
	}
}
