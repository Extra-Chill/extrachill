<?php
/**
 * Commerce fee settings (network layer).
 *
 * One network option, `extrachill_commerce_fees`, holds every platform fee
 * rate. The network Payments page writes it; the shop's
 * `extrachill_commerce_fee()` reads it through `ec_commerce_fee_settings()`.
 * It lives here, in the network-active plugin, because the Payments page runs
 * in network admin where the shop plugin is not loaded.
 *
 * Until the page is first saved, the values fall back to the two stores this
 * replaces: the shop's `extrachill_shop_commission_rate` (blog option) and the
 * network `extrachill_ticket_fees` array. Nothing is written on read. Keys
 * those stores never had (the per-ticket service fee and the card processing
 * estimate) use the shipped defaults in `ec_commerce_fee_new_defaults()`.
 *
 * @package ExtraChillNetwork
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fee setting keys mapped to whether the value is a percentage (true) or a
 * flat amount in minor units (false).
 *
 * @return array<string,bool>
 */
function ec_commerce_fee_fields() {
	return array(
		'merch_percent'                     => true,
		'ticket_percent'                    => true,
		'ticket_buyer_fee_per_ticket_minor' => false,
		'ticket_buyer_fee_flat_minor'       => false,
		'ticket_buyer_fee_percent'          => true,
		'ticket_processing_percent'         => true,
		'ticket_processing_fixed_minor'     => false,
	);
}

/**
 * Highest allowed value per percentage key, when lower than 100.
 *
 * The processing percentage divides the buyer total by (1 - rate), so it must
 * stay well below 100.
 *
 * @return array<string,float>
 */
function ec_commerce_fee_percent_caps() {
	return array( 'ticket_processing_percent' => 50.0 );
}

/**
 * Shipped defaults for keys the legacy stores never had.
 *
 * - `ticket_buyer_fee_per_ticket_minor`: $1 per ticket, paid by the buyer.
 * - `ticket_processing_percent` / `ticket_processing_fixed_minor`: the card
 *   processing estimate (2.9% + 30¢) the ticket service fee is grossed up by,
 *   so the venue nets the full ticket subtotal.
 *
 * @return array<string,float|int>
 */
function ec_commerce_fee_new_defaults() {
	return array(
		'ticket_buyer_fee_per_ticket_minor' => 100,
		'ticket_processing_percent'         => 2.9,
		'ticket_processing_fixed_minor'     => 30,
	);
}

/**
 * Fee defaults, read from the stores this setting replaces.
 *
 * @return array<string,float|int>
 */
function ec_commerce_fee_defaults() {
	$rate = get_blog_option( (int) ec_get_blog_id( 'shop' ), 'extrachill_shop_commission_rate', '10' );
	// The shop stored either a percentage ('10') or a decimal (0.1).
	$merch  = is_numeric( $rate ) ? ( (float) $rate <= 1 ? (float) $rate * 100 : (float) $rate ) : 10.0;
	$ticket = get_site_option( 'extrachill_ticket_fees', array() );
	$ticket = is_array( $ticket ) ? $ticket : array();

	return ec_commerce_fee_sanitize(
		array(
			'merch_percent'               => $merch,
			'ticket_percent'              => $ticket['seller_fee_percent'] ?? 0,
			'ticket_buyer_fee_flat_minor' => $ticket['buyer_fee_flat_minor'] ?? 0,
			'ticket_buyer_fee_percent'    => $ticket['buyer_fee_percent'] ?? 0,
		) + ec_commerce_fee_new_defaults(),
		array_fill_keys( array_keys( ec_commerce_fee_fields() ), 0 )
	);
}

/**
 * Clamp fee values: percentages to 0-100 (or a lower cap from
 * `ec_commerce_fee_percent_caps()`), flat amounts to whole minor units of at
 * least 0. A missing or non-numeric value keeps its fallback.
 *
 * @param array $values   Submitted or stored values.
 * @param array $fallback Values used for missing or invalid keys.
 * @return array<string,float|int>
 */
function ec_commerce_fee_sanitize( array $values, array $fallback ) {
	$clean = array();
	$caps  = ec_commerce_fee_percent_caps();
	foreach ( ec_commerce_fee_fields() as $key => $is_percent ) {
		$value         = isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (float) $values[ $key ] : (float) ( $fallback[ $key ] ?? 0 );
		$value         = max( 0.0, $value );
		$clean[ $key ] = $is_percent ? min( $caps[ $key ] ?? 100.0, $value ) : (int) round( $value );
	}
	return $clean;
}

/**
 * Current fee settings: the saved option over the legacy defaults.
 *
 * @return array<string,float|int> Keyed by `ec_commerce_fee_fields()`.
 */
function ec_commerce_fee_settings() {
	$stored = get_site_option( 'extrachill_commerce_fees', array() );
	return ec_commerce_fee_sanitize( is_array( $stored ) ? $stored : array(), ec_commerce_fee_defaults() );
}
