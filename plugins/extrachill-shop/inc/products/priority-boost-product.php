<?php
/**
 * Priority Boost Product Provisioning
 *
 * Ensures the Event Priority Boost product always exists on the shop site.
 * Uses a fixed SKU as the canonical identifier and caches the product ID in an option.
 *
 * Thin config caller — the shared provisioning logic lives in
 * inc/products/product-provisioning.php.
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

/**
 * Provisioning config for the priority boost product.
 *
 * @return array
 */
function extrachill_shop_get_priority_boost_product_config() {
	return array(
		'sku'                   => extrachill_shop_get_priority_boost_sku(),
		'name'                  => 'Event Priority Boost',
		'price'                 => '5',
		'sold_individually'     => false,
		'short_description'      => 'Boost an event to appear first in the calendar for its date.',
		'option_key'            => extrachill_shop_get_priority_boost_product_option_key(),
		'sync_flag_key'         => extrachill_shop_get_priority_boost_product_sync_flag_key(),
		'sync_transient_key'    => extrachill_shop_get_priority_boost_product_sync_transient_key(),
		'invalid_product_error' => 'Priority boost product ID is invalid.',
		'save_failed_error'     => 'Failed to save priority boost product.',
	);
}

function extrachill_shop_get_priority_boost_product_id() {
	return extrachill_shop_provision_get_product_id( extrachill_shop_get_priority_boost_product_config() );
}

function extrachill_shop_maybe_sync_priority_boost_product() {
	extrachill_shop_provision_maybe_sync_product( extrachill_shop_get_priority_boost_product_config() );
}

function extrachill_shop_ensure_priority_boost_product() {
	return extrachill_shop_provision_ensure_product( extrachill_shop_get_priority_boost_product_config() );
}
