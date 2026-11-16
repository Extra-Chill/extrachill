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
 *   discounts, before tax and shipping). No buyer fee. `$quantity` and
 *   `$fee_payer` are ignored: this is the commission only. Merch checkout
 *   uses `extrachill_commerce_merch_fee()`, which adds card processing (when
 *   Extra Chill pays it) and the shipping hold on the whole charge.
 * - `extrachill_ticket`: see `extrachill_commerce_ticket_fee()`. The buyer
 *   pays one service fee (per ticket, per order, an optional percentage, and
 *   the card processing estimate), so the venue nets the ticket subtotal.
 *   `$fee_payer` (the seller account's `controller.fees.payer`) decides how
 *   much of the service fee is the application fee.
 *
 * Platform-owned products (no seller) are charged on the platform account, so
 * there is no application fee. Free sales have no fee.
 *
 * @param string     $kind           `extrachill_merch` or `extrachill_ticket`.
 * @param int        $subtotal_minor Item subtotal in minor units.
 * @param array|null $seller         Seller reference, or null for platform products.
 * @param int        $quantity       Tickets in the order (tickets only).
 * @param string     $fee_payer      Who pays card processing on the seller's direct
 *                                   charges (tickets only); '' when unknown.
 * @return array{buyer_fee:int,application_fee:int} Minor units.
 * @throws InvalidArgumentException For an unknown kind.
 */
function extrachill_commerce_fee( $kind, $subtotal_minor, $seller, $quantity = 1, $fee_payer = '' ) {
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
	return extrachill_commerce_ticket_fee( $subtotal, max( 1, (int) $quantity ), $settings, $fee_payer );
}

/**
 * The merch application fee for a checkout, split into its parts.
 *
 * Merch sellers pay card processing (Seller Agreement §3). Merch checkout is
 * a direct charge on the seller's payout account:
 *
 * - `account` pays processing (Standard accounts, and the fail-safe when the
 *   payer is unknown): the processor takes processing from the seller, so the
 *   application fee is the commission alone.
 * - `platform` pays processing (the Express accounts Extra Chill creates):
 *   the processor takes processing from Extra Chill, so the application fee
 *   adds the processing estimate on the whole charge (items, shipping and
 *   anything else the buyer pays). Extra Chill then nets the commission.
 *
 * The shipping hold (`shipping-recovery.php`) is added on top, unchanged.
 * The fee never exceeds the charge total. If the cap bites, the commission
 * gives way first, then processing; the hold always fits because it is part
 * of what the buyer paid beyond the items. The returned parts add up to
 * `application_fee`; checkout stores `processing` on the order so a refund
 * can keep it (`extrachill_shop_merch_fee_refund_target()`).
 *
 * @param int        $subtotal_minor Item subtotal (after discounts, before tax and shipping).
 * @param int        $total_minor    Whole charge the buyer pays.
 * @param array|null $seller         Seller reference, or null for platform products.
 * @param string     $fee_payer      Stripe `controller.fees.payer` of the seller's account, or ''.
 * @param int        $hold_minor     Shipping held in the fee for an Extra Chill label.
 * @return array{commission:int,processing:int,shipping_hold:int,application_fee:int,processing_payer:string} Minor units.
 */
function extrachill_commerce_merch_fee( $subtotal_minor, $total_minor, $seller, $fee_payer = '', $hold_minor = 0 ) {
	$total = max( 0, (int) $total_minor );
	$payer = extrachill_commerce_processing_payer( $fee_payer );
	if ( ! $seller || 0 === $total ) {
		return array(
			'commission'       => 0,
			'processing'       => 0,
			'shipping_hold'    => 0,
			'application_fee'  => 0,
			'processing_payer' => $payer,
		);
	}
	$hold       = min( $total, max( 0, (int) $hold_minor ) );
	$settings   = function_exists( 'ec_commerce_fee_settings' ) ? ec_commerce_fee_settings() : array();
	$processing = 'platform' === $payer ? min( $total - $hold, extrachill_commerce_processing_estimate( $total, $settings ) ) : 0;
	$commission = min( $total - $hold - $processing, extrachill_commerce_fee( 'extrachill_merch', $subtotal_minor, $seller )['application_fee'] );
	return array(
		'commission'       => $commission,
		'processing'       => $processing,
		'shipping_hold'    => $hold,
		'application_fee'  => $commission + $processing + $hold,
		'processing_payer' => $payer,
	);
}

/**
 * Who bears card processing on a direct charge: `account` or `platform`.
 *
 * Stripe reports `controller.fees.payer` as `account` (the connected account
 * pays, as on Standard accounts) or `application`, `application_express`,
 * `application_custom` (the platform pays, as on the Express accounts Extra
 * Chill creates).
 *
 * Anything else, including '' (not known yet), counts as `account`. For
 * merch that means the application fee is the commission only, never the
 * processing estimate as well: taking it when the account also pays would
 * charge the seller processing twice. For tickets it is the fail-safe for
 * the venue: with the account-pays application fee the
 * venue nets at least the ticket subtotal whoever really pays processing
 * (if the platform pays, the venue nets the subtotal plus processing and
 * Extra Chill keeps only the rounding). The platform-pays fee would leave an
 * account-pays venue short by the processing. Guessing wrong costs Extra
 * Chill its share of that sale, never the venue, so the payer is stored and
 * backfilled before checkout to keep '' rare.
 *
 * @param string $fee_payer Stripe `controller.fees.payer`, or ''.
 * @return string `account` or `platform`.
 */
function extrachill_commerce_processing_payer( $fee_payer ): string {
	return str_starts_with( (string) $fee_payer, 'application' ) ? 'platform' : 'account';
}

/**
 * Ticket fees: the buyer pays a service fee, the venue keeps the subtotal.
 *
 * Ticket checkout is a direct charge on the venue's payout account. The
 * service fee is grossed up to cover card processing on the whole charge:
 *
 *     base  = subtotal + per_ticket × quantity + per_order
 *             + buyer_percent × subtotal + processing_fixed
 *     total = ceil( base / (1 − processing_percent) )
 *     service fee      = total − subtotal
 *     processing (est) = ceil( total × processing_percent ) + processing_fixed
 *
 * The buyer's total and service fee are the same whoever pays processing.
 * The application fee depends on who Stripe debits for processing
 * (`extrachill_commerce_processing_payer()`):
 *
 * - `account` (the venue pays processing, e.g. Standard accounts, and the
 *   fail-safe when the payer is unknown):
 *       application fee = total − subtotal − processing (est) + seller fee
 *   so the venue nets `total − processing − application fee = subtotal −
 *   seller fee` at the estimate.
 * - `platform` (Extra Chill pays processing, e.g. Express accounts):
 *       application fee = total − subtotal + seller fee
 *   so the venue nets `total − application fee = subtotal − seller fee`
 *   exactly, and Extra Chill pays the real processing out of the service fee.
 *
 * The seller fee is `ticket_percent`, default 0. The estimate rounds up, and
 * the total is nudged up a cent at a time until the account-pays application
 * fee is not negative, so the venue never nets less than that at the
 * estimate. Real processing can differ (international or currency converted
 * cards cost more); the estimate is a setting so it can be set conservatively.
 * When the platform pays, that difference comes out of Extra Chill's share.
 *
 * @param int    $subtotal  Ticket subtotal in minor units (price × quantity), above 0.
 * @param int    $quantity  Tickets in the order, at least 1.
 * @param array  $settings  `ec_commerce_fee_settings()`.
 * @param string $fee_payer Stripe `controller.fees.payer` of the venue's account, or '' when unknown.
 * @return array{buyer_fee:int,application_fee:int} Minor units. `buyer_fee` is the Service fee line.
 */
function extrachill_commerce_ticket_fee( $subtotal, $quantity, array $settings, $fee_payer = '' ) {
	$per_ticket = (int) ( $settings['ticket_buyer_fee_per_ticket_minor'] ?? 0 );
	$per_order  = (int) ( $settings['ticket_buyer_fee_flat_minor'] ?? 0 );
	$fixed      = (int) ( $settings['processing_fixed_minor'] ?? 0 );
	// Basis points as integers keep the gross-up exact.
	$bps        = min( 5000, max( 0, (int) round( (float) ( $settings['processing_percent'] ?? 0 ) * 100 ) ) );
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
	while ( $total - $subtotal - extrachill_commerce_processing_estimate( $total, $settings ) < 0 ) {
		++$total;
	}
	$application = 'account' === extrachill_commerce_processing_payer( $fee_payer )
		? $total - $subtotal - extrachill_commerce_processing_estimate( $total, $settings ) + $seller_fee
		: $total - $subtotal + $seller_fee;
	return array(
		'buyer_fee'       => $total - $subtotal,
		'application_fee' => min( $total, $application ),
	);
}

/**
 * Estimated card processing on a charge (ticket or merch), rounded up.
 *
 * @param int   $total_minor Charge total in minor units.
 * @param array $settings    `ec_commerce_fee_settings()`.
 * @return int Minor units.
 */
function extrachill_commerce_processing_estimate( $total_minor, array $settings ) {
	$bps = min( 5000, max( 0, (int) round( (float) ( $settings['processing_percent'] ?? 0 ) * 100 ) ) );
	return intdiv( (int) $total_minor * $bps + 9999, 10000 ) + (int) ( $settings['processing_fixed_minor'] ?? 0 );
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
 * the full ticket price and buyers pay the service fee. The merch summary
 * says "10% + card processing": merch sellers pay processing on the whole
 * order whichever way it is collected (by the processor from the seller, or
 * inside the application fee when Extra Chill pays it; see
 * `extrachill_commerce_merch_fee()`).
 *
 * @return array{merch_percent: float, ticket_percent: float, ticket_buyer_fee_percent: float, ticket_buyer_fee_flat: float, ticket_buyer_fee_per_ticket: float, processing_percent: float, processing_fixed: float, merch_summary: string, ticket_summary: string}
 */
function extrachill_shop_seller_fee_rates() {
	$settings   = function_exists( 'ec_commerce_fee_settings' ) ? ec_commerce_fee_settings() : array();
	$merch      = (float) ( $settings['merch_percent'] ?? 0 );
	$ticket     = (float) ( $settings['ticket_percent'] ?? 0 );
	$buyer_pc   = (float) ( $settings['ticket_buyer_fee_percent'] ?? 0 );
	$buyer_fl   = round( ( (int) ( $settings['ticket_buyer_fee_flat_minor'] ?? 0 ) ) / 100, 2 );
	$buyer_tk   = round( ( (int) ( $settings['ticket_buyer_fee_per_ticket_minor'] ?? 0 ) ) / 100, 2 );
	$proc_pc    = (float) ( $settings['processing_percent'] ?? 0 );
	$proc_fx    = round( ( (int) ( $settings['processing_fixed_minor'] ?? 0 ) ) / 100, 2 );
	$processing = $proc_pc > 0 || $proc_fx > 0;

	$merch_summary = sprintf(
		/* translators: %s: merch fee percentage. */
		__( 'Merch fee: %s%% of the item price (shipping not included) + card processing on the whole order', 'extrachill-shop' ),
		extrachill_shop_format_fee_percent( $merch )
	);
	if ( $processing ) {
		/* translators: 1: processing percentage, 2: fixed processing fee in dollars. */
		$merch_summary .= ' ' . sprintf( __( '(about %1$s%% + $%2$s)', 'extrachill-shop' ), extrachill_shop_format_fee_percent( $proc_pc ), number_format( $proc_fx, 2 ) );
	}
	$merch_summary .= '.';

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
		'processing_percent'          => $proc_pc,
		'processing_fixed'            => $proc_fx,
		'merch_summary'               => $merch_summary,
		'ticket_summary'              => $ticket_summary,
	);
}
