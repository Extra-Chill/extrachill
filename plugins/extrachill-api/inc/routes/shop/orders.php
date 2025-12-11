<?php
/**
 * Shop Orders REST API Endpoints
 *
 * Provides REST API endpoints for viewing artist orders and earnings.
 * All operations switch to shop blog context for WooCommerce access.
 *
 * Routes:
 * - GET /shop/orders - List orders containing user's artist products
 * - GET /shop/earnings - Get earnings summary statistics
 *
 * @package ExtraChillAPI
 * @since 0.2.8
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_orders_routes' );

/**
 * Get the shop blog ID.
 *
 * @return int|null Shop blog ID or null if not available.
 */
function extrachill_api_orders_get_shop_blog_id() {
	return function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;
}

/**
 * Check if user has any artist profiles they can manage.
 *
 * @return bool True if user has manageable artists.
 */
function extrachill_api_orders_user_has_artists() {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return false;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return false;
	}

	$artists = ec_get_artists_for_user( $user_id );
	return ! empty( $artists );
}

/**
 * Get user's artist profile IDs.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return array Array of artist profile IDs.
 */
function extrachill_api_orders_get_user_artist_ids( $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}

	return ec_get_artists_for_user( $user_id );
}

/**
 * Register shop orders REST routes.
 */
function extrachill_api_register_shop_orders_routes() {
	// List orders.
	register_rest_route( 'extrachill/v1', '/shop/orders', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_shop_orders_list_handler',
		'permission_callback' => 'extrachill_api_shop_orders_permission_check',
		'args'                => array(
			'limit' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 50,
				'sanitize_callback' => 'absint',
			),
			'status' => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array( 'completed', 'processing', 'on-hold', 'pending' ),
				'items'    => array( 'type' => 'string' ),
			),
		),
	) );

	// Get earnings summary.
	register_rest_route( 'extrachill/v1', '/shop/earnings', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_shop_earnings_handler',
		'permission_callback' => 'extrachill_api_shop_orders_permission_check',
	) );
}

/**
 * Permission check for orders endpoints.
 *
 * @param WP_REST_Request $request Request object.
 * @return bool|WP_Error True if permitted, WP_Error otherwise.
 */
function extrachill_api_shop_orders_permission_check( $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be logged in.',
			array( 'status' => 401 )
		);
	}

	$shop_blog_id = extrachill_api_orders_get_shop_blog_id();
	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Shop site is not configured.',
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_api_orders_user_has_artists() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be an artist to view orders.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Handle GET /shop/orders - List artist orders.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_shop_orders_list_handler( WP_REST_Request $request ) {
	$shop_blog_id = extrachill_api_orders_get_shop_blog_id();
	$user_id      = get_current_user_id();
	$artist_ids   = extrachill_api_orders_get_user_artist_ids( $user_id );
	$limit        = $request->get_param( 'limit' );
	$statuses     = $request->get_param( 'status' );

	if ( empty( $artist_ids ) && ! current_user_can( 'manage_options' ) ) {
		return rest_ensure_response( array() );
	}

	switch_to_blog( $shop_blog_id );
	try {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error(
				'dependency_missing',
				'WooCommerce is not available.',
				array( 'status' => 500 )
			);
		}

		// Get product IDs for user's artists.
		$product_ids = extrachill_api_shop_get_artist_product_ids( $artist_ids );

		if ( empty( $product_ids ) ) {
			return rest_ensure_response( array() );
		}

		// Get orders.
		$orders = wc_get_orders(
			array(
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => $statuses,
			)
		);

		$response = array();

		foreach ( $orders as $order ) {
			$order_data = extrachill_api_shop_build_order_response( $order, $product_ids, $artist_ids );

			if ( $order_data ) {
				$response[] = $order_data;
			}
		}

		return rest_ensure_response( $response );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle GET /shop/earnings - Get earnings summary.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_shop_earnings_handler( WP_REST_Request $request ) {
	$shop_blog_id = extrachill_api_orders_get_shop_blog_id();
	$user_id      = get_current_user_id();
	$artist_ids   = extrachill_api_orders_get_user_artist_ids( $user_id );

	if ( empty( $artist_ids ) && ! current_user_can( 'manage_options' ) ) {
		return rest_ensure_response( array(
			'total_orders'    => 0,
			'total_earnings'  => 0,
			'pending_payout'  => 0,
			'completed_sales' => 0,
		) );
	}

	switch_to_blog( $shop_blog_id );
	try {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error(
				'dependency_missing',
				'WooCommerce is not available.',
				array( 'status' => 500 )
			);
		}

		// Get product IDs for user's artists.
		$product_ids = extrachill_api_shop_get_artist_product_ids( $artist_ids );

		if ( empty( $product_ids ) ) {
			return rest_ensure_response( array(
				'total_orders'    => 0,
				'total_earnings'  => 0,
				'pending_payout'  => 0,
				'completed_sales' => 0,
			) );
		}

		// Get all orders with relevant statuses.
		$orders = wc_get_orders(
			array(
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => array( 'completed', 'processing', 'on-hold', 'pending' ),
			)
		);

		$stats = array(
			'total_orders'    => 0,
			'total_earnings'  => 0,
			'pending_payout'  => 0,
			'completed_sales' => 0,
		);

		foreach ( $orders as $order ) {
			$order_earnings = extrachill_api_shop_calculate_order_artist_earnings( $order, $product_ids );

			if ( $order_earnings > 0 ) {
				$stats['total_orders']++;
				$stats['total_earnings'] += $order_earnings;

				$status = $order->get_status();
				if ( in_array( $status, array( 'completed', 'processing' ), true ) ) {
					$stats['completed_sales']++;
				} else {
					$stats['pending_payout'] += $order_earnings;
				}
			}
		}

		// Round to 2 decimal places.
		$stats['total_earnings'] = round( $stats['total_earnings'], 2 );
		$stats['pending_payout'] = round( $stats['pending_payout'], 2 );

		return rest_ensure_response( $stats );
	} finally {
		restore_current_blog();
	}
}

