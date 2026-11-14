<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-list-shipping-labels
 *
 * List shipping labels across a seller's orders.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_list_shipping_labels_ability' );

/**
 * Register the shop-list-shipping-labels ability.
 */
function extrachill_shop_register_list_shipping_labels_ability(): void {

	wp_register_ability(
		'extrachill/shop-list-shipping-labels',
		array(
			'label'               => __( 'List Shipping Labels', 'extrachill-shop' ),
			'description'         => __( 'List all shipping labels purchased for a seller across their orders.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'seller' => extrachill_shop_seller_input_schema(),
				),
				'required'   => array( 'seller' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'labels' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_list_shipping_labels',
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
 * List a seller's shipping labels.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_list_shipping_labels( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$orders = wc_get_orders(
		extrachill_shop_scope_order_query_to_seller(
			array(
				'limit'   => -1,
				'status'  => array( 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-on-hold' ),
				'orderby' => 'date',
				'order'   => 'DESC',
			),
			$seller
		)
	);

	$labels = array();
	foreach ( $orders as $order ) {
		$label = extrachill_shop_get_order_shipment( $order );
		if ( '' === $label['label_url'] ) {
			continue;
		}
		$labels[] = array_merge(
			array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'seller'       => $seller,
			),
			$label
		);
	}

	return array( 'labels' => $labels );
}
