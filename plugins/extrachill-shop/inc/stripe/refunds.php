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
 * - Commission (fee minus shipping hold and processing) is returned in
 *   proportion to the share of the charge refunded so far.
 * - Processing: when Extra Chill pays the seller's card processing, the fee
 *   includes the processing estimate (`EXTRACHILL_SHOP_PROCESSING_FEE_META`,
 *   see `extrachill_commerce_merch_fee()`). It is never returned, not even on
 *   a full refund: the processor keeps its fee on a refund, Extra Chill paid
 *   it, and a seller who pays processing themselves is in the same place.
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

/** Order meta: card processing (minor units) included in the application fee at checkout. */
const EXTRACHILL_SHOP_PROCESSING_FEE_META = '_extrachill_processing_fee_minor';

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
 * Refund a seller's order, in full or in part.
 *
 * A cart holds products from one seller, so the seller's portion is the whole
 * order. Without an amount this refunds whatever is still refundable; with
 * one it refunds that much, up to what is left. WooCommerce records the
 * refund and calls the gateway to issue the direct-charge refund, and the
 * gateway returns the prorated application fee
 * (`extrachill_shop_merch_fee_refund_target()`).
 *
 * @param WC_Order          $order  Order.
 * @param array             $seller Seller reference.
 * @param string            $reason Refund reason shown on the order.
 * @param float|string|null $amount Amount in major units, or null for everything left.
 * @return array|WP_Error
 */
function extrachill_shop_refund_seller_portion( $order, array $seller, $reason = '', $amount = null ) {
	if ( null === extrachill_shop_get_order_seller_payout( $order, $seller ) ) {
		return new WP_Error( 'invalid_seller', 'This order does not contain products from this seller.', array( 'status' => 400 ) );
	}
	// The bank decides a disputed payment; a refund now is refused upstream.
	if ( extrachill_shop_order_is_disputed( $order ) ) {
		return extrachill_shop_order_disputed_error();
	}

	$remaining = round( (float) $order->get_remaining_refund_amount(), 2 );
	if ( $remaining <= 0 ) {
		return new WP_Error( 'already_refunded', 'This order has already been refunded.', array( 'status' => 409 ) );
	}

	$refund_amount = extrachill_shop_validate_refund_amount( $amount, $remaining );
	if ( is_wp_error( $refund_amount ) ) {
		return $refund_amount;
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
		'remaining'     => max( 0.0, round( $remaining - $refund_amount, 2 ) ),
	);
}

/**
 * Validate a seller's refund amount against what is left to refund.
 *
 * @param float|string|null $amount    Requested amount in major units, or null for all of it.
 * @param float             $remaining Refundable amount left on the order.
 * @return float|WP_Error Amount to refund, rounded to cents.
 */
function extrachill_shop_validate_refund_amount( $amount, $remaining ) {
	$remaining = round( (float) $remaining, 2 );
	if ( null === $amount || '' === $amount ) {
		return $remaining;
	}
	if ( ! is_numeric( $amount ) || round( (float) $amount, 2 ) <= 0 ) {
		return new WP_Error( 'invalid_refund_amount', 'Enter a refund amount greater than $0.00.', array( 'status' => 400 ) );
	}
	$amount = round( (float) $amount, 2 );
	if ( $amount > $remaining ) {
		return new WP_Error(
			'refund_exceeds_remaining',
			sprintf( 'You can refund up to $%s on this order.', number_format( $remaining, 2 ) ),
			array(
				'status'    => 400,
				'remaining' => $remaining,
			)
		);
	}
	return $amount;
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
		return new WP_Error( 'refund_unavailable', 'No refundable payment found for this order.' );
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
			return new WP_Error( 'refund_failed', 'The payment refund failed.' );
		}
	} catch ( \Exception $e ) {
		return new WP_Error( 'refund_failed', 'The payment refund failed. Please retry.' );
	}
	// Tag the WooCommerce refund row with this refund ID once it is final
	// (`woocommerce_refund_created`), so `charge.refunded` skips it.
	$GLOBALS['extrachill_shop_pending_refund_id'] = (string) ( $refund->id ?? '' );
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
 * target instead of adding to it. The processing part of the fee is never
 * part of the target.
 *
 * @param WC_Order $order     Order. Its total refunded already includes the refund being processed.
 * @param int      $fee_minor Application fee collected on the charge.
 * @return int
 */
