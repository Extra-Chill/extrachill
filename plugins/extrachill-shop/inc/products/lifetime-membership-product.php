<?php
/**
 * Lifetime Membership Product Provisioning
 *
 * Ensures the Lifetime Extra Chill Membership product always exists on the shop site.
 * Uses a fixed SKU as the canonical identifier and caches the product ID in an option.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'extrachill_shop_maybe_sync_lifetime_membership_product', 20 );

function extrachill_shop_get_lifetime_membership_sku() {
	return 'ec-lifetime-membership';
}

function extrachill_shop_get_lifetime_membership_product_option_key() {
	return 'extrachill_shop_lifetime_membership_product_id';
}

function extrachill_shop_get_lifetime_membership_product_sync_flag_key() {
	return 'extrachill_shop_needs_lifetime_membership_product_sync';
}

function extrachill_shop_get_lifetime_membership_product_sync_transient_key() {
	return 'extrachill_shop_lifetime_membership_product_sync_daily';
}

function extrachill_shop_get_lifetime_membership_product_id() {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return 0;
	}

	$option_key = extrachill_shop_get_lifetime_membership_product_option_key();
	$stored_id  = absint( get_option( $option_key ) );
	$sku        = extrachill_shop_get_lifetime_membership_sku();

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

function extrachill_shop_maybe_sync_lifetime_membership_product() {
	if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Simple' ) ) {
		return;
	}

	$sync_flag_key = extrachill_shop_get_lifetime_membership_product_sync_flag_key();
	$needs_sync    = (bool) get_option( $sync_flag_key );

	if ( $needs_sync ) {
		extrachill_shop_ensure_lifetime_membership_product();
		delete_option( $sync_flag_key );
		delete_transient( extrachill_shop_get_lifetime_membership_product_sync_transient_key() );
		return;
	}

	if ( get_transient( extrachill_shop_get_lifetime_membership_product_sync_transient_key() ) ) {
		return;
	}

	extrachill_shop_ensure_lifetime_membership_product();
	set_transient( extrachill_shop_get_lifetime_membership_product_sync_transient_key(), 1, DAY_IN_SECONDS );
}

function extrachill_shop_ensure_lifetime_membership_product() {
	$sku        = extrachill_shop_get_lifetime_membership_sku();
	$product_id = absint( wc_get_product_id_by_sku( $sku ) );

	if ( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', 'Lifetime membership product ID is invalid.' );
		}
	} else {
		$product = new WC_Product_Simple();
		$product->set_name( 'Lifetime Extra Chill Membership' );
		$product->set_sku( $sku );
	}

	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_virtual( true );
	$product->set_sold_individually( true );
	$product->set_regular_price( '20' );
	$product->set_tax_status( 'none' );
	$product->set_short_description( 'Support independent music journalism and enjoy an ad-free experience across the entire Extra Chill network. One-time payment, lifetime status.' );

	$saved_product_id = absint( $product->save() );
	if ( ! $saved_product_id ) {
		return new WP_Error( 'save_failed', 'Failed to save lifetime membership product.' );
	}

	update_option( extrachill_shop_get_lifetime_membership_product_option_key(), $saved_product_id );

	// Link product to platform artist for shop manager visibility.
	if ( defined( 'EC_PLATFORM_ARTIST_ID' ) && function_exists( 'extrachill_shop_set_product_artist' ) ) {
		extrachill_shop_set_product_artist( $saved_product_id, EC_PLATFORM_ARTIST_ID );
	}

	return $saved_product_id;
}
