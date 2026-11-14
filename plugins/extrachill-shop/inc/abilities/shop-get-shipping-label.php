<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-get-shipping-label
 *
 * Retrieve a seller's existing shipping label for an order.
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
			'label'               => __( 'Get Shipping Label', 'extrachill-shop' ),
			'description'         => __( 'Retrieve a seller\'s existing shipping label for an order.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array(
						'type'        => 'integer',
						'description' => 'Order ID.',
					),
					'seller'   => extrachill_shop_seller_input_schema(),
				),
				'required'   => array( 'order_id', 'seller' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'order_id'        => array( 'type' => 'integer' ),
					'seller'          => extrachill_shop_seller_input_schema(),
					'has_label'       => array( 'type' => 'boolean' ),
					'label_url'       => array( 'type' => 'string' ),
					'tracking_number' => array( 'type' => 'string' ),
					'tracking_url'    => array( 'type' => 'string' ),
					'carrier'         => array( 'type' => 'string' ),
					'service'         => array( 'type' => 'string' ),
					'cost'            => array( 'type' => 'number' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_get_shipping_label',
			'permission_callback' => 'extrachill_shop_authorize_seller_input',
			'meta'                => array(
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
 * Get a seller's shipping label for an order.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_get_shipping_label( array $input ): array|WP_Error {
	$order_id = (int) ( $input['order_id'] ?? 0 );
	$seller   = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || ! extrachill_shop_order_has_seller( $order, $seller ) ) {
		return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}

	$label = extrachill_shop_get_order_shipment( $order );
	return array_merge(
		array(
			'order_id'  => $order_id,
			'seller'    => $seller,
			'has_label' => '' !== $label['label_url'],
		),
		$label
	);
}
