<?php
/**
 * Commission Settings
 *
 * Manages platform commission rates for artist product sales. Provides default
 * rate configuration and per-product override capability.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default commission rate (platform fee).
 *
 * 0.10 = 10% to platform, 90% to artist.
 */
define( 'EXTRACHILL_SHOP_DEFAULT_COMMISSION_RATE', 0.10 );

/**
 * Get the default platform commission rate.
 *
 * @return float Commission rate as decimal (0.10 = 10%).
 */
function extrachill_shop_get_default_commission_rate() {
	return (float) get_option( 'extrachill_shop_commission_rate', EXTRACHILL_SHOP_DEFAULT_COMMISSION_RATE );
}

/**
 * Get the commission rate for a specific product.
 *
 * Checks for product-specific override, falls back to default rate.
 *
 * @param int $product_id WooCommerce product ID.
 * @return float Commission rate as decimal.
 */
function extrachill_shop_get_product_commission_rate( $product_id ) {
	$product_rate = get_post_meta( $product_id, '_artist_commission_rate', true );

	if ( '' !== $product_rate && is_numeric( $product_rate ) ) {
		return (float) $product_rate;
	}

	return extrachill_shop_get_default_commission_rate();
}

/**
 * Calculate platform commission amount for a given price.
 *
 * @param float $price Product price.
 * @param int   $product_id Optional. Product ID for specific rate lookup.
 * @return float Commission amount.
 */
function extrachill_shop_calculate_commission( $price, $product_id = null ) {
	$rate = $product_id
		? extrachill_shop_get_product_commission_rate( $product_id )
		: extrachill_shop_get_default_commission_rate();

	return round( $price * $rate, 2 );
}

/**
 * Calculate artist payout amount for a given price.
 *
 * @param float $price Product price.
 * @param int   $product_id Optional. Product ID for specific rate lookup.
 * @return float Artist payout amount.
 */
function extrachill_shop_calculate_artist_payout( $price, $product_id = null ) {
	$commission = extrachill_shop_calculate_commission( $price, $product_id );
	return round( $price - $commission, 2 );
}

/**
 * Get commission rate as percentage for display.
 *
 * @param int $product_id Optional. Product ID for specific rate.
 * @return string Formatted percentage (e.g., "10%").
 */
function extrachill_shop_get_commission_rate_display( $product_id = null ) {
	$rate = $product_id
		? extrachill_shop_get_product_commission_rate( $product_id )
		: extrachill_shop_get_default_commission_rate();

	return round( $rate * 100 ) . '%';
}

/**
 * Get artist share as percentage for display.
 *
 * @param int $product_id Optional. Product ID for specific rate.
 * @return string Formatted percentage (e.g., "90%").
 */
function extrachill_shop_get_artist_share_display( $product_id = null ) {
	$rate = $product_id
		? extrachill_shop_get_product_commission_rate( $product_id )
		: extrachill_shop_get_default_commission_rate();

	return round( ( 1 - $rate ) * 100 ) . '%';
}

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
 * Add commission settings fields.
 *
 * @param array  $settings Existing settings.
 * @param string $current_section Current section ID.
 * @return array Modified settings.
 */
function extrachill_shop_add_settings_fields( $settings, $current_section ) {
	if ( 'extrachill' !== $current_section ) {
		return $settings;
	}

	$extrachill_settings = array(
		array(
			'title' => __( 'Artist Marketplace Settings', 'extrachill-shop' ),
			'type'  => 'title',
			'id'    => 'extrachill_marketplace_settings',
		),
		array(
			'title'             => __( 'Platform Commission Rate', 'extrachill-shop' ),
			'desc'              => __( 'Percentage of each sale that goes to Extra Chill. Artists receive the remainder.', 'extrachill-shop' ),
			'id'                => 'extrachill_shop_commission_rate',
			'type'              => 'number',
			'default'           => EXTRACHILL_SHOP_DEFAULT_COMMISSION_RATE * 100,
			'css'               => 'width: 80px;',
			'desc_tip'          => true,
			'custom_attributes' => array(
				'min'  => '0',
				'max'  => '100',
				'step' => '0.5',
			),
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

	return $extrachill_settings;
}
add_filter( 'woocommerce_get_settings_products', 'extrachill_shop_add_settings_fields', 10, 2 );

/**
 * Convert percentage input to decimal on save.
 *
 * @param mixed  $value Setting value.
 * @param array  $option Option config.
 * @param mixed  $raw_value Raw input value.
 * @return mixed Processed value.
 */
function extrachill_shop_process_commission_rate( $value, $option, $raw_value ) {
	if ( 'extrachill_shop_commission_rate' !== $option['id'] ) {
		return $value;
	}

	$percentage = floatval( $raw_value );
	$percentage = max( 0, min( 100, $percentage ) );

	return $percentage / 100;
}
add_filter( 'woocommerce_admin_settings_sanitize_option', 'extrachill_shop_process_commission_rate', 10, 3 );

/**
 * Display commission rate as percentage in settings field.
 *
 * @param mixed  $value Option value.
 * @param array  $option Option config.
 * @return mixed Display value.
 */
function extrachill_shop_display_commission_rate( $value, $option ) {
	if ( 'extrachill_shop_commission_rate' !== $option['id'] ) {
		return $value;
	}

	if ( is_numeric( $value ) && $value <= 1 ) {
		return $value * 100;
	}

	return $value;
}
add_filter( 'woocommerce_admin_settings_sanitize_option_extrachill_shop_commission_rate', 'extrachill_shop_display_commission_rate', 10, 2 );

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
