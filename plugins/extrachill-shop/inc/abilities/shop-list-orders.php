<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-list-orders
 *
 * List orders containing products from a specific artist.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_list_orders_ability' );

/**
 * Register the shop-list-orders ability.
 */
function extrachill_shop_register_list_orders_ability(): void {

	wp_register_ability(
		'extrachill/shop-list-orders',
		array(
			'label'       => __( 'List Shop Orders', 'extrachill-shop' ),
			'description' => __( 'List orders containing products from a specific artist, with filtering and pagination.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'needs_fulfillment', 'completed' ),
						'default'     => 'all',
						'description' => 'Filter by fulfilment status.',
					),
					'page'      => array(
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Page number.',
					),
					'per_page'  => array(
						'type'        => 'integer',
						'default'     => 20,
						'description' => 'Items per page.',
					),
				),
				'required' => array( 'artist_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'orders'                  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'total'                   => array( 'type' => 'integer' ),
					'total_pages'             => array( 'type' => 'integer' ),
					'page'                    => array( 'type' => 'integer' ),
					'per_page'                => array( 'type' => 'integer' ),
					'needs_fulfillment_count' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_list_orders',
			'permission_callback' => static function ( array $input ): bool|WP_Error {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
				}
				$artist_id = isset( $input['artist_id'] ) ? (int) $input['artist_id'] : 0;
				if ( ! $artist_id ) {
					return new WP_Error( 'missing_artist_id', 'Artist ID is required.', array( 'status' => 400 ) );
				}
				if ( function_exists( 'extrachill_api_shop_user_can_manage_artist' ) ) {
					if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
						return new WP_Error( 'rest_forbidden', 'You do not have permission to view orders for this artist.', array( 'status' => 403 ) );
					}
					return true;
				}
				if ( function_exists( 'ec_can_manage_artist' ) ) {
					return ec_can_manage_artist( get_current_user_id(), $artist_id );
				}
				return current_user_can( 'manage_options' );
			},
			'meta' => array(
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
 * List orders for an artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_list_orders( array $input ): array|WP_Error {
	$artist_id = (int) ( $input['artist_id'] ?? 0 );
	$status    = (string) ( $input['status'] ?? 'all' );
	$page      = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page  = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
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

	$filtered_orders         = array();
	$needs_fulfillment_count = 0;

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
	$total_pages  = (int) ceil( $total / $per_page );
	$offset       = ( $page - 1 ) * $per_page;
	$paged_orders = array_slice( $filtered_orders, $offset, $per_page );

	$response_orders = array();
	foreach ( $paged_orders as $order ) {
		$response_orders[] = extrachill_shop_ability_build_order_response( $order, $artist_id );
	}

	return array(
		'orders'                  => $response_orders,
		'total'                   => $total,
		'total_pages'             => $total_pages,
		'page'                    => $page,
		'per_page'                => $per_page,
		'needs_fulfillment_count' => $needs_fulfillment_count,
	);
}

// ─── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build order response for an artist.
 *
 * @param WC_Order $order     WooCommerce order.
 * @param int      $artist_id Artist profile ID.
 * @return array
 */
function extrachill_shop_ability_build_order_response( $order, int $artist_id ): array {
	$payouts         = $order->get_meta( '_artist_payouts' ) ?: array();
	$artist_payout   = $payouts[ $artist_id ] ?? array();
	$tracking_number = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';

	$items = array();
	if ( ! empty( $artist_payout['items'] ) ) {
		foreach ( $artist_payout['items'] as $item_data ) {
			$product_id = $item_data['product_id'] ?? 0;
			$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			$items[] = array(
				'product_id' => $product_id,
				'name'       => $product ? $product->get_name() : 'Unknown Product',
				'quantity'   => $item_data['quantity'] ?? 1,
				'total'      => (float) ( $item_data['line_total'] ?? 0 ),
			);
		}
	}

	$shipping = $order->get_address( 'shipping' );
	$billing  = $order->get_address( 'billing' );
	$address  = ! empty( $shipping['address_1'] ) ? $shipping : $billing;

	$ships_free_only = false;
	if ( ! empty( $artist_payout['items'] ) && is_array( $artist_payout['items'] ) ) {
		$ships_free_only = true;
		foreach ( $artist_payout['items'] as $item_data ) {
			$pid = $item_data['product_id'] ?? 0;
			if ( $pid && '1' !== get_post_meta( $pid, '_ships_free', true ) ) {
				$ships_free_only = false;
				break;
			}
		}
	}

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
		'artist_payout'   => (float) ( $artist_payout['artist_payout'] ?? 0 ),
		'order_total'     => (float) ( $artist_payout['total'] ?? 0 ),
		'tracking_number' => $tracking_number,
		'ships_free_only' => $ships_free_only,
	);
}
