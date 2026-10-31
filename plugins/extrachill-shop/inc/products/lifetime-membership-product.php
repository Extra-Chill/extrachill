<?php
/**
 * Lifetime Membership Product Provisioning
 *
 * Ensures the Lifetime Extra Chill Membership product always exists on the shop site.
 * Uses a fixed SKU as the canonical identifier and caches the product ID in an option.
 *
 * Thin config caller — the shared provisioning logic lives in
 * inc/products/product-provisioning.php.
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

/**
 * Provisioning config for the lifetime membership product.
 *
 * @return array
 */
function extrachill_shop_get_lifetime_membership_product_config() {
	return array(
		'sku'                   => extrachill_shop_get_lifetime_membership_sku(),
		'name'                  => 'Lifetime Extra Chill Membership',
		'price'                 => '20',
		'sold_individually'     => true,
		'short_description'      => 'Support independent music journalism and enjoy an ad-free experience across the entire Extra Chill network. One-time payment, lifetime status.',
		'option_key'            => extrachill_shop_get_lifetime_membership_product_option_key(),
		'sync_flag_key'         => extrachill_shop_get_lifetime_membership_product_sync_flag_key(),
		'sync_transient_key'    => extrachill_shop_get_lifetime_membership_product_sync_transient_key(),
		'invalid_product_error' => 'Lifetime membership product ID is invalid.',
		'save_failed_error'     => 'Failed to save lifetime membership product.',
	);
}

function extrachill_shop_get_lifetime_membership_product_id() {
	return extrachill_shop_provision_get_product_id( extrachill_shop_get_lifetime_membership_product_config() );
}

function extrachill_shop_maybe_sync_lifetime_membership_product() {
	extrachill_shop_provision_maybe_sync_product( extrachill_shop_get_lifetime_membership_product_config() );
}

function extrachill_shop_ensure_lifetime_membership_product() {
	return extrachill_shop_provision_ensure_product( extrachill_shop_get_lifetime_membership_product_config() );
}
