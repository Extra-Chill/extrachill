<?php
/**
 * Shipping Labels REST API Endpoints
 *
 * Thin adapters over the extrachill-shop shipping-label abilities, which own
 * the Shippo call, order writes, status transition and email. Any seller type
 * is named with `seller_type` + `seller_id`.
 *
 * Routes:
 * - POST /wp-json/extrachill/v1/shop/shipping-labels             extrachill/shop-create-shipping-label
 * - GET  /wp-json/extrachill/v1/shop/shipping-labels/{order_id}  extrachill/shop-get-shipping-label
 *
 * @package ExtraChillAPI
 * @since 0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shipping_labels_routes' );

/**
 * Register shipping labels REST routes.
 */
function extrachill_api_register_shipping_labels_routes() {
	$args = array_merge(
		array(
			'order_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
		extrachill_api_shop_seller_args()
	);

	extrachill_api_shop_register_ability_route(
		'/shop/shipping-labels',
		array(
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'ability' => 'extrachill/shop-create-shipping-label',
				'params'  => array( 'order_id' ),
				'args'    => $args,
			),
		)
	);

	extrachill_api_shop_register_ability_route(
		'/shop/shipping-labels/(?P<order_id>\d+)',
		array(
			array(
				'methods' => WP_REST_Server::READABLE,
				'ability' => 'extrachill/shop-get-shipping-label',
				'params'  => array( 'order_id' ),
				'args'    => $args,
			),
		)
	);
}
