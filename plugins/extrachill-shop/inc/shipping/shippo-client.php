<?php
/**
 * Shippo API Client
 *
 * Wrapper for Shippo shipping API. Creates shipments and purchases labels.
 *
 * @package ExtraChill\Shop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a shipment and get available rates.
 *
 * @param array $from_address From address array (name, street1, street2, city, state, zip, country).
 * @param array $to_address   To address array (same format).
 * @param array $parcel       Optional. Parcel in Shippo format. Uses the default parcel if not provided.
 * @return array|WP_Error Shipment data with rates or error.
 */
function extrachill_shop_shippo_create_shipment( $from_address, $to_address, $parcel = null ) {
	$api_key = extrachill_shop_get_shippo_api_key();
	if ( empty( $api_key ) ) {
		return new WP_Error( 'shippo_not_configured', 'Shippo API key is not configured.' );
	}

	if ( null === $parcel ) {
		$parcel = extrachill_shop_shippo_parcel( extrachill_shop_get_default_parcel() );
	}

	$body = array(
		'address_from' => array(
			'name'    => $from_address['name'] ?? '',
			'street1' => $from_address['street1'] ?? '',
			'street2' => $from_address['street2'] ?? '',
			'city'    => $from_address['city'] ?? '',
			'state'   => $from_address['state'] ?? '',
			'zip'     => $from_address['zip'] ?? '',
			'country' => $from_address['country'] ?? 'US',
		),
		'address_to'   => array(
			'name'    => $to_address['name'] ?? '',
			'street1' => $to_address['street1'] ?? '',
			'street2' => $to_address['street2'] ?? '',
			'city'    => $to_address['city'] ?? '',
			'state'   => $to_address['state'] ?? '',
			'zip'     => $to_address['zip'] ?? '',
			'country' => $to_address['country'] ?? 'US',
		),
		'parcels'      => array( $parcel ),
		'async'        => false,
	);

	$response = wp_remote_post(
		'https://api.goshippo.com/shipments/',
		array(
			'headers' => array(
				'Authorization' => 'ShippoToken ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status_code >= 400 ) {
		$message = $body['detail'] ?? 'Shippo API error';
		return new WP_Error( 'shippo_api_error', $message, array( 'status' => $status_code ) );
	}

	return $body;
}

/**
 * Get the cheapest USPS rate from a shipment.
 *
 * @param array $shipment Shipment data from Shippo.
 * @return array|null Cheapest USPS rate or null if none found.
 */
function extrachill_shop_shippo_get_cheapest_usps_rate( $shipment ) {
	if ( empty( $shipment['rates'] ) || ! is_array( $shipment['rates'] ) ) {
		return null;
	}

	$usps_rates = array_filter(
		$shipment['rates'],
		function ( $rate ) {
			return 'USPS' === strtoupper( $rate['provider'] ?? '' );
		}
	);

	if ( empty( $usps_rates ) ) {
		return null;
	}

	usort(
		$usps_rates,
		function ( $a, $b ) {
			return floatval( $a['amount'] ) <=> floatval( $b['amount'] );
		}
	);

	return reset( $usps_rates );
}

/**
 * Purchase a shipping label.
 *
 * @param string $rate_id Shippo rate object ID.
 * @return array|WP_Error Transaction data with label URL or error.
 */
function extrachill_shop_shippo_purchase_label( $rate_id ) {
	$api_key = extrachill_shop_get_shippo_api_key();
	if ( empty( $api_key ) ) {
		return new WP_Error( 'shippo_not_configured', 'Shippo API key is not configured.' );
	}

	$body = array(
		'rate'            => $rate_id,
		'label_file_type' => 'PDF',
		'async'           => false,
	);

	$response = wp_remote_post(
		'https://api.goshippo.com/transactions/',
		array(
			'headers' => array(
				'Authorization' => 'ShippoToken ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status_code >= 400 ) {
		$message = $body['detail'] ?? 'Shippo API error';
		return new WP_Error( 'shippo_api_error', $message, array( 'status' => $status_code ) );
	}

	if ( 'SUCCESS' !== ( $body['status'] ?? '' ) ) {
		$messages = $body['messages'] ?? array();
		$message  = ! empty( $messages ) ? implode( ', ', array_column( $messages, 'text' ) ) : 'Label creation failed';
		return new WP_Error( 'shippo_label_failed', $message );
	}

	return $body;
}

/**
 * Quote the cheapest USPS label for a parcel without buying it.
 *
 * @param array      $from_address Seller's shipping address.
 * @param array      $to_address   Customer's shipping address.
 * @param array|null $parcel       Parcel in Shippo format (`extrachill_shop_shippo_parcel()`).
 * @return array|WP_Error { rate_id, cost, carrier, service } or error.
 */
function extrachill_shop_shippo_quote_label( $from_address, $to_address, $parcel = null ) {
	$shipment = extrachill_shop_shippo_create_shipment( $from_address, $to_address, $parcel );
	if ( is_wp_error( $shipment ) ) {
		return $shipment;
	}

	$rate = extrachill_shop_shippo_get_cheapest_usps_rate( $shipment );
	if ( ! $rate ) {
		return new WP_Error( 'no_usps_rates', 'No USPS shipping rates available for this destination.' );
	}

	return array(
		'rate_id' => (string) ( $rate['object_id'] ?? '' ),
		'cost'    => floatval( $rate['amount'] ?? 0 ),
		'carrier' => (string) ( $rate['provider'] ?? 'USPS' ),
		'service' => (string) ( $rate['servicelevel']['name'] ?? $rate['servicelevel_name'] ?? '' ),
	);
}

/**
 * Buy the label for a quoted rate.
 *
 * @param array $quote Quote from `extrachill_shop_shippo_quote_label()`.
 * @return array|WP_Error Label data or error.
 */
function extrachill_shop_shippo_buy_label( array $quote ) {
	$transaction = extrachill_shop_shippo_purchase_label( $quote['rate_id'] );
	if ( is_wp_error( $transaction ) ) {
		return $transaction;
	}

	return array(
		'tracking_number' => $transaction['tracking_number'] ?? '',
		'label_url'       => $transaction['label_url'] ?? '',
		'tracking_url'    => $transaction['tracking_url_provider'] ?? '',
		'carrier'         => $quote['carrier'],
		'service'         => $quote['service'],
		'cost'            => (float) $quote['cost'],
		'rate_id'         => $quote['rate_id'],
		'transaction_id'  => $transaction['object_id'] ?? '',
	);
}
