<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-get-shipping-label
 *
 * Retrieve an existing shipping label for a specific order and artist.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_get_shipping_label_ability' );

/**
 * Register the shop-get-shipping-label ability.
 */
function extrachill_shop_register_get_shipping_label_ability(): void {

	wp_register_ability(
		'extrachill/shop-get-shipping-label',
		array(
			'label'       => __( 'Get Shipping Label', 'extrachill-shop' ),
			'description' => __( 'Retrieve an existing shipping label for a specific order.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'order_id'  => array(
						'type'        => 'integer',
						'description' => 'Order ID.',
					),
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
				),
				'required' => array( 'order_id', 'artist_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'order_id'        => array( 'type' => 'integer' ),
					'artist_id'       => array( 'type' => 'integer' ),
					'has_label'       => array( 'type' => 'boolean' ),
					'label_url'       => array( 'type' => 'string' ),
					'tracking_number' => array( 'type' => 'string' ),
					'carrier'         => array( 'type' => 'string' ),
					'service'         => array( 'type' => 'string' ),
					'cost'            => array( 'type' => 'number' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_get_shipping_label',
			'permission_callback' => static function ( array $input ): bool|WP_Error {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
				}
				$artist_id = isset( $input['artist_id'] ) ? (int) $input['artist_id'] : 0;
				if ( ! $artist_id ) {
					return new WP_Error( 'missing_artist_id', 'Artist ID is required.', array( 'status' => 400 ) );
				}
				if ( function_exists( 'extrachill_api_shop_user_can_manage_artist' ) ) {
					if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
						return new WP_Error( 'rest_forbidden', 'You do not have permission to manage this artist.', array( 'status' => 403 ) );
					}
					return true;
				}
				if ( function_exists( 'ec_can_manage_artist' ) ) {
					return ec_can_manage_artist( get_current_user_id(), $artist_id );
				}
				return current_user_can( 'manage_options' );
			},
			'meta' => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Get shipping label for a specific order and artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_get_shipping_label( array $input ): array|WP_Error {
	$order_id  = (int) ( $input['order_id'] ?? 0 );
	$artist_id = (int) ( $input['artist_id'] ?? 0 );

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}

	$label_url       = $order->get_meta( '_artist_label_' . $artist_id ) ?: '';
	$tracking_number = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';
	$label_data      = $order->get_meta( '_artist_label_data_' . $artist_id ) ?: array();

	return array(
		'order_id'        => $order_id,
		'artist_id'       => $artist_id,
		'has_label'       => ! empty( $label_url ),
		'label_url'       => $label_url,
		'tracking_number' => $tracking_number,
		'carrier'         => $label_data['carrier'] ?? '',
		'service'         => $label_data['service'] ?? '',
		'cost'            => $label_data['cost'] ?? 0,
	);
}
