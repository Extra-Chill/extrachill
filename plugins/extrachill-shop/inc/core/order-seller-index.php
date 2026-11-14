<?php
/**
 * Canonical order seller record.
 *
 * A merch order holds products from at most one seller (checkout refuses mixed
 * carts), so every seller fact lives in one set of scalar order meta keys:
 *
 * - `_extrachill_seller`          `type:id` seller key; queryable.
 * - `_extrachill_stripe_account`  Connected account the order is charged on.
 * - `_extrachill_application_fee` Platform fee in minor units (cents).
 * - `_extrachill_shipment`        Label and tracking record.
 *
 * The seller is derived from line items when the order is created or edited,
 * and frozen once checkout binds the order to a Stripe account.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

// These hooks run after line items have been persisted (classic and Store API).
add_action( 'woocommerce_checkout_order_created', 'extrachill_shop_index_order_seller' );
add_action( 'woocommerce_store_api_checkout_order_processed', 'extrachill_shop_index_order_seller' );
add_action( 'woocommerce_saved_order_items', 'extrachill_shop_index_order_seller' );
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'extrachill_shop_order_seller_cpt_query', 10, 2 );

/**
 * Translate the seller constraint for the post-backed order data store.
 *
 * @param array $query WP_Query args.
 * @param array $vars  Order query vars.
 * @return array
 */
function extrachill_shop_order_seller_cpt_query( $query, $vars ) {
	if ( ! empty( $vars['extrachill_seller'] ) ) {
		$query['meta_query'][] = array(
			'key'   => '_extrachill_seller',
			'value' => $vars['extrachill_seller'],
		);
	}
	return $query;
}

/**
 * Scope a `wc_get_orders()` query to one seller.
 *
 * HPOS supports meta_query natively; the CPT adapter translates our query var.
 *
 * @param array $args   Order query args.
 * @param array $seller Seller reference.
 * @return array
 */
function extrachill_shop_scope_order_query_to_seller( array $args, array $seller ): array {
	$seller_key = extrachill_shop_seller_ref_key( $seller );
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The order seller is order meta.
			array(
				'key'   => '_extrachill_seller',
				'value' => $seller_key,
			),
		);
	} else {
		$args['extrachill_seller'] = $seller_key;
	}
	return $args;
}

/**
 * Derive the order seller from its line items. Safe to replay.
 *
 * Platform and mixed orders have no seller. Once checkout has bound the order
 * to a seller's Stripe account the seller never changes.
 *
 * @param int|WC_Order $order Order or order ID.
 */
function extrachill_shop_index_order_seller( $order ) {
	$order = is_object( $order ) ? $order : wc_get_order( $order );
	if ( ! $order || $order->get_meta( '_extrachill_checkout_session' ) ) {
		return;
	}
	$ids = array();
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$ids[] = $item->get_product_id();
	}
	$seller  = extrachill_shop_single_seller( $ids );
	$desired = is_array( $seller ) ? extrachill_shop_seller_ref_key( $seller ) : '';
	if ( (string) $order->get_meta( '_extrachill_seller' ) === $desired ) {
		return;
	}
	if ( '' === $desired ) {
		$order->delete_meta_data( '_extrachill_seller' );
	} else {
		$order->update_meta_data( '_extrachill_seller', $desired );
	}
	$order->save_meta_data();
}

/**
 * The order's seller.
 *
 * @param WC_Order $order Order.
 * @return array|null Seller reference, or null for platform orders.
 */
function extrachill_shop_get_order_seller( $order ): ?array {
	return extrachill_shop_parse_seller_key( $order->get_meta( '_extrachill_seller' ) );
}

/**
 * Whether an order belongs to a seller.
 *
 * @param WC_Order $order  Order.
 * @param array    $seller Seller reference.
 * @return bool
 */
function extrachill_shop_order_has_seller( $order, array $seller ): bool {
	$owner = extrachill_shop_get_order_seller( $order );
	return null !== $owner && $owner['type'] === $seller['type'] && $owner['id'] === (int) $seller['id'];
}

/**
 * The seller's money and items for an order.
 *
 * The fee is the one checkout charged. Orders that never reached checkout
 * (admin-created) show the fee checkout would charge.
 *
 * @param WC_Order $order Order.
 * @return array|null { seller, stripe_account, total, application_fee, seller_payout, items[] }, null for platform orders.
 */
function extrachill_shop_get_order_seller_record( $order ): ?array {
	$seller = extrachill_shop_get_order_seller( $order );
	if ( null === $seller ) {
		return null;
	}

	$items        = array();
	$computed_fee = 0;
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$items[]       = array(
			'product_id' => (int) $item->get_product_id(),
			'quantity'   => (int) $item->get_quantity(),
			'line_total' => (float) $item->get_total(),
		);
		$computed_fee += (int) round( extrachill_shop_calculate_commission( $item->get_total(), $item->get_product_id() ) * 100 );
	}

	$total_minor = (int) round( (float) $order->get_total() * 100 );
	$stored_fee  = $order->get_meta( '_extrachill_application_fee' );
	$fee_minor   = '' === $stored_fee ? $computed_fee : (int) $stored_fee;
	$fee_minor   = min( $total_minor, max( 0, $fee_minor ) );

	return array(
		'seller'          => $seller,
		'stripe_account'  => (string) $order->get_meta( '_extrachill_stripe_account' ),
		'total'           => (float) ( $total_minor / 100 ),
		'application_fee' => (float) ( $fee_minor / 100 ),
		'seller_payout'   => (float) ( ( $total_minor - $fee_minor ) / 100 ),
		'items'           => $items,
	);
}

/**
 * The order's shipment: purchased label and/or tracking number.
 *
 * @param WC_Order $order Order.
 * @return array{label_url: string, tracking_number: string, tracking_url: string, carrier: string, service: string, cost: float, purchased_at: string}
 */
function extrachill_shop_get_order_shipment( $order ): array {
	$stored = $order->get_meta( '_extrachill_shipment' );
	$stored = is_array( $stored ) ? $stored : array();

	return array(
		'label_url'       => (string) ( $stored['label_url'] ?? '' ),
		'tracking_number' => (string) ( $stored['tracking_number'] ?? '' ),
		'tracking_url'    => (string) ( $stored['tracking_url'] ?? '' ),
		'carrier'         => (string) ( $stored['carrier'] ?? '' ),
		'service'         => (string) ( $stored['service'] ?? '' ),
		'cost'            => (float) ( $stored['cost'] ?? 0 ),
		'purchased_at'    => (string) ( $stored['purchased_at'] ?? '' ),
	);
}

/**
 * Merge fields into the order's shipment record. The caller saves the order.
 *
 * @param WC_Order $order  Order.
 * @param array    $fields Shipment fields, plus internal Shippo references.
 */
function extrachill_shop_update_order_shipment( $order, array $fields ): void {
	$stored = $order->get_meta( '_extrachill_shipment' );
	$stored = is_array( $stored ) ? $stored : array();
	$order->update_meta_data( '_extrachill_shipment', array_merge( $stored, $fields ) );
}
