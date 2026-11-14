<?php
/**
 * Shipping Address REST API Endpoints
 *
 * Thin adapters over the extrachill-shop shipping-address abilities. The
 * ship-from address belongs to a seller (artist, venue or promoter), named
 * with `seller_type` + `seller_id`.
 *
 * Routes:
 * - GET /wp-json/extrachill/v1/shop/shipping-address  extrachill/shop-get-shipping-address
 * - PUT /wp-json/extrachill/v1/shop/shipping-address  extrachill/shop-update-shipping-address
 *
 * @package ExtraChillAPI
 * @since 0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shipping_address_routes' );

/**
 * Register shipping address REST routes.
 */
function extrachill_api_register_shipping_address_routes() {
	$fields = array( 'name', 'street1', 'street2', 'city', 'state', 'zip' );
	$args   = extrachill_api_shop_seller_args();
	foreach ( $fields as $field ) {
		$args[ $field ] = array(
			'required'          => 'street2' !== $field,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	extrachill_api_shop_register_ability_route(
		'/shop/shipping-address',
		array(
			array(
				'methods' => WP_REST_Server::READABLE,
				'ability' => 'extrachill/shop-get-shipping-address',
				'args'    => extrachill_api_shop_seller_args(),
			),
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'ability' => 'extrachill/shop-update-shipping-address',
				'params'  => $fields,
				'args'    => $args,
			),
		)
	);
}
