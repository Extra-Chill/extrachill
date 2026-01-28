<?php
/**
 * Shipping Labels REST API Endpoints
 *
 * Endpoint for purchasing shipping labels via Shippo.
 * Automatically selects cheapest USPS rate, updates order status, and sends email.
 *
 * Routes:
 * - POST /wp-json/extrachill/v1/shop/shipping-labels  Purchase shipping label
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
	register_rest_route(
		'extrachill/v1',
		'/shop/shipping-labels',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_shipping_labels_create_handler',
			'permission_callback' => 'extrachill_api_shipping_labels_permission_check',
			'args'                => array(
				'order_id'  => array(
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
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/shop/shipping-labels/(?P<order_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_shipping_labels_get_handler',
			'permission_callback' => 'extrachill_api_shipping_labels_permission_check',
			'args'                => array(
				'order_id'  => array(
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
		)
	);
}

/**
 * Permission check for shipping labels routes.
 */
function extrachill_api_shipping_labels_permission_check( WP_REST_Request $request ) {
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

	if ( ! function_exists( 'extrachill_api_shop_user_can_manage_artist' ) ) {
		return new WP_Error(
			'configuration_error',
			'Shop API not available.',
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Handle GET /shop/shipping-labels/{order_id} - Get existing label for order.
 */
function extrachill_api_shipping_labels_get_handler( WP_REST_Request $request ) {
	$order_id  = absint( $request->get_param( 'order_id' ) );
	$artist_id = absint( $request->get_param( 'artist_id' ) );

	$shop_blog_id = function_exists( 'extrachill_api_shop_get_blog_id' )
		? extrachill_api_shop_get_blog_id()
		: ( function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null );
	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'blog_id_missing',
			'Shop blog ID helper not available.',
			array( 'status' => 500 )
		);
	}

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

		$label_url       = $order->get_meta( '_artist_label_' . $artist_id ) ?: '';
		$tracking_number = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';
		$label_data      = $order->get_meta( '_artist_label_data_' . $artist_id ) ?: array();

		return rest_ensure_response( array(
			'order_id'        => $order_id,
			'artist_id'       => $artist_id,
			'has_label'       => ! empty( $label_url ),
			'label_url'       => $label_url,
			'tracking_number' => $tracking_number,
			'carrier'         => $label_data['carrier'] ?? '',
			'service'         => $label_data['service'] ?? '',
			'cost'            => $label_data['cost'] ?? 0,
		) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle POST /shop/shipping-labels - Purchase shipping label.
 */
function extrachill_api_shipping_labels_create_handler( WP_REST_Request $request ) {
	$order_id  = absint( $request->get_param( 'order_id' ) );
	$artist_id = absint( $request->get_param( 'artist_id' ) );
	$user_id   = get_current_user_id();

	if ( ! function_exists( 'extrachill_api_artist_has_shipping_address' ) ) {
		return new WP_Error(
			'configuration_error',
			'Shipping address API not available.',
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_api_artist_has_shipping_address( $artist_id ) ) {
		return new WP_Error(
			'no_shipping_address',
			'Please set up your shipping address in the Settings tab before printing labels.',
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'extrachill_shop_is_shippo_configured' ) || ! extrachill_shop_is_shippo_configured() ) {
		return new WP_Error(
			'shippo_not_configured',
			'Shipping service is not configured. Please contact support.',
			array( 'status' => 500 )
		);
	}

	$shop_blog_id = function_exists( 'extrachill_api_shop_get_blog_id' )
		? extrachill_api_shop_get_blog_id()
		: ( function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null );
	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'blog_id_missing',
			'Shop blog ID helper not available.',
			array( 'status' => 500 )
		);
	}

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
				'invalid_artist',
				'This order does not contain products from your artist.',
				array( 'status' => 400 )
			);
		}

		// Check if order contains only ships-free products for this artist
		if ( function_exists( 'extrachill_api_shop_order_ships_free_only' ) ) {
			$artist_payout = $payouts[ $artist_id ] ?? array();
			if ( extrachill_api_shop_order_ships_free_only( $artist_payout ) ) {
				return new WP_Error(
					'ships_free_order',
					'This order contains only "Ships Free" items. No shipping label is needed—ship these items yourself.',
					array( 'status' => 400 )
				);
			}
		}

		$existing_label = $order->get_meta( '_artist_label_' . $artist_id );
		if ( ! empty( $existing_label ) ) {
			$tracking = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';
			$label_data = $order->get_meta( '_artist_label_data_' . $artist_id ) ?: array();

			return rest_ensure_response( array(
				'success'         => true,
				'reprint'         => true,
				'order_id'        => $order_id,
				'artist_id'       => $artist_id,
				'label_url'       => $existing_label,
				'tracking_number' => $tracking,
				'carrier'         => $label_data['carrier'] ?? 'USPS',
				'service'         => $label_data['service'] ?? '',
				'cost'            => $label_data['cost'] ?? 0,
			) );
		}

		$from_address = extrachill_api_get_artist_shipping_address( $artist_id );

		$shipping = $order->get_address( 'shipping' );
		$billing  = $order->get_address( 'billing' );
		$address  = ! empty( $shipping['address_1'] ) ? $shipping : $billing;

		$to_address = array(
			'name'    => trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) ),
			'street1' => $address['address_1'] ?? '',
			'street2' => $address['address_2'] ?? '',
			'city'    => $address['city'] ?? '',
			'state'   => $address['state'] ?? '',
			'zip'     => $address['postcode'] ?? '',
			'country' => $address['country'] ?? 'US',
		);

		if ( 'US' !== $to_address['country'] ) {
			return new WP_Error(
				'international_not_supported',
				'International shipping is not currently supported.',
				array( 'status' => 400 )
			);
		}

		$label_result = extrachill_shop_shippo_create_label( $from_address, $to_address );

		if ( is_wp_error( $label_result ) ) {
			return $label_result;
		}

		$order->update_meta_data( '_artist_label_' . $artist_id, $label_result['label_url'] );
		$order->update_meta_data( '_artist_tracking_' . $artist_id, $label_result['tracking_number'] );
		$order->update_meta_data( '_artist_label_data_' . $artist_id, array(
			'carrier'        => $label_result['carrier'],
			'service'        => $label_result['service'],
			'cost'           => $label_result['cost'],
			'tracking_url'   => $label_result['tracking_url'],
			'rate_id'        => $label_result['rate_id'],
			'transaction_id' => $label_result['transaction_id'],
			'purchased_at'   => current_time( 'mysql' ),
			'purchased_by'   => $user_id,
		) );

		$order->set_status( 'completed', sprintf(
			'Shipping label purchased by %s. Tracking: %s',
			wp_get_current_user()->display_name,
			$label_result['tracking_number']
		) );
		$order->save();

		$user       = get_userdata( $user_id );
		$user_email = $user ? $user->user_email : '';

		if ( $user_email ) {
			extrachill_api_send_label_email(
				$user_email,
				$order,
				$artist_id,
				$label_result,
				$payouts[ $artist_id ]
			);
		}

		return rest_ensure_response( array(
			'success'         => true,
			'order_id'        => $order_id,
			'artist_id'       => $artist_id,
			'label_url'       => $label_result['label_url'],
			'tracking_number' => $label_result['tracking_number'],
			'tracking_url'    => $label_result['tracking_url'],
			'carrier'         => $label_result['carrier'],
			'service'         => $label_result['service'],
			'cost'            => $label_result['cost'],
		) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Send shipping label email to the user who purchased it.
 *
 * @param string   $to_email    Recipient email.
 * @param WC_Order $order       WooCommerce order.
 * @param int      $artist_id   Artist profile ID.
 * @param array    $label_data  Label data from Shippo.
 * @param array    $payout_data Artist payout data from order.
 */
function extrachill_api_send_label_email( $to_email, $order, $artist_id, $label_data, $payout_data ) {
	$order_number = $order->get_order_number();
	$subject      = sprintf( 'Shipping Label Ready - Order #%s', $order_number );

	$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

	$items_list = '';
	if ( ! empty( $payout_data['items'] ) && is_array( $payout_data['items'] ) ) {
		foreach ( $payout_data['items'] as $item ) {
			$product_id = $item['product_id'] ?? 0;
			$product    = wc_get_product( $product_id );
			$name       = $product ? $product->get_name() : 'Product';
			$qty        = $item['quantity'] ?? 1;
			$items_list .= sprintf( "- %s (x%d)\n", $name, $qty );
		}
	}

	$shipping = $order->get_address( 'shipping' );
	$billing  = $order->get_address( 'billing' );
	$address  = ! empty( $shipping['address_1'] ) ? $shipping : $billing;

	$address_formatted = sprintf(
		"%s\n%s\n%s%s, %s %s",
		$customer_name,
		$address['address_1'] ?? '',
		! empty( $address['address_2'] ) ? $address['address_2'] . "\n" : '',
		$address['city'] ?? '',
		$address['state'] ?? '',
		$address['postcode'] ?? ''
	);

	$shop_manager_url = home_url( '/shop-manager/' );

	$message = sprintf(
		"Your shipping label for Order #%s is ready.\n\n" .
		"TRACKING NUMBER: %s\n\n" .
		"LABEL: %s\n\n" .
		"---\n\n" .
		"ORDER DETAILS\n\n" .
		"Customer: %s\n\n" .
		"Ship To:\n%s\n\n" .
		"Items:\n%s\n" .
		"---\n\n" .
		"View order in Shop Manager: %s\n\n" .
		"Carrier: %s %s",
		$order_number,
		$label_data['tracking_number'],
		$label_data['label_url'],
		$customer_name,
		$address_formatted,
		$items_list,
		$shop_manager_url,
		$label_data['carrier'],
		$label_data['service']
	);

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	wp_mail( $to_email, $subject, $message, $headers );
}