function extrachill_shop_merch_fee_refund_target( $order, $fee_minor ) {
	$fee_minor  = max( 0, (int) $fee_minor );
	$hold_minor = min( $fee_minor, max( 0, (int) $order->get_meta( EXTRACHILL_SHOP_SHIPPING_HOLD_META ) ) );
	// Processing Extra Chill paid stays with it (orders from before #1563 have none).
	$processing = min( $fee_minor - $hold_minor, max( 0, (int) $order->get_meta( EXTRACHILL_SHOP_PROCESSING_FEE_META ) ) );
	$commission = $fee_minor - $hold_minor - $processing;
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

/**
 * WooCommerce refund meta naming the Stripe refund it records.
 */
const EXTRACHILL_SHOP_REFUND_ID_META = '_extrachill_stripe_refund_id';

add_action( 'woocommerce_refund_created', 'extrachill_shop_tag_refund_with_stripe_id' );

/**
 * Store the Stripe refund ID our gateway just issued on the WooCommerce refund.
 *
 * WooCommerce saves the refund row, calls the gateway, then fires
 * `woocommerce_refund_created`; the gateway leaves the ID for this hook.
 *
 * @param int $refund_id WooCommerce refund ID.
 */
function extrachill_shop_tag_refund_with_stripe_id( $refund_id ): void {
	$stripe_id = (string) ( $GLOBALS['extrachill_shop_pending_refund_id'] ?? '' );
	unset( $GLOBALS['extrachill_shop_pending_refund_id'] );
	$refund = '' === $stripe_id ? null : wc_get_order( $refund_id );
	if ( $refund instanceof WC_Order_Refund ) {
		$refund->update_meta_data( EXTRACHILL_SHOP_REFUND_ID_META, $stripe_id );
		$refund->save();
	}
}

/**
 * Stripe refund IDs already recorded as WooCommerce refunds on an order.
 *
 * @param WC_Order $order Order.
 * @return string[]
 */
function extrachill_shop_recorded_stripe_refunds( $order ): array {
	$ids = array();
	foreach ( $order->get_refunds() as $refund ) {
		$id = (string) $refund->get_meta( EXTRACHILL_SHOP_REFUND_ID_META );
		if ( '' !== $id ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/**
 * Total of the WooCommerce refunds that record a lost dispute.
 *
 * @param WC_Order $order Order.
 * @return float Major units.
 */
function extrachill_shop_recorded_dispute_refund_total( $order ): float {
	$outcome = (string) $order->get_meta( '_extrachill_dispute_outcome' );
	$dispute = '' === $outcome ? '' : strstr( $outcome, ':', true );
	$total   = 0.0;
	if ( '' === (string) $dispute ) {
		return $total;
	}
	foreach ( $order->get_refunds() as $refund ) {
		if ( (string) $refund->get_meta( EXTRACHILL_SHOP_REFUND_ID_META ) === $dispute ) {
			$total += (float) $refund->get_amount();
		}
	}
	return $total;
}

/**
 * Refunds on a charge, from the event or, when it is truncated, from Stripe.
 *
 * @param object $charge  Stripe charge.
 * @param string $account Connected account.
 * @return iterable<object>
 */
function extrachill_shop_charge_refund_rows( $charge, $account ) {
	$refunds = $charge->refunds ?? null;
	if ( $refunds && empty( $refunds->has_more ) ) {
		return $refunds->data ?? array();
	}
	// @phpstan-ignore-next-line staticMethod.notFound -- Refund::all comes from the SDK's ApiOperations\All trait.
	$refunds = \Stripe\Refund::all(
		array(
			'charge' => (string) ( $charge->id ?? '' ),
			'limit'  => 100,
		),
		array( 'stripe_account' => $account )
	);
	return $refunds->autoPagingIterator();
}

/**
 * Record merch refunds made outside Extra Chill (`charge.refunded`).
 *
 * A seller can refund a buyer from their payout dashboard. The money has
 * moved, so each new refund is recorded in WooCommerce without refunding
 * the payment again, and the order gets a note. Idempotent:
 *
 * - Nothing happens unless the charge's `amount_refunded` exceeds what the
 *   order already records, so our own refunds (recorded in WooCommerce
 *   before the payment refund is issued) and replays are no-ops.
 * - Refunds already recorded carry their Stripe refund ID in WooCommerce
 *   refund meta and are skipped. Refunds created by
 *   `extrachill_shop_refund_merch_payment()` also carry the order ID in
 *   their metadata, which covers a webhook that beats the meta write.
 *
 * Seller orders then get the prorated application fee back
 * (`extrachill_shop_refund_merch_application_fee()`), as a refund from the
 * shop manager would.
 *
 * @param object $charge  Stripe charge.
 * @param string $account Connected account the charge is on.
 * @return true|WP_Error WP_Error asks Stripe to retry.
 */
function extrachill_shop_merch_charge_refunded( $charge, $account ) {
	$order = extrachill_shop_find_merch_order_by_payment_intent( (string) ( $charge->payment_intent ?? '' ), $account );
	if ( ! $order ) {
		error_log( 'Extra Chill charge.refunded ' . ( $charge->id ?? '' ) . ' on ' . $account . ' matches no merch order.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; a retry would not find it either.
		return true;
	}

	// A lost dispute is recorded as a refund but is not in the charge's
	// `amount_refunded`; its refund still counts against what is left.
	$recorded_minor = (int) round( ( (float) $order->get_total_refunded() - extrachill_shop_recorded_dispute_refund_total( $order ) ) * 100 );
	$unrecorded     = (int) ( $charge->amount_refunded ?? 0 ) - $recorded_minor;
	if ( $unrecorded <= 0 ) {
		return true;
	}

	$order_id = (string) $order->get_id();
	$known    = extrachill_shop_recorded_stripe_refunds( $order );
	$added    = 0;
	try {
		$rows = extrachill_shop_charge_refund_rows( $charge, $account );
		foreach ( $rows as $refund ) {
			$refund_id = (string) ( $refund->id ?? '' );
			$status    = (string) ( $refund->status ?? 'succeeded' );
			if ( '' === $refund_id || ! in_array( $status, array( 'succeeded', 'pending' ), true ) || in_array( $refund_id, $known, true ) ) {
				continue;
			}
			// Issued by our own refund flow, which recorded it in WooCommerce first.
			if ( (string) ( $refund->metadata->order_id ?? '' ) === $order_id ) {
				continue;
			}
			$remaining = (int) round( (float) $order->get_remaining_refund_amount() * 100 );
			$minor     = min( (int) ( $refund->amount ?? 0 ), $unrecorded, $remaining );
			if ( $minor <= 0 ) {
				break;
			}
			$wc_refund = wc_create_refund(
				array(
					'order_id'       => (int) $order->get_id(),
					'amount'         => round( $minor / 100, 2 ),
					'reason'         => 'Refunded from the seller\'s payout dashboard.',
					'refund_payment' => false,
				)
			);
			if ( is_wp_error( $wc_refund ) ) {
				return new WP_Error( 'merch_refund_record_failed', 'Could not record refund ' . $refund_id . ': ' . $wc_refund->get_error_message(), array( 'status' => 500 ) );
			}
			$wc_refund->update_meta_data( EXTRACHILL_SHOP_REFUND_ID_META, $refund_id );
			$wc_refund->save();
			$known[] = $refund_id;
			$order->add_order_note( sprintf( 'The seller refunded $%1$s from their payout dashboard (refund %2$s). Recorded here; no further payment refund was issued.', number_format( $minor / 100, 2 ), $refund_id ) );
			$unrecorded -= $minor;
			$added      += $minor;
			if ( $unrecorded <= 0 ) {
				break;
			}
		}
	} catch ( \Exception $e ) {
		return new WP_Error( 'merch_refund_lookup_failed', 'Could not list refunds for charge ' . ( $charge->id ?? '' ) . ': ' . $e->getMessage(), array( 'status' => 500 ) );
	}

	if ( $added > 0 && '' !== (string) $order->get_meta( '_extrachill_stripe_account' ) ) {
		// Same fee math as a shop-manager refund: return the prorated commission
		// (and unspent shipping once fully refunded). The buyer's refund stands
		// either way; a failure is left in a note for an operator.
		$fresh      = wc_get_order( $order->get_id() );
		$fee_refund = extrachill_shop_refund_merch_application_fee( $fresh instanceof WC_Order ? $fresh : $order );
		if ( is_wp_error( $fee_refund ) ) {
			$order->add_order_note( 'Dashboard refund recorded, but returning the Extra Chill application fee to the seller failed: ' . $fee_refund->get_error_message() );
		}
	}
	return true;
}
