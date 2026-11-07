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
					'seller'    => extrachill_shop_seller_input_schema(),
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
 * List orders for an artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_list_orders( array $input ): array|WP_Error {
	$authorized = extrachill_shop_authorize_seller_input( $input );
	if ( is_wp_error( $authorized ) ) {
		return $authorized;
	}
	$seller   = extrachill_shop_seller_from_input( $input );
	$status   = (string) ( $input['status'] ?? 'all' );
	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$seller_key = $seller['type'] . ':' . $seller['id'];
	$args       = array(
		'limit'    => $per_page,
		'paged'    => $page,
		'paginate' => true,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'status'   => array( 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-on-hold' ),
	);
	// HPOS supports meta_query natively; the CPT adapter translates our query var.
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$args['meta_query'] = array(
			array(
				'key'   => '_extrachill_order_seller',
				'value' => $seller_key,
			),
		);
	} else {
		$args['extrachill_order_seller'] = $seller_key;
	}
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
		$response_orders[] = extrachill_shop_ability_build_order_response( $order, extrachill_shop_seller_key( $seller ) );
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
function extrachill_shop_ability_build_order_response( $order, int|string $artist_id ): array {
	$payouts         = $order->get_meta( '_artist_payouts' ) ?: array();
	$artist_payout   = $payouts[ $artist_id ] ?? array();
	$tracking_number = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';

	// Orders may be indexed before payment projections exist. Never include
	// another seller's lines when constructing the compatible response shape.
	if ( empty( $artist_payout['items'] ) ) {
		$artist_payout['items'] = array();
		$artist_payout['total'] = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$seller = extrachill_shop_get_product_payout_seller( $item->get_product_id() );
			if ( ! $seller || extrachill_shop_seller_key( $seller ) !== $artist_id ) {
				continue;
			}
			$artist_payout['items'][] = array(
				'product_id' => $item->get_product_id(),
				'quantity'   => $item->get_quantity(),
				'line_total' => $item->get_total(),
			);
			$artist_payout['total']  += (float) $item->get_total();
		}
	}

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
