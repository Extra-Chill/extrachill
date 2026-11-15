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

/**
 * Format a fee percentage for sellers: `10`, `12.5`, `2.25`.
 *
 * @param float $percent Percentage.
 * @return string
 */
function extrachill_shop_format_fee_percent( $percent ) {
	return rtrim( rtrim( number_format( (float) $percent, 2, '.', '' ), '0' ), '.' );
}

/**
 * The current fee rates, as sellers see them before they list.
 *
 * Read from the same network setting `extrachill_commerce_fee()` charges
 * with, so the shop manager never shows a rate checkout does not use. The
 * summaries are the seller-facing wording; clients show them as-is.
 *
 * @return array{merch_percent: float, ticket_percent: float, ticket_buyer_fee_percent: float, ticket_buyer_fee_flat: float, merch_summary: string, ticket_summary: string}
 */
function extrachill_shop_seller_fee_rates() {
	$settings = function_exists( 'ec_commerce_fee_settings' ) ? ec_commerce_fee_settings() : array();
	$merch    = (float) ( $settings['merch_percent'] ?? 0 );
	$ticket   = (float) ( $settings['ticket_percent'] ?? 0 );
	$buyer_pc = (float) ( $settings['ticket_buyer_fee_percent'] ?? 0 );
	$buyer_fl = round( ( (int) ( $settings['ticket_buyer_fee_flat_minor'] ?? 0 ) ) / 100, 2 );

	$ticket_summary = sprintf(
		/* translators: %s: ticket fee percentage. */
		__( 'Extra Chill fee on tickets: %s%% of the ticket price.', 'extrachill-shop' ),
		extrachill_shop_format_fee_percent( $ticket )
	);
	if ( $buyer_fl > 0 && $buyer_pc > 0 ) {
		$ticket_summary .= ' ' . sprintf(
			/* translators: 1: flat fee in dollars, 2: percentage. */
			__( 'Buyers also pay a service fee of $%1$s plus %2$s%% per order.', 'extrachill-shop' ),
			number_format( $buyer_fl, 2 ),
			extrachill_shop_format_fee_percent( $buyer_pc )
		);
	} elseif ( $buyer_fl > 0 ) {
		$ticket_summary .= ' ' . sprintf(
			/* translators: %s: flat fee in dollars. */
			__( 'Buyers also pay a service fee of $%s per order.', 'extrachill-shop' ),
			number_format( $buyer_fl, 2 )
		);
	} elseif ( $buyer_pc > 0 ) {
		$ticket_summary .= ' ' . sprintf(
			/* translators: %s: percentage. */
			__( 'Buyers also pay a service fee of %s%% per order.', 'extrachill-shop' ),
			extrachill_shop_format_fee_percent( $buyer_pc )
		);
	}

	return array(
		'merch_percent'            => $merch,
		'ticket_percent'           => $ticket,
		'ticket_buyer_fee_percent' => $buyer_pc,
		'ticket_buyer_fee_flat'    => $buyer_fl,
		'merch_summary'            => sprintf(
			/* translators: %s: merch fee percentage. */
			__( 'Extra Chill fee: %s%% of the item price (shipping not included).', 'extrachill-shop' ),
			extrachill_shop_format_fee_percent( $merch )
		),
		'ticket_summary'           => $ticket_summary,
	);
}
