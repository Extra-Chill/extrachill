<?php
/**
 * Shop Products REST API Endpoints
 *
 * Thin adapters over the extrachill-shop product abilities, which own every
 * product write and the seller permission check. A product belongs to one
 * seller (artist, venue or promoter): list and create name it with
 * `seller_type` + `seller_id`; item routes follow the product's own seller,
 * and an update that names a seller moves the product to it.
 *
 * Routes:
 * - GET    /wp-json/extrachill/v1/shop/products       extrachill/shop-list-products
 * - POST   /wp-json/extrachill/v1/shop/products       extrachill/shop-create-product
 * - GET    /wp-json/extrachill/v1/shop/products/{id}  extrachill/shop-get-product
 * - PUT    /wp-json/extrachill/v1/shop/products/{id}  extrachill/shop-update-product
 * - DELETE /wp-json/extrachill/v1/shop/products/{id}  extrachill/shop-delete-product
 *
 * @package ExtraChillAPI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_products_routes' );

/**
 * Product fields forwarded from create and update requests.
 *
 * @return string[]
 */
function extrachill_api_shop_product_fields() {
	return array( 'name', 'price', 'sale_price', 'description', 'manage_stock', 'stock_quantity', 'image_id', 'gallery_ids', 'sizes', 'ships_free' );
}

/**
 * Register shop products REST routes.
 */
function extrachill_api_register_shop_products_routes() {
	$product_id = array(
		'id' => array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		),
	);

	extrachill_api_shop_register_ability_route(
		'/shop/products',
		array(
			array(
				'methods' => WP_REST_Server::READABLE,
				'ability' => 'extrachill/shop-list-products',
				'params'  => array( 'page', 'per_page' ),
				'args'    => array_merge(
					extrachill_api_shop_seller_args(),
					array(
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
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'ability' => 'extrachill/shop-create-product',
				'params'  => extrachill_api_shop_product_fields(),
				'args'    => extrachill_api_shop_products_create_args(),
			),
		)
	);

	extrachill_api_shop_register_ability_route(
		'/shop/products/(?P<id>\d+)',
		array(
			array(
				'methods' => WP_REST_Server::READABLE,
				'ability' => 'extrachill/shop-get-product',
				'params'  => array( 'id' ),
				'args'    => $product_id,
			),
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'ability' => 'extrachill/shop-update-product',
				'params'  => array_merge( array( 'id', 'status', 'image_ids' ), extrachill_api_shop_product_fields() ),
				'args'    => array_merge( $product_id, extrachill_api_shop_products_update_args() ),
			),
			array(
				'methods' => WP_REST_Server::DELETABLE,
				'ability' => 'extrachill/shop-delete-product',
				'params'  => array( 'id' ),
				'args'    => $product_id,
			),
		)
	);
}

/**
 * REST arg schema for POST /shop/products.
 */
function extrachill_api_shop_products_create_args() {
	return array_merge(
		extrachill_api_shop_seller_args(),
		array(
			'name'           => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'price'          => array(
				'required'          => true,
				'type'              => 'number',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (float) $value > 0;
				},
			),
			'sale_price'     => array(
				'required'          => false,
				'type'              => 'number',
				'validate_callback' => function ( $value ) {
					return null === $value || is_numeric( $value );
				},
			),
			'description'    => array(
				'required' => false,
				'type'     => 'string',
			),
			'manage_stock'   => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'stock_quantity' => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 0;
				},
			),
			'image_id'       => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value >= 0;
				},
			),
			'gallery_ids'    => array(
				'required'          => false,
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => function ( $value ) {
					if ( ! is_array( $value ) ) {
						return array();
					}
					return array_map( 'absint', $value );
				},
			),
			'sizes'          => array(
				'required'          => false,
				'type'              => 'array',
				'items'             => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array( 'type' => 'string' ),
						'stock' => array( 'type' => 'integer' ),
					),
				),
				'sanitize_callback' => function ( $value ) {
					if ( ! is_array( $value ) ) {
						return array();
					}
					return array_map(
						function ( $item ) {
							return array(
								'name'  => sanitize_text_field( $item['name'] ?? '' ),
								'stock' => absint( $item['stock'] ?? 0 ),
							);
						},
						$value
					);
				},
			),
			'ships_free'     => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			),
		)
	);
}

/**
 * REST arg schema for PUT /shop/products/{id}.
 */
function extrachill_api_shop_products_update_args() {
	return array_merge(
		extrachill_api_shop_seller_args( false ),
		array(
			'status'         => array(
				'required'          => false,
				'type'              => 'string',
				'enum'              => array( 'draft', 'publish' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'image_ids'      => array(
				'required'          => false,
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => function ( $value ) {
					if ( null === $value ) {
						return null;
					}
					if ( ! is_array( $value ) ) {
						return array();
					}
					return array_values( array_filter( array_map( 'absint', $value ) ) );
				},
			),
			'name'           => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'price'          => array(
				'required'          => false,
				'type'              => 'number',
				'validate_callback' => function ( $value ) {
					return null === $value || is_numeric( $value );
				},
			),
			'sale_price'     => array(
				'required'          => false,
				'type'              => 'number',
				'validate_callback' => function ( $value ) {
					return null === $value || is_numeric( $value );
				},
			),
			'description'    => array(
				'required' => false,
				'type'     => 'string',
			),
			'manage_stock'   => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'stock_quantity' => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => function ( $value ) {
					return is_numeric( $value ) ? (int) $value : null;
				},
				'validate_callback' => function ( $value ) {
					return null === $value || ( is_numeric( $value ) && (int) $value >= 0 );
				},
			),
			'image_id'       => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => function ( $value ) {
					return is_numeric( $value ) ? absint( $value ) : null;
				},
			),
			'gallery_ids'    => array(
				'required'          => false,
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => function ( $value ) {
					if ( null === $value ) {
						return null;
					}
					if ( ! is_array( $value ) ) {
						return array();
					}
					return array_map( 'absint', $value );
				},
			),
			'sizes'          => array(
				'required'          => false,
				'type'              => 'array',
				'items'             => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array( 'type' => 'string' ),
						'stock' => array( 'type' => 'integer' ),
					),
				),
				'sanitize_callback' => function ( $value ) {
					if ( null === $value ) {
						return null;
					}
					if ( ! is_array( $value ) ) {
						return array();
					}
					return array_map(
						function ( $item ) {
							return array(
								'name'  => sanitize_text_field( $item['name'] ?? '' ),
								'stock' => absint( $item['stock'] ?? 0 ),
							);
						},
						$value
					);
				},
			),
			'ships_free'     => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		)
	);
}

