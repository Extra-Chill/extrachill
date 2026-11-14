<?php
/**
 * Commerce fees: the one place Extra Chill's share of a sale is calculated.
 *
 * Rates come from the network `extrachill_commerce_fees` setting
 * (`ec_commerce_fee_settings()` in extrachill-network, edited on the network
 * Payments page). Checkout and ticket checkout call this to set the Stripe
 * application fee, and seller emails call it to show the payout. Refunds
 * never recompute it: they return the fee Stripe recorded on the charge.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Platform fees for a sale.
 *
 * - `extrachill_merch`: the merch percentage of the item subtotal (after
 *   discounts, before tax and shipping). No buyer fee.
 * - `extrachill_ticket`: a buyer service fee (flat per order plus a
 *   percentage), which is added to the buyer's total, plus the seller ticket
 *   percentage. The application fee is both together.
 *
 * Platform-owned products (no seller) are charged on the platform account, so
 * there is no application fee.
 *
 * @param string     $kind           `extrachill_merch` or `extrachill_ticket`.
 * @param int        $subtotal_minor Item subtotal in minor units.
 * @param array|null $seller         Seller reference, or null for platform products.
 * @return array{buyer_fee:int,application_fee:int} Minor units. `application_fee` includes `buyer_fee`.
 * @throws InvalidArgumentException For an unknown kind.
 */
function extrachill_commerce_fee( $kind, $subtotal_minor, $seller ) {
	if ( ! in_array( $kind, array( 'extrachill_merch', 'extrachill_ticket' ), true ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Programming error, never shown to users.
		throw new InvalidArgumentException( 'Unknown commerce fee kind: ' . $kind );
	}
	$subtotal = max( 0, (int) $subtotal_minor );
	if ( ! $seller || 0 === $subtotal ) {
		return array(
			'buyer_fee'       => 0,
			'application_fee' => 0,
		);
	}
	$settings = ec_commerce_fee_settings();
	if ( 'extrachill_merch' === $kind ) {
		return array(
			'buyer_fee'       => 0,
			'application_fee' => min( $subtotal, (int) round( $subtotal * $settings['merch_percent'] / 100 ) ),
		);
	}
	$buyer = $settings['ticket_buyer_fee_flat_minor'] + (int) round( $subtotal * $settings['ticket_buyer_fee_percent'] / 100 );
	return array(
		'buyer_fee'       => $buyer,
		'application_fee' => $buyer + (int) round( $subtotal * $settings['ticket_percent'] / 100 ),
	);
}
