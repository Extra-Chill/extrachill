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
 *   discounts, before tax and shipping). No buyer fee. `$quantity` is ignored.
 * - `extrachill_ticket`: see `extrachill_commerce_ticket_fee()`. The buyer
 *   pays one service fee (per ticket, per order, an optional percentage, and
 *   the card processing estimate), so the venue nets the ticket subtotal.
 *
 * Platform-owned products (no seller) are charged on the platform account, so
 * there is no application fee. Free sales have no fee.
 *
 * @param string     $kind           `extrachill_merch` or `extrachill_ticket`.
 * @param int        $subtotal_minor Item subtotal in minor units.
 * @param array|null $seller         Seller reference, or null for platform products.
 * @param int        $quantity       Tickets in the order (tickets only).
 * @return array{buyer_fee:int,application_fee:int} Minor units.
 * @throws InvalidArgumentException For an unknown kind.
 */
function extrachill_commerce_fee( $kind, $subtotal_minor, $seller, $quantity = 1 ) {
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
	return extrachill_commerce_ticket_fee( $subtotal, max( 1, (int) $quantity ), $settings );
}

/**
 * Ticket fees: the buyer pays a service fee, the venue keeps the subtotal.
 *
 * Ticket checkout is a direct charge on the venue's payout account, so the
 * card processor takes its fee out of the venue's side of the whole charge.
 * The service fee is grossed up to cover that:
 *
 *     base  = subtotal + per_ticket × quantity + per_order
 *             + buyer_percent × subtotal + processing_fixed
 *     total = ceil( base / (1 − processing_percent) )
 *     service fee      = total − subtotal
 *     processing (est) = ceil( total × processing_percent ) + processing_fixed
 *     application fee  = total − subtotal − processing (est) + seller fee
 *
 * so the venue nets `total − processing − application fee = subtotal − seller
 * fee` at the estimate (the seller fee is `ticket_percent`, default 0). The
 * estimate rounds up, and the total is nudged up a cent at a time until the
 * application fee is not negative, so the venue never nets less than that at
 * the estimate. Real processing can differ (international or currency
 * converted cards cost more); the estimate is a setting so it can be set
 * conservatively.
 *
 * @param int   $subtotal Ticket subtotal in minor units (price × quantity), above 0.
 * @param int   $quantity Tickets in the order, at least 1.
 * @param array $settings `ec_commerce_fee_settings()`.
 * @return array{buyer_fee:int,application_fee:int} Minor units. `buyer_fee` is the Service fee line.
 */
function extrachill_commerce_ticket_fee( $subtotal, $quantity, array $settings ) {
	$per_ticket = (int) ( $settings['ticket_buyer_fee_per_ticket_minor'] ?? 0 );
	$per_order  = (int) ( $settings['ticket_buyer_fee_flat_minor'] ?? 0 );
	$fixed      = (int) ( $settings['ticket_processing_fixed_minor'] ?? 0 );
	// Basis points as integers keep the gross-up exact.
	$bps        = min( 5000, max( 0, (int) round( (float) ( $settings['ticket_processing_percent'] ?? 0 ) * 100 ) ) );
	$seller_fee = (int) round( $subtotal * (float) ( $settings['ticket_percent'] ?? 0 ) / 100 );
	$base       = $subtotal + $per_ticket * $quantity + $per_order + (int) round( $subtotal * (float) ( $settings['ticket_buyer_fee_percent'] ?? 0 ) / 100 ) + $fixed;
	if ( $base === $subtotal && 0 === $bps ) {
		// No buyer fee and no processing estimate: only the seller fee, if any.
		return array(
			'buyer_fee'       => 0,
			'application_fee' => min( $subtotal, $seller_fee ),
		);
	}
	$total = intdiv( $base * 10000 + ( 10000 - $bps ) - 1, 10000 - $bps );
	while ( $total - $subtotal - extrachill_commerce_ticket_processing_estimate( $total, $settings ) < 0 ) {
		++$total;
	}
	$application = $total - $subtotal - extrachill_commerce_ticket_processing_estimate( $total, $settings ) + $seller_fee;
	return array(
		'buyer_fee'       => $total - $subtotal,
		'application_fee' => min( $total, $application ),
	);
}

