<?php
/**
 * Shipping money follows whoever pays for the postage.
 *
 * Labels are bought with Extra Chill's Shippo account, so Extra Chill pays the
 * postage on every label a seller prints through the shop manager. The buyer's
 * shipping charge is therefore added to the application fee at checkout and
 * recorded on the order as the shipping hold. A merch charge is a direct
 * charge on the seller's account, so that is the only point at which the
 * platform can take it: the fee cannot be raised after the payment.
 *
 * - Seller buys the label through Extra Chill: the hold stays with the
 *   platform and pays for the label.
 * - Seller marks the order shipped without buying a label: they paid the
 *   postage, so the hold is returned to their account as a partial refund of
 *   the application fee.
 *
 * Account Debits (`Charge::create` with `source => acct_…`) were the other
 * option. They draw on the seller's available balance, which a direct charge
 * only fills after Stripe's payout delay, so a label printed the day of the
 * sale would often fail to be recovered, and every failure would need retry
 * and write-off handling. The application fee is collected with the payment.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/** Order meta: shipping (minor units) added to the application fee at checkout. */
const EXTRACHILL_SHOP_SHIPPING_HOLD_META = '_extrachill_shipping_hold_minor';

/** Order meta: shipping (minor units) returned to the seller. */
const EXTRACHILL_SHOP_SHIPPING_RELEASED_META = '_extrachill_shipping_released_minor';

/**
 * Shipping the buyer paid on an order, including shipping tax, in minor units.
 *
 * @param WC_Order $order Order.
 * @return int
 */
function extrachill_shop_order_shipping_minor( $order ) {
	return max( 0, (int) round( ( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() ) * 100 ) );
}

/**
 * Shipping held in the application fee that is still with the platform.
 *
 * @param WC_Order $order Order.
 * @return int Minor units.
 */
function extrachill_shop_order_shipping_hold( $order ) {
	if ( '' !== (string) $order->get_meta( EXTRACHILL_SHOP_SHIPPING_RELEASED_META ) ) {
		return 0;
	}
	return max( 0, (int) $order->get_meta( EXTRACHILL_SHOP_SHIPPING_HOLD_META ) );
}

/**
 * Whether a label can still be bought for this order through Extra Chill.
 *
 * Once the shipping has gone back to the seller, a label would be postage
 * Extra Chill pays for with nothing to cover it.
 *
 * @param WC_Order $order Order.
 * @return true|WP_Error
 */
function extrachill_shop_can_buy_label( $order ) {
	if ( '' !== (string) $order->get_meta( EXTRACHILL_SHOP_SHIPPING_RELEASED_META ) ) {
		return new WP_Error(
			'shipping_released',
			'You marked this order shipped yourself, so its shipping charge was paid out to you. Buy the label directly from the carrier.',
			array( 'status' => 409 )
		);
	}
	return true;
}

/**
 * Return the shipping hold to the seller because they shipped the order themselves.
 *
 * Refunds the shipping part of the platform's application fee to the seller's
 * connected account. If the order was partly refunded, Stripe has already
 * returned part of the fee, so at most the fee still unrefunded is returned.
 * The order is not changed when Stripe fails, so the seller can retry.
 *
 * @param WC_Order $order Order.
 * @return int|WP_Error Minor units returned (0 when there was nothing to return).
 */
function extrachill_shop_release_shipping_hold( $order ) {
	$hold    = extrachill_shop_order_shipping_hold( $order );
	$account = (string) $order->get_meta( '_extrachill_stripe_account' );
	$intent  = (string) $order->get_meta( '_extrachill_payment_intent' );
	if ( $hold <= 0 || '' === $account || '' === $intent ) {
		return 0;
	}
	if ( ! extrachill_shop_stripe_init() ) {
		return new WP_Error( 'shipping_release_failed', 'Payments are unavailable. Please try again.', array( 'status' => 503 ) );
	}
	try {
		$payment = \Stripe\PaymentIntent::retrieve(
			array(
				'id'     => $intent,
				'expand' => array( 'latest_charge' ),
			),
			array( 'stripe_account' => $account )
		);
		$fee_id  = (string) ( $payment->latest_charge->application_fee ?? '' );
		$amount  = 0;
		if ( '' !== $fee_id ) {
			$fee    = \Stripe\ApplicationFee::retrieve( $fee_id );
			$amount = min( $hold, max( 0, (int) $fee->amount - (int) $fee->amount_refunded ) );
		}
		if ( $amount > 0 ) {
			\Stripe\ApplicationFee::createRefund(
				$fee_id,
				array(
					'amount'   => $amount,
					'metadata' => array(
						'order_id' => (string) $order->get_id(),
						'reason'   => 'seller_shipped_without_label',
					),
				),
				array( 'idempotency_key' => 'ec-shipping-release-' . $order->get_id() )
			);
		}
	} catch ( \Exception $e ) {
		return new WP_Error( 'shipping_release_failed', 'We could not return the shipping charge to you. Please try again.', array( 'status' => 502 ) );
	}
	$order->update_meta_data( EXTRACHILL_SHOP_SHIPPING_RELEASED_META, $amount );
	$order->add_order_note( sprintf( 'Seller shipped without an Extra Chill label: $%s shipping returned to the seller.', number_format( $amount / 100, 2 ) ) );
	return $amount;
}
