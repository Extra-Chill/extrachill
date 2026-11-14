<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-list-orders
 *
 * List orders containing products from a specific seller.
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
			'label'               => __( 'List Shop Orders', 'extrachill-shop' ),
			'description'         => __( 'List orders containing products from a specific seller, with filtering and pagination.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'seller'   => extrachill_shop_seller_input_schema(),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'needs_fulfillment', 'completed' ),
						'default'     => 'all',
						'description' => 'Filter by fulfilment status.',
					),
					'page'     => array(
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Page number.',
					),
					'per_page' => array(
						'type'        => 'integer',
						'default'     => 20,
						'description' => 'Items per page.',
					),
				),
			),
			'output_schema'       => array(
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
 * List a seller's orders.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_list_orders( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	$status   = (string) ( $input['status'] ?? 'all' );
	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$args = array(
		'limit'    => $per_page,
		'paged'    => $page,
		'paginate' => true,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'status'   => array( 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-on-hold' ),
	);
	$args = extrachill_shop_scope_order_query_to_seller( $args, $seller );
	if ( 'needs_fulfillment' === $status ) {
		$args['status'] = array( 'wc-processing', 'wc-on-hold' );
	} elseif ( 'completed' === $status ) {
		$args['status'] = array( 'wc-completed', 'wc-refunded' );
	}
	$result                  = wc_get_orders( $args );
	$count_args              = array_merge( $args, array(
		'limit'  => 1,
		'paged'  => 1,
		'return' => 'ids',
		'status' => array( 'wc-processing', 'wc-on-hold' ),
	) );
	$needs_fulfillment_count = (int) wc_get_orders( $count_args )->total;
	$total                   = (int) $result->total;
	$total_pages             = (int) $result->max_num_pages;
	$paged_orders            = $result->orders;

	$response_orders = array();
	foreach ( $paged_orders as $order ) {
		$response_orders[] = extrachill_shop_ability_build_order_response( $order );
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
 * Whether every line in a seller's portion of an order ships free.
 *
 * @param mixed $items Seller order lines (`product_id` each).
 * @return bool False for an empty portion.
 */
function extrachill_shop_items_ship_free_only( $items ): bool {
	if ( empty( $items ) || ! is_array( $items ) ) {
		return false;
	}
	foreach ( $items as $item_data ) {
		$product_id = (int) ( $item_data['product_id'] ?? 0 );
		if ( $product_id && '1' !== get_post_meta( $product_id, '_ships_free', true ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Build a seller's view of an order from the canonical order records.
 *
 * @param WC_Order $order WooCommerce order.
 * @return array
 */
function extrachill_shop_ability_build_order_response( $order ): array {
	$record   = extrachill_shop_get_order_seller_record( $order );
	$shipment = extrachill_shop_get_order_shipment( $order );

	$items = array();
	foreach ( $record['items'] ?? array() as $item_data ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $item_data['product_id'] ) : null;
		$items[] = array(
			'product_id' => $item_data['product_id'],
			'name'       => $product ? $product->get_name() : 'Unknown Product',
			'quantity'   => $item_data['quantity'],
			'total'      => $item_data['line_total'],
		);
	}

	$shipping = $order->get_address( 'shipping' );
	$billing  = $order->get_address( 'billing' );
	$address  = ! empty( $shipping['address_1'] ) ? $shipping : $billing;

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
		'seller_payout'   => (float) ( $record['seller_payout'] ?? 0 ),
		'order_total'     => (float) ( $record['total'] ?? 0 ),
		'tracking_number' => $shipment['tracking_number'],
		'label_url'       => $shipment['label_url'],
		'ships_free_only' => extrachill_shop_items_ship_free_only( $record['items'] ?? array() ),
	);
}
