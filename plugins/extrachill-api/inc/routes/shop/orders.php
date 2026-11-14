<?php
/**
 * Shop Orders REST API Endpoints
 *
 * Thin adapters over the extrachill-shop order abilities. Any seller type
 * (artist, venue, promoter) is named with `seller_type` + `seller_id`; the
 * abilities own seller scoping, permission and the response shape.
 *
 * Routes:
 * - GET  /wp-json/extrachill/v1/shop/orders              extrachill/shop-list-orders
 * - PUT  /wp-json/extrachill/v1/shop/orders/{id}/status  extrachill/shop-update-order-status
 * - POST /wp-json/extrachill/v1/shop/orders/{id}/refund  extrachill/shop-refund-order
 *
 * @package ExtraChillAPI
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_orders_routes' );

/**
 * Register shop orders REST routes.
 */
function extrachill_api_register_shop_orders_routes() {
	$order_id = array(
		'id' => array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		),
	);

	extrachill_api_shop_register_ability_route(
		'/shop/orders',
		array(
			array(
				'methods' => WP_REST_Server::READABLE,
				'ability' => 'extrachill/shop-list-orders',
				'params'  => array( 'status', 'page', 'per_page' ),
				'args'    => array_merge(
					extrachill_api_shop_seller_args(),
					array(
						'status'   => array(
							'type'    => 'string',
							'default' => 'all',
							'enum'    => array( 'all', 'needs_fulfillment', 'completed' ),
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					)
				),
			),
		)
	);

	extrachill_api_shop_register_ability_route(
		'/shop/orders/(?P<id>\d+)/status',
		array(
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'ability' => 'extrachill/shop-update-order-status',
				'params'  => array( 'id', 'status', 'tracking_number' ),
				'args'    => array_merge(
					$order_id,
					extrachill_api_shop_seller_args(),
					array(
						'status'          => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'completed' ),
						),
						'tracking_number' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			),
		)
	);

	extrachill_api_shop_register_ability_route(
		'/shop/orders/(?P<id>\d+)/refund',
		array(
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'ability' => 'extrachill/shop-refund-order',
				'params'  => array( 'id', 'reason' ),
				'args'    => array_merge(
					$order_id,
					extrachill_api_shop_seller_args(),
					array(
						'reason' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			),
		)
	);
}
