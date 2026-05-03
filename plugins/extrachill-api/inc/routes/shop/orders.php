<?php
/**
 * Shop Orders REST API Endpoints
 *
 * Endpoints for artists to view and manage orders containing their products.
 * Orders are filtered to show only the artist's items from each order.
 * Route affinity middleware ensures this runs on the shop site.
 *
 * Routes:
 * - GET    /wp-json/extrachill/v1/shop/orders              List orders for artist
 * - PUT    /wp-json/extrachill/v1/shop/orders/{id}/status  Update order status (mark shipped)
 * - POST   /wp-json/extrachill/v1/shop/orders/{id}/refund  Issue full refund
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
	// List orders
	register_rest_route( 'extrachill/v1', '/shop/orders', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_shop_orders_list_handler',
			'permission_callback' => 'extrachill_api_shop_orders_permission_check',
			'args'                => array(
				'artist_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'status' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'all',
					'enum'              => array( 'all', 'needs_fulfillment', 'completed' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
			),
		),
	) );

	// Update order status
	register_rest_route( 'extrachill/v1', '/shop/orders/(?P<id>\d+)/status', array(
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'extrachill_api_shop_orders_status_handler',
			'permission_callback' => 'extrachill_api_shop_orders_item_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'status' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'completed' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'tracking_number' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		),
	) );

	// Refund order
	register_rest_route( 'extrachill/v1', '/shop/orders/(?P<id>\d+)/refund', array(
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_shop_orders_refund_handler',
			'permission_callback' => 'extrachill_api_shop_orders_item_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
	) );
}

/**
 * Permission check for orders collection routes.
 */
function extrachill_api_shop_orders_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be logged in.',
			array( 'status' => 401 )
		);
	}

	$artist_id = absint( $request->get_param( 'artist_id' ) );
	if ( ! $artist_id ) {
		return new WP_Error(
			'missing_artist_id',
			'Artist ID is required.',
			array( 'status' => 400 )
		);
	}

	if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to view orders for this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Permission check for order item routes.
 */
function extrachill_api_shop_orders_item_permission_check( WP_REST_Request $request ) {
	$base_check = extrachill_api_shop_orders_permission_check( $request );
	if ( is_wp_error( $base_check ) ) {
		return $base_check;
	}

	$order_id  = absint( $request->get_param( 'id' ) );
	$artist_id = absint( $request->get_param( 'artist_id' ) );

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error(
			'woocommerce_missing',
			'WooCommerce is not available.',
			array( 'status' => 500 )
		);
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error(
			'order_not_found',
			'Order not found.',
			array( 'status' => 404 )
		);
	}

	$payouts = $order->get_meta( '_artist_payouts' ) ?: array();
	if ( ! isset( $payouts[ $artist_id ] ) ) {
		return new WP_Error(
			'rest_forbidden',
			'This order does not contain products from your artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Handle GET /shop/orders - List orders for artist.
 *
 * Wraps the extrachill/shop-list-orders ability from extrachill-shop.
 */
function extrachill_api_shop_orders_list_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/shop-list-orders' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'artist_id' => absint( $request->get_param( 'artist_id' ) ),
			'status'    => $request->get_param( 'status' ),
			'page'      => absint( $request->get_param( 'page' ) ),
			'per_page'  => absint( $request->get_param( 'per_page' ) ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle PUT /shop/orders/{id}/status - Update order status.
 *
 * Wraps the extrachill/shop-update-order-status ability from extrachill-shop.
 */
function extrachill_api_shop_orders_status_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/shop-update-order-status' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'id'              => absint( $request->get_param( 'id' ) ),
			'artist_id'       => absint( $request->get_param( 'artist_id' ) ),
			'status'          => $request->get_param( 'status' ),
			'tracking_number' => $request->get_param( 'tracking_number' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle POST /shop/orders/{id}/refund - Issue full refund.
 *
 * Wraps the extrachill/shop-refund-order ability from extrachill-shop.
 */
function extrachill_api_shop_orders_refund_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/shop-refund-order' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'id'        => absint( $request->get_param( 'id' ) ),
			'artist_id' => absint( $request->get_param( 'artist_id' ) ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Check if all items in an artist's portion of an order ship free.
 *
 * @param array $artist_payout Artist payout data from order.
 * @return bool True if all items ship free.
 */
function extrachill_api_shop_order_ships_free_only( $artist_payout ) {
	if ( empty( $artist_payout['items'] ) || ! is_array( $artist_payout['items'] ) ) {
		return false;
	}

	foreach ( $artist_payout['items'] as $item_data ) {
		$product_id = $item_data['product_id'] ?? 0;
		if ( ! $product_id ) {
			continue;
		}

		if ( '1' !== get_post_meta( $product_id, '_ships_free', true ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Build order response for artist.
 *
 * @param WC_Order $order     WooCommerce order.
 * @param int      $artist_id Artist profile ID.
 * @return array Order data.
 */
function extrachill_api_shop_orders_build_response( $order, $artist_id ) {
	$payouts         = $order->get_meta( '_artist_payouts' ) ?: array();
	$artist_payout   = $payouts[ $artist_id ] ?? array();
	$tracking_number = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';

	$items = array();
	if ( ! empty( $artist_payout['items'] ) ) {
		foreach ( $artist_payout['items'] as $item_data ) {
			$product_id = $item_data['product_id'] ?? 0;
			$product    = wc_get_product( $product_id );

			$items[] = array(
				'product_id' => $product_id,
				'name'       => $product ? $product->get_name() : 'Unknown Product',
				'quantity'   => $item_data['quantity'] ?? 1,
				'total'      => floatval( $item_data['line_total'] ?? 0 ),
			);
		}
	}

	$shipping = $order->get_address( 'shipping' );
	$billing  = $order->get_address( 'billing' );

	$address = ! empty( $shipping['address_1'] ) ? $shipping : $billing;

	return array(
		'id'              => $order->get_id(),
		'number'          => $order->get_order_number(),
		'status'          => $order->get_status(),
		'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '',
		'customer'        => array(
			'name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'   => $order->get_billing_email(),
			'address' => array(
				'address_1' => $address['address_1'] ?? '',
				'address_2' => $address['address_2'] ?? '',
				'city'      => $address['city'] ?? '',
				'state'     => $address['state'] ?? '',
				'postcode'  => $address['postcode'] ?? '',
				'country'   => $address['country'] ?? '',
			),
		),
		'items'           => $items,
		'artist_payout'   => floatval( $artist_payout['artist_payout'] ?? 0 ),
		'order_total'     => floatval( $artist_payout['total'] ?? 0 ),
		'tracking_number' => $tracking_number,
		'ships_free_only' => extrachill_api_shop_order_ships_free_only( $artist_payout ),
	);
}
