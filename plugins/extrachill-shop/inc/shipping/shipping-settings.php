<?php
/**
 * Shipping Settings
 *
 * Default parcel configuration and Shippo API key retrieval.
 *
 * @package ExtraChill\Shop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the Shippo API key from network options.
 *
 * @return string API key or empty string if not configured.
 */
function extrachill_shop_get_shippo_api_key() {
	return get_site_option( 'extrachill_shippo_api_key', '' );
}

/**
 * Check if Shippo is configured.
 *
 * @return bool True if API key is set.
 */
function extrachill_shop_is_shippo_configured() {
	return ! empty( extrachill_shop_get_shippo_api_key() );
}

/**
 * Get default parcel dimensions for shipping.
 *
 * Standard size for most merch items (t-shirts, small goods).
 *
 * @return array Parcel dimensions in Shippo format.
 */
function extrachill_shop_get_default_parcel() {
	return array(
		'length'        => '10',
		'width'         => '8',
		'height'        => '4',
		'distance_unit' => 'in',
		'weight'        => '1',
		'mass_unit'     => 'lb',
	);
}

/**
 * Get flat shipping rate per artist.
 *
 * @return float Rate in dollars.
 */
function extrachill_shop_get_flat_rate_per_artist() {
	return 5.00;
}

/**
 * Get supported carrier for shipping.
 *
 * MVP: USPS only.
 *
 * @return string Carrier identifier.
 */
function extrachill_shop_get_shipping_carrier() {
	return 'usps';
}
