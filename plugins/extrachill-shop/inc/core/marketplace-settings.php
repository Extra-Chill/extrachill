<?php
/**
 * Marketplace Settings
 *
 * Shop-site marketplace options under WooCommerce > Settings > Products >
 * Extra Chill. Fee rates are network settings (see `extrachill_commerce_fee()`).
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add Extra Chill settings section to WooCommerce settings.
 *
 * @param array $sections Existing sections.
 * @return array Modified sections.
 */
function extrachill_shop_add_settings_section( $sections ) {
	$sections['extrachill'] = __( 'Extra Chill', 'extrachill-shop' );
	return $sections;
}
add_filter( 'woocommerce_get_sections_products', 'extrachill_shop_add_settings_section' );

/**
 * Add marketplace settings fields.
 *
 * @param array  $settings Existing settings.
 * @param string $current_section Current section ID.
 * @return array Modified settings.
 */
function extrachill_shop_add_settings_fields( $settings, $current_section ) {
	if ( 'extrachill' !== $current_section ) {
		return $settings;
	}

	return array(
		array(
			'title' => __( 'Artist Marketplace Settings', 'extrachill-shop' ),
			'type'  => 'title',
			'desc'  => __( 'Fee rates are set network-wide on Network Admin > Extra Chill > Payments.', 'extrachill-shop' ),
			'id'    => 'extrachill_marketplace_settings',
		),
		array(
			'title'   => __( 'Product Approval Required', 'extrachill-shop' ),
			'desc'    => __( 'Require admin approval before artist products go live.', 'extrachill-shop' ),
			'id'      => 'extrachill_shop_require_approval',
			'type'    => 'checkbox',
			'default' => 'yes',
		),
		array(
			'type' => 'sectionend',
			'id'   => 'extrachill_marketplace_settings',
		),
	);
}
add_filter( 'woocommerce_get_settings_products', 'extrachill_shop_add_settings_fields', 10, 2 );

/**
 * Check if product approval is required.
 *
 * @return bool True if approval required.
 */
function extrachill_shop_requires_product_approval() {
	return 'yes' === get_option( 'extrachill_shop_require_approval', 'yes' );
}

/**
 * Get the default post status for new artist products.
 *
 * @return string Post status ('pending' or 'publish').
 */
function extrachill_shop_get_new_product_status() {
	return extrachill_shop_requires_product_approval() ? 'pending' : 'publish';
}
