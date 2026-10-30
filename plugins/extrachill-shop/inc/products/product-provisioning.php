<?php
/**
 * Shared Product Provisioning
 *
 * Generic, parameterized provisioner for shop products that are pinned to a
 * fixed SKU and cached via an option. Both the lifetime-membership and
 * priority-boost products delegate to these helpers with their own config.
 *
 * A config array has the following shape:
 *   - sku                 (string) Canonical SKU identifier.
 *   - name                (string) Product name (used on first creation).
 *   - price               (string) Regular price.
 *   - sold_individually   (bool)   Whether the product is sold individually.
 *   - short_description    (string) Product short description.
 *   - option_key          (string) Option storing the resolved product ID.
 *   - sync_flag_key        (string) Option flagging an immediate sync.
 *   - sync_transient_key   (string) Transient throttling the daily sync.
 *   - invalid_product_error (string) Message for an invalid stored product ID.
 *   - save_failed_error     (string) Message when the product fails to save.
 *
 * @package ExtraChillShop
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the product ID for a given provisioning config.
 *
 * @param array $config Provisioning config.
 * @return int Product ID, or 0 if WooCommerce is unavailable or the product
 *             does not yet exist.
 */
function extrachill_shop_provision_get_product_id( array $config ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return 0;
	}

	$option_key = $config['option_key'];
	$stored_id  = absint( get_option( $option_key ) );
	$sku        = $config['sku'];

	if ( $stored_id ) {
		$product = wc_get_product( $stored_id );
		if ( $product && $product->get_sku() === $sku ) {
			return $stored_id;
		}
	}

	$product_id = absint( wc_get_product_id_by_sku( $sku ) );
	if ( $product_id ) {
		update_option( $option_key, $product_id );
		return $product_id;
	}

	return 0;
}

/**
 * Conditionally sync the product described by a config.
 *
 * Honors the immediate sync flag, then throttles to once per day via a
 * transient.
 *
 * @param array $config Provisioning config.
 * @return void
 */
function extrachill_shop_provision_maybe_sync_product( array $config ) {
	if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Simple' ) ) {
		return;
	}

	$sync_flag_key      = $config['sync_flag_key'];
	$sync_transient_key = $config['sync_transient_key'];
	$needs_sync         = (bool) get_option( $sync_flag_key );

	if ( $needs_sync ) {
		extrachill_shop_provision_ensure_product( $config );
		delete_option( $sync_flag_key );
		delete_transient( $sync_transient_key );
		return;
	}

	if ( get_transient( $sync_transient_key ) ) {
		return;
	}

	extrachill_shop_provision_ensure_product( $config );
	set_transient( $sync_transient_key, 1, DAY_IN_SECONDS );
}

/**
 * Ensure the product described by a config exists and is up to date.
 *
 * @param array $config Provisioning config.
 * @return int|WP_Error Saved product ID, or WP_Error on failure.
 */
function extrachill_shop_provision_ensure_product( array $config ) {
	$sku        = $config['sku'];
	$product_id = absint( wc_get_product_id_by_sku( $sku ) );

	if ( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', $config['invalid_product_error'] );
		}
	} else {
		$product = new WC_Product_Simple();
		$product->set_name( $config['name'] );
		$product->set_sku( $sku );
	}

	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_virtual( true );
	$product->set_sold_individually( $config['sold_individually'] );
	$product->set_regular_price( $config['price'] );
	$product->set_tax_status( 'none' );
	$product->set_short_description( $config['short_description'] );

	$saved_product_id = absint( $product->save() );
	if ( ! $saved_product_id ) {
		return new WP_Error( 'save_failed', $config['save_failed_error'] );
	}

	update_option( $config['option_key'], $saved_product_id );

	// Link product to platform artist for shop manager visibility.
	if ( defined( 'EC_PLATFORM_ARTIST_ID' ) && function_exists( 'extrachill_shop_set_product_artist' ) ) {
		extrachill_shop_set_product_artist( $saved_product_id, EC_PLATFORM_ARTIST_ID );
	}

	return $saved_product_id;
}