/**
 * Get product IDs for specified artists.
 *
 * Must be called within shop blog context.
 *
 * @param array $artist_ids Array of artist profile IDs.
 * @return array Array of product IDs.
 */
function extrachill_api_shop_get_artist_product_ids( $artist_ids ) {
	if ( empty( $artist_ids ) ) {
		return array();
	}

	global $wpdb;

	$placeholders = implode( ',', array_fill( 0, count( $artist_ids ), '%d' ) );

	$product_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = '_artist_profile_id'
			AND meta_value IN ($placeholders)",
			...$artist_ids
		)
	);

	return array_map( 'intval', $product_ids );
}

/**
 * Build order response for API output.
 *
 * Must be called within shop blog context.
 *
 * @param WC_Order $order WooCommerce order object.
 * @param array    $product_ids Array of artist's product IDs.
 * @param array    $artist_ids Array of artist profile IDs.
 * @return array|null Order data or null if no relevant items.
 */
function extrachill_api_shop_build_order_response( $order, $product_ids, $artist_ids ) {
	$order_items  = array();
	$artist_total = 0;

	foreach ( $order->get_items() as $item ) {
		$product_id = $item->get_product_id();

		if ( ! in_array( $product_id, $product_ids, true ) ) {
			continue;
		}

		$line_total    = (float) $item->get_total();
		$artist_payout = extrachill_api_shop_calculate_item_payout( $line_total, $product_id );

		$order_items[] = array(
			'product_id'    => $product_id,
			'name'          => $item->get_name(),
			'quantity'      => $item->get_quantity(),
			'line_total'    => $line_total,
			'artist_payout' => $artist_payout,
		);

		$artist_total += $artist_payout;
	}

	if ( empty( $order_items ) ) {
		return null;
	}

	$date_created = $order->get_date_created();

	return array(
		'order_id'      => $order->get_id(),
		'order_number'  => $order->get_order_number(),
		'status'        => $order->get_status(),
		'date_created'  => $date_created ? $date_created->date( 'c' ) : null,
		'items'         => $order_items,
		'artist_total'  => round( $artist_total, 2 ),
		'payout_status' => in_array( $order->get_status(), array( 'completed', 'processing' ), true ) ? 'eligible' : 'pending',
	);
}

/**
 * Calculate artist payout for a line item.
 *
 * Must be called within shop blog context.
 *
 * @param float $line_total Line item total.
 * @param int   $product_id Product ID.
 * @return float Artist payout amount.
 */
function extrachill_api_shop_calculate_item_payout( $line_total, $product_id ) {
	// Use shop plugin function if available.
	if ( function_exists( 'extrachill_shop_calculate_artist_payout' ) ) {
		return extrachill_shop_calculate_artist_payout( $line_total, $product_id );
	}

	// Fallback: 90% to artist (10% commission).
	$commission_rate = 0.10;
	return round( $line_total * ( 1 - $commission_rate ), 2 );
}

/**
 * Calculate total artist earnings for an order.
 *
 * Must be called within shop blog context.
 *
 * @param WC_Order $order WooCommerce order object.
 * @param array    $product_ids Array of artist's product IDs.
 * @return float Total artist earnings.
 */
function extrachill_api_shop_calculate_order_artist_earnings( $order, $product_ids ) {
	$total = 0;

	foreach ( $order->get_items() as $item ) {
		$product_id = $item->get_product_id();

		if ( ! in_array( $product_id, $product_ids, true ) ) {
			continue;
		}

		$line_total = (float) $item->get_total();
		$total     += extrachill_api_shop_calculate_item_payout( $line_total, $product_id );
	}

	return $total;
}
