<?php
/**
 * Shipping Settings
 *
 * Shippo API key retrieval and shipping constants. Label parcels live in
 * label-parcel.php.
 *
 * @package ExtraChill\Shop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the Shippo API key.
 *
 * Read from the network commerce credential store (ec_commerce_credential()).
 *
 * @return string API key or empty string if not configured.
 */
function extrachill_shop_get_shippo_api_key() {
	return ec_commerce_credential( 'shippo', 'api_key' );
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
