<?php
/**
 * Priority Boost Product Provisioning
 *
 * Ensures the Event Priority Boost product always exists on the shop site.
 * Uses a fixed SKU as the canonical identifier and caches the product ID in an option.
 *
 * @package ExtraChillShop
 * @since 0.6.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'extrachill_shop_maybe_sync_priority_boost_product', 20 );

function extrachill_shop_get_priority_boost_sku() {
	return 'extrachill-event-priority-boost';
}

function extrachill_shop_get_priority_boost_product_option_key() {
	return 'extrachill_shop_priority_boost_product_id';
}

function extrachill_shop_get_priority_boost_product_sync_flag_key() {
	return 'extrachill_shop_needs_priority_boost_product_sync';
}

function extrachill_shop_get_priority_boost_product_sync_transient_key() {
	return 'extrachill_shop_priority_boost_product_sync_daily';
}

function extrachill_shop_get_priority_boost_product_id() {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return 0;
	}

	$option_key = extrachill_shop_get_priority_boost_product_option_key();
	$stored_id  = absint( get_option( $option_key ) );
	$sku        = extrachill_shop_get_priority_boost_sku();

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

function extrachill_shop_maybe_sync_priority_boost_product() {
	if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Simple' ) ) {
		return;
	}

	$sync_flag_key = extrachill_shop_get_priority_boost_product_sync_flag_key();
	$needs_sync    = (bool) get_option( $sync_flag_key );

	if ( $needs_sync ) {
		extrachill_shop_ensure_priority_boost_product();
		delete_option( $sync_flag_key );
		delete_transient( extrachill_shop_get_priority_boost_product_sync_transient_key() );
		return;
	}

	if ( get_transient( extrachill_shop_get_priority_boost_product_sync_transient_key() ) ) {
		return;
	}

	extrachill_shop_ensure_priority_boost_product();
	set_transient( extrachill_shop_get_priority_boost_product_sync_transient_key(), 1, DAY_IN_SECONDS );
}

function extrachill_shop_ensure_priority_boost_product() {
	$sku        = extrachill_shop_get_priority_boost_sku();
	$product_id = absint( wc_get_product_id_by_sku( $sku ) );

	if ( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', 'Priority boost product ID is invalid.' );
		}
	} else {
		$product = new WC_Product_Simple();
		$product->set_name( 'Event Priority Boost' );
		$product->set_sku( $sku );
	}

	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_virtual( true );
	$product->set_sold_individually( false );
	$product->set_regular_price( '5' );
	$product->set_tax_status( 'none' );
	$product->set_short_description( 'Boost an event to appear first in the calendar for its date.' );

	$saved_product_id = absint( $product->save() );
	if ( ! $saved_product_id ) {
		return new WP_Error( 'save_failed', 'Failed to save priority boost product.' );
	}

	update_option( extrachill_shop_get_priority_boost_product_option_key(), $saved_product_id );

	if ( defined( 'EC_PLATFORM_ARTIST_ID' ) && function_exists( 'extrachill_shop_set_product_artist' ) ) {
		extrachill_shop_set_product_artist( $saved_product_id, EC_PLATFORM_ARTIST_ID );
	}

	return $saved_product_id;
}
