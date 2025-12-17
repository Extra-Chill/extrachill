<?php
/**
 * Shop Orders REST API Endpoints
 *
 * Endpoints for artists to view and manage orders containing their products.
 * Orders are filtered to show only the artist's items from each order.
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

	$shop_blog_id = extrachill_api_shop_get_blog_id();
	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Shop site is not configured.',
			array( 'status' => 500 )
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

	$order_id     = absint( $request->get_param( 'id' ) );
	$artist_id    = absint( $request->get_param( 'artist_id' ) );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
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
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle GET /shop/orders - List orders for artist.
 */
function extrachill_api_shop_orders_list_handler( WP_REST_Request $request ) {
	$artist_id    = absint( $request->get_param( 'artist_id' ) );
	$status       = $request->get_param( 'status' );
	$page         = max( 1, absint( $request->get_param( 'page' ) ) );
	$per_page     = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error(
				'woocommerce_missing',
				'WooCommerce is not available.',
				array( 'status' => 500 )
			);
		}

		$wc_statuses = array( 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-on-hold' );

		$orders = wc_get_orders( array(
			'limit'      => -1,
			'status'     => $wc_statuses,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => array(
				array(
					'key'     => '_artist_payouts',
					'compare' => 'EXISTS',
				),
			),
		) );

		$filtered_orders          = array();
		$needs_fulfillment_count  = 0;

		foreach ( $orders as $order ) {
			$payouts = $order->get_meta( '_artist_payouts' ) ?: array();
			if ( ! isset( $payouts[ $artist_id ] ) ) {
				continue;
			}

			$order_status = $order->get_status();

			if ( 'processing' === $order_status || 'on-hold' === $order_status ) {
				$needs_fulfillment_count++;
			}

			if ( 'needs_fulfillment' === $status && ! in_array( $order_status, array( 'processing', 'on-hold' ), true ) ) {
				continue;
			}

			if ( 'completed' === $status && 'completed' !== $order_status && 'refunded' !== $order_status ) {
				continue;
			}

			$filtered_orders[] = $order;
		}

		$total        = count( $filtered_orders );
		$total_pages  = ceil( $total / $per_page );
		$offset       = ( $page - 1 ) * $per_page;
		$paged_orders = array_slice( $filtered_orders, $offset, $per_page );

		$response_orders = array();
		foreach ( $paged_orders as $order ) {
			$response_orders[] = extrachill_api_shop_orders_build_response( $order, $artist_id );
		}

		return rest_ensure_response( array(
			'orders'                  => $response_orders,
			'total'                   => $total,
			'total_pages'             => $total_pages,
			'page'                    => $page,
			'per_page'                => $per_page,
			'needs_fulfillment_count' => $needs_fulfillment_count,
		) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle PUT /shop/orders/{id}/status - Update order status.
 */
function extrachill_api_shop_orders_status_handler( WP_REST_Request $request ) {
	$order_id        = absint( $request->get_param( 'id' ) );
	$artist_id       = absint( $request->get_param( 'artist_id' ) );
	$new_status      = $request->get_param( 'status' );
	$tracking_number = $request->get_param( 'tracking_number' );
	$shop_blog_id    = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				'Order not found.',
				array( 'status' => 404 )
			);
		}

		if ( $tracking_number ) {
			$order->update_meta_data( '_artist_tracking_' . $artist_id, $tracking_number );
		}

		if ( 'completed' === $new_status ) {
			$order->set_status( 'completed', 'Order marked as shipped by artist.' );
		}

		$order->save();

		return rest_ensure_response( extrachill_api_shop_orders_build_response( $order, $artist_id ) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle POST /shop/orders/{id}/refund - Issue full refund.
 */
function extrachill_api_shop_orders_refund_handler( WP_REST_Request $request ) {
	$order_id     = absint( $request->get_param( 'id' ) );
	$artist_id    = absint( $request->get_param( 'artist_id' ) );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
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
				'invalid_artist',
				'This order does not contain products from your artist.',
				array( 'status' => 400 )
			);
		}

		$artist_payout = $payouts[ $artist_id ];
		$refund_amount = floatval( $artist_payout['total'] ?? 0 );

		if ( $refund_amount <= 0 ) {
			return new WP_Error(
				'invalid_refund_amount',
				'No refundable amount found for this artist.',
				array( 'status' => 400 )
			);
		}

		$charges = $order->get_meta( '_stripe_charges' ) ?: array();
		$payment_intent_id = $charges[ $artist_id ]['payment_intent_id'] ?? '';

		if ( ! $payment_intent_id ) {
			return new WP_Error(
				'no_payment_intent',
				'No payment intent found for this artist order.',
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'extrachill_shop_stripe_init' ) || ! extrachill_shop_stripe_init() ) {
			return new WP_Error(
				'stripe_not_configured',
				'Stripe is not configured.',
				array( 'status' => 500 )
			);
		}

		try {
			\Stripe\Refund::create( array(
				'payment_intent' => $payment_intent_id,
			) );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'refund_failed',
				'Refund failed: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}

		$order->set_status( 'refunded', 'Full refund issued by artist.' );
		$order->save();

		return rest_ensure_response( array(
			'success'       => true,
			'order_id'      => $order_id,
			'refund_amount' => $refund_amount,
		) );
	} finally {
		restore_current_blog();
	}
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
	);
}
