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
 * and frozen once checkout binds the order to a Stripe account
 * (`_extrachill_checkout_sessions`).
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
	if ( ! empty( $vars['extrachill_exclude_disputed'] ) ) {
		$query['meta_query'][] = extrachill_shop_not_disputed_meta_clause();
	}
	foreach ( (array) ( $vars['extrachill_meta_query'] ?? array() ) as $clause ) {
		$query['meta_query'][] = $clause;
	}
	return $query;
}

/**
 * Add one meta clause to a `wc_get_orders()` query.
 *
 * HPOS takes `meta_query` natively; the CPT adapter appends
 * `extrachill_meta_query` clauses (extrachill_shop_order_seller_cpt_query()).
 *
 * @param array $args   Order query args.
 * @param array $clause Meta query clause.
 * @return array
 */
function extrachill_shop_add_order_meta_clause( array $args, array $clause ): array {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Order facts are order meta.
		$args['meta_query'][] = $clause;
	} else {
		$args['extrachill_meta_query'][] = $clause;
	}
	return $args;
}

/**
 * Meta query clause matching orders with no open dispute.
 *
 * @return array
 */
function extrachill_shop_not_disputed_meta_clause(): array {
	return array(
		'relation' => 'OR',
		array(
			'key'     => '_extrachill_dispute',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'   => '_extrachill_dispute',
			'value' => '',
		),
	);
}

/**
 * Leave disputed orders out of a `wc_get_orders()` query.
 *
 * Disputed orders are on hold but must not be fulfilled, so Needs
 * Fulfillment leaves them out. Same HPOS/CPT split as
 * extrachill_shop_scope_order_query_to_seller().
 *
 * @param array $args Order query args.
 * @return array
 */
function extrachill_shop_exclude_disputed_orders( array $args ): array {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The dispute flag is order meta.
		$args['meta_query'][] = extrachill_shop_not_disputed_meta_clause();
	} else {
		$args['extrachill_exclude_disputed'] = true;
	}
	return $args;
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
	// Ticket orders have no products; ticket checkout records their seller.
	if ( ! $order || $order->get_meta( '_extrachill_checkout_sessions' ) || extrachill_shop_order_is_ticket( $order ) ) {
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
 * Whether a merch order belongs to a seller.
 *
 * Ticket orders never match: the merch tools (status, labels, refunds) do not
 * apply to them. Ticket refunds check the seller themselves.
 *
 * @param WC_Order $order  Order.
 * @param array    $seller Seller reference.
 * @return bool
 */
function extrachill_shop_order_has_seller( $order, array $seller ): bool {
	if ( extrachill_shop_order_is_ticket( $order ) ) {
		return false;
	}
	$owner = extrachill_shop_get_order_seller( $order );
	return null !== $owner && $owner['type'] === $seller['type'] && $owner['id'] === (int) $seller['id'];
}

/**
 * The seller's money and items for an order.
 *
 * The fee is the one checkout charged. Orders that never reached checkout
 * (admin-created) show the fee checkout would charge. The fee includes the
 * shipping hold while Extra Chill still holds it.
 *
 * @param WC_Order $order Order.
 * @return array|null { seller, stripe_account, total, application_fee, shipping_held, seller_payout, items[] }, null for platform and ticket orders.
 */
function extrachill_shop_get_order_seller_record( $order ): ?array {
	$seller = extrachill_shop_get_order_seller( $order );
	if ( null === $seller || extrachill_shop_order_is_ticket( $order ) ) {
		return null;
	}

	$items          = array();
	$subtotal_minor = 0;
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$items[]         = array(
			'product_id' => (int) $item->get_product_id(),
			'quantity'   => (int) $item->get_quantity(),
			'line_total' => (float) $item->get_total(),
		);
		$subtotal_minor += (int) round( (float) $item->get_total() * 100 );
	}

	$total_minor = (int) round( (float) $order->get_total() * 100 );
	$stored_fee  = $order->get_meta( '_extrachill_application_fee' );
	$fee_minor   = '' === $stored_fee ? extrachill_commerce_fee( 'extrachill_merch', $subtotal_minor, $seller )['application_fee'] : (int) $stored_fee;
	$fee_minor   = min( $total_minor, max( 0, $fee_minor ) );
	// Shipping returned to a seller who shipped without an Extra Chill label
	// was refunded out of the application fee (see shipping-recovery.php).
	$released_minor = min( $fee_minor, max( 0, (int) $order->get_meta( EXTRACHILL_SHOP_SHIPPING_RELEASED_META ) ) );
	$fee_minor     -= $released_minor;

	return array(
		'seller'          => $seller,
		'stripe_account'  => (string) $order->get_meta( '_extrachill_stripe_account' ),
		'total'           => (float) ( $total_minor / 100 ),
		'application_fee' => (float) ( $fee_minor / 100 ),
		'shipping_held'   => (float) ( extrachill_shop_order_shipping_hold( $order ) / 100 ),
		'seller_payout'   => (float) ( ( $total_minor - $fee_minor ) / 100 ),
		'items'           => $items,
	);
}

/**
 * The order's shipment: purchased label and/or tracking number.
 *
 * `cost` is the label postage; `shipping_held` is the buyer's shipping that
 * paid for it and `overage` any postage above that, paid by Extra Chill.
 *
 * @param WC_Order $order Order.
 * @return array{label_url: string, tracking_number: string, tracking_url: string, carrier: string, service: string, cost: float, shipping_held: float, overage: float, purchased_at: string}
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
		'shipping_held'   => (float) ( $stored['shipping_held'] ?? 0 ),
		'overage'         => (float) ( $stored['overage'] ?? 0 ),
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

/**
 * Whether an order is on hold for a buyer's bank dispute.
 *
 * The dispute webhook (`extrachill_shop_merch_charge_disputed()`) records
 * `_extrachill_dispute` and puts the order on hold. An operator who settles
 * the dispute moves the order off hold, which releases it again.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function extrachill_shop_order_is_disputed( $order ): bool {
	return '' !== (string) $order->get_meta( '_extrachill_dispute' ) && 'on-hold' === $order->get_status();
}

/**
 * The error a seller gets when they try to ship, label or refund a disputed order.
 *
 * @return WP_Error
 */
function extrachill_shop_order_disputed_error(): WP_Error {
	return new WP_Error(
		'order_disputed',
		__( 'The buyer disputed this payment with their bank. Do not ship this order until the dispute is settled. Respond to the dispute in your payout dashboard before the deadline.', 'extrachill-shop' ),
		array( 'status' => 409 )
	);
}