/**
 * Estimated card processing on a ticket charge, rounded up.
 *
 * @param int   $total_minor Charge total in minor units.
 * @param array $settings    `ec_commerce_fee_settings()`.
 * @return int Minor units.
 */
function extrachill_commerce_ticket_processing_estimate( $total_minor, array $settings ) {
	$bps = min( 5000, max( 0, (int) round( (float) ( $settings['ticket_processing_percent'] ?? 0 ) * 100 ) ) );
	return intdiv( (int) $total_minor * $bps + 9999, 10000 ) + (int) ( $settings['ticket_processing_fixed_minor'] ?? 0 );
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
 * summaries are the seller-facing wording; clients show them as-is. With no
 * seller ticket fee (the default), the ticket summary says the venue keeps
 * the full ticket price and buyers pay the service fee.
 *
 * @return array{merch_percent: float, ticket_percent: float, ticket_buyer_fee_percent: float, ticket_buyer_fee_flat: float, ticket_buyer_fee_per_ticket: float, merch_summary: string, ticket_summary: string}
 */
function extrachill_shop_seller_fee_rates() {
	$settings   = function_exists( 'ec_commerce_fee_settings' ) ? ec_commerce_fee_settings() : array();
	$merch      = (float) ( $settings['merch_percent'] ?? 0 );
	$ticket     = (float) ( $settings['ticket_percent'] ?? 0 );
	$buyer_pc   = (float) ( $settings['ticket_buyer_fee_percent'] ?? 0 );
	$buyer_fl   = round( ( (int) ( $settings['ticket_buyer_fee_flat_minor'] ?? 0 ) ) / 100, 2 );
	$buyer_tk   = round( ( (int) ( $settings['ticket_buyer_fee_per_ticket_minor'] ?? 0 ) ) / 100, 2 );
	$processing = (float) ( $settings['ticket_processing_percent'] ?? 0 ) > 0 || (int) ( $settings['ticket_processing_fixed_minor'] ?? 0 ) > 0;

	$ticket_summary = $ticket > 0
		? sprintf(
			/* translators: %s: ticket fee percentage. */
			__( 'Extra Chill fee on tickets: %s%% of the ticket price.', 'extrachill-shop' ),
			extrachill_shop_format_fee_percent( $ticket )
		)
		: __( 'Tickets: you keep the full ticket price. Extra Chill takes nothing from it.', 'extrachill-shop' );

	$parts = array();
	if ( $buyer_tk > 0 ) {
		/* translators: %s: fee in dollars. */
		$parts[] = sprintf( __( '$%s per ticket', 'extrachill-shop' ), number_format( $buyer_tk, 2 ) );
	}
	if ( $buyer_fl > 0 ) {
		/* translators: %s: fee in dollars. */
		$parts[] = sprintf( __( '$%s per order', 'extrachill-shop' ), number_format( $buyer_fl, 2 ) );
	}
	if ( $buyer_pc > 0 ) {
		/* translators: %s: percentage. */
		$parts[] = sprintf( __( '%s%% of the ticket price', 'extrachill-shop' ), extrachill_shop_format_fee_percent( $buyer_pc ) );
	}
	if ( $parts ) {
		if ( $processing ) {
			$parts[] = __( 'card processing', 'extrachill-shop' );
		}
		/* translators: %s: list of fee parts, such as "$1.00 per ticket plus card processing". */
		$ticket_summary .= ' ' . sprintf( __( 'Buyers pay a service fee of %s, shown at checkout.', 'extrachill-shop' ), implode( __( ' plus ', 'extrachill-shop' ), $parts ) );
	} elseif ( $processing ) {
		$ticket_summary .= ' ' . __( 'Buyers pay a service fee that covers card processing, shown at checkout.', 'extrachill-shop' );
	}

	return array(
		'merch_percent'               => $merch,
		'ticket_percent'              => $ticket,
		'ticket_buyer_fee_percent'    => $buyer_pc,
		'ticket_buyer_fee_flat'       => $buyer_fl,
		'ticket_buyer_fee_per_ticket' => $buyer_tk,
		'merch_summary'               => sprintf(
			/* translators: %s: merch fee percentage. */
			__( 'Extra Chill fee: %s%% of the item price (shipping not included).', 'extrachill-shop' ),
			extrachill_shop_format_fee_percent( $merch )
		),
		'ticket_summary'              => $ticket_summary,
	);
}
