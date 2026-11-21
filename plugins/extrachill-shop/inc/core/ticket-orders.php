<?php
/**
 * Ticket orders.
 *
 * Every ticket sale is a WooCommerce order (HPOS) with no product and no
 * cart. The buyer pays the seller directly on the seller's connected Stripe
 * account; Events issues the passes. The order is the record of the sale:
 *
 * - Line item "Event tickets: <show>" with no product: quantity = tickets,
 *   total = ticket subtotal, item meta `event_id`.
 * - Fee line "Service fee" when the buyer pays one.
 * - Billing name and email; `customer_id` is the logged-in buyer (0 for guests).
 * - Payment method `extrachill_ticket_checkout` (no registered gateway, so
 *   WooCommerce never refunds a ticket order through a gateway).
 *
 * Order meta:
 *
 * - `_extrachill_order_kind`        `extrachill_ticket`; merch orders have none.
 * - `_extrachill_seller`            `type:id` seller key (the merch seller index).
 * - `_extrachill_stripe_account`    Connected account the payment is on.
 * - `_extrachill_ticket_event`      Event post ID (queryable).
 * - `_extrachill_ticket_reference`  Order reference shared with Events: the
 *                                   Checkout Session ID passes are keyed by.
 * - `_extrachill_checkout_sessions` `{ session ID: account }`, as merch.
 * - `_extrachill_payment_intent`    PaymentIntent, written at payment.
 * - `_extrachill_ticket_amounts`    Split of what the buyer paid (minor units):
 *                                   subtotal, service fee, total, application
 *                                   fee, currency, processing payer.
 * - `_extrachill_fee_payer`         Stripe `controller.fees.payer` at checkout.
 * - `_extrachill_ticket_hold_until`  Unix time the checkout's inventory hold
 *                                   ends (the Checkout Session's expiry).
 * - `_extrachill_ticket_refund_attempts` Payment refunds tried, so each try
 *                                   gets its own Stripe idempotency key.
 * - `_extrachill_terms_accepted_at`, `_extrachill_terms_version`.
 * - `_extrachill_ticket_refund_pending` Set between revoking passes and the
 *                                   refund succeeding.
 * - `_extrachill_ticket_refund_reason` Why the order was refunded.
 * - `_extrachill_dispute`, `_extrachill_dispute_prior_status`,
 *   `_extrachill_dispute_outcome`   Bank disputes, as merch.
 *
 * Status mapping (extrachill_shop_ticket_order_state()):
 *
 * | Ticket state     | WooCommerce                                   |
 * |------------------|-----------------------------------------------|
 * | pending          | `pending` (Checkout Session open)             |
 * | expired / failed | `cancelled` / `failed` (Session ended unpaid) |
 * | issued           | `completed` (or `processing`)                 |
 * | refund_pending   | issued + `_extrachill_ticket_refund_pending`  |
 * | refunded         | `refunded` (a `wc_create_refund` record)      |
 * | disputed         | `on-hold` (with `_extrachill_dispute` while open) |
 *
 * Ticket orders have nothing to ship, so merch behavior is switched off: no
 * stock changes, no WooCommerce order emails, no place in the seller's Orders
 * tab (Needs Fulfillment, labels, status changes, merch refunds).
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/** Order meta naming what kind of sale an order records. */
const EXTRACHILL_SHOP_ORDER_KIND_META = '_extrachill_order_kind';

/** Order kind of a ticket sale. */
const EXTRACHILL_SHOP_TICKET_KIND = 'extrachill_ticket';

/** Payment method recorded on ticket orders. */
const EXTRACHILL_SHOP_TICKET_PAYMENT_METHOD = 'extrachill_ticket_checkout';

/** Order meta: the order reference passes are keyed by (Checkout Session ID). */
const EXTRACHILL_SHOP_TICKET_REFERENCE_META = '_extrachill_ticket_reference';

/** Order meta: the event post ID. */
const EXTRACHILL_SHOP_TICKET_EVENT_META = '_extrachill_ticket_event';

/** Order meta: the payment split in minor units. */
const EXTRACHILL_SHOP_TICKET_AMOUNTS_META = '_extrachill_ticket_amounts';

/** Order meta: refund started but not yet succeeded (`seller_refund` or `sold_out`). */
const EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META = '_extrachill_ticket_refund_pending';

/** Order meta: Unix time the checkout's ticket hold ends. */
const EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META = '_extrachill_ticket_hold_until';

/** How long a ticket checkout holds its tickets: the Checkout Session lifetime (Stripe's minimum). */
const EXTRACHILL_SHOP_TICKET_HOLD_SECONDS = 1800;

/** Order meta: payment refunds tried on the order. */
const EXTRACHILL_SHOP_TICKET_REFUND_ATTEMPTS_META = '_extrachill_ticket_refund_attempts';

/** Order meta: why the order was refunded. */
const EXTRACHILL_SHOP_TICKET_REFUND_REASON_META = '_extrachill_ticket_refund_reason';

/** Order meta: show title and URL at checkout. */
const EXTRACHILL_SHOP_TICKET_EVENT_TITLE_META = '_extrachill_ticket_event_title';
const EXTRACHILL_SHOP_TICKET_EVENT_URL_META   = '_extrachill_ticket_event_url';

/**
 * WooCommerce emails a ticket order never sends. Events emails the passes,
 * refunds and dispute notices; `new_order`, `cancelled_order` and
 * `failed_order` would tell the site admin about sales Extra Chill does not
 * fulfil, and every abandoned ticket checkout.
 */
const EXTRACHILL_SHOP_TICKET_SUPPRESSED_EMAILS = array(
	'new_order',
	'cancelled_order',
	'failed_order',
	'customer_on_hold_order',
	'customer_processing_order',
	'customer_completed_order',
	'customer_refunded_order',
	'customer_partially_refunded_order',
	'customer_cancelled_order',
	'customer_failed_order',
	'customer_invoice',
	'customer_note',
);

foreach ( EXTRACHILL_SHOP_TICKET_SUPPRESSED_EMAILS as $extrachill_shop_email_id ) {
	add_filter( 'woocommerce_email_enabled_' . $extrachill_shop_email_id, 'extrachill_shop_suppress_ticket_order_email', 10, 2 );
}
unset( $extrachill_shop_email_id );
add_filter( 'woocommerce_can_reduce_order_stock', 'extrachill_shop_ticket_order_keeps_stock', 10, 2 );
add_filter( 'woocommerce_my_account_my_orders_query', 'extrachill_shop_exclude_ticket_orders' );

/**
 * Whether an order records a ticket sale.
 *
 * @param mixed $order Order, or anything else.
 * @return bool
 */
function extrachill_shop_order_is_ticket( $order ): bool {
	return is_object( $order ) && method_exists( $order, 'get_meta' ) && EXTRACHILL_SHOP_TICKET_KIND === (string) $order->get_meta( EXTRACHILL_SHOP_ORDER_KIND_META );
}

/**
 * Keep WooCommerce's order emails off ticket orders.
 *
 * @param bool  $enabled Whether the email is enabled.
 * @param mixed $order   Email object (an order for order emails).
 * @return bool
 */
function extrachill_shop_suppress_ticket_order_email( $enabled, $order = null ) {
	return extrachill_shop_order_is_ticket( $order ) ? false : $enabled;
}

/**
 * Ticket orders never touch stock.
 *
 * @param bool  $can   Whether stock can be reduced.
 * @param mixed $order Order.
 * @return bool
 */
function extrachill_shop_ticket_order_keeps_stock( $can, $order = null ) {
	return extrachill_shop_order_is_ticket( $order ) ? false : $can;
}

/**
 * Meta clause matching orders that are not ticket orders.
 *
 * @return array
 */
function extrachill_shop_not_ticket_meta_clause(): array {
	return array(
		'relation' => 'OR',
		array(
			'key'     => EXTRACHILL_SHOP_ORDER_KIND_META,
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => EXTRACHILL_SHOP_ORDER_KIND_META,
			'value'   => EXTRACHILL_SHOP_TICKET_KIND,
			'compare' => '!=',
		),
	);
}

/**
 * Leave ticket orders out of a `wc_get_orders()` query (merch Orders tab,
 * shipping labels, My Account > Orders).
 *
 * @param mixed $args Order query args.
 * @return array
 */
function extrachill_shop_exclude_ticket_orders( $args ) {
	return extrachill_shop_add_order_meta_clause( is_array( $args ) ? $args : array(), extrachill_shop_not_ticket_meta_clause() );
}

/**
 * Scope a `wc_get_orders()` query to ticket orders, plus more meta clauses.
 *
 * @param array   $args    Order query args.
 * @param array[] $clauses Extra meta clauses (all must match).
 * @return array
 */
function extrachill_shop_ticket_order_query( array $args, array $clauses = array() ): array {
	array_unshift(
		$clauses,
		array(
			'key'   => EXTRACHILL_SHOP_ORDER_KIND_META,
			'value' => EXTRACHILL_SHOP_TICKET_KIND,
		)
	);
	foreach ( $clauses as $clause ) {
		$args = extrachill_shop_add_order_meta_clause( $args, $clause );
	}
	return $args;
}

/**
 * The ticket order with this order reference.
 *
 * @param string $reference Order reference (Checkout Session ID).
 * @return WC_Order|null
 */
function extrachill_shop_get_ticket_order( string $reference ) {
	if ( '' === $reference || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}
	/** @var WC_Order[] $orders Not paginated. */
	$orders = wc_get_orders(
		extrachill_shop_ticket_order_query(
			array( 'limit' => 1 ),
			array(
				array(
					'key'   => EXTRACHILL_SHOP_TICKET_REFERENCE_META,
					'value' => $reference,
				),
			)
		)
	);
	return $orders ? $orders[0] : null;
}

/**
 * Paid ticket orders for one show that may still be refunded.
 *
 * @param int $event_id Event post ID.
 * @return WC_Order[]
 */
function extrachill_shop_get_event_ticket_orders( int $event_id ): array {
	if ( $event_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	/** @var WC_Order[] $orders Not paginated. */
	$orders = wc_get_orders(
		extrachill_shop_ticket_order_query(
			array(
				'limit'   => -1,
				'status'  => array( 'wc-processing', 'wc-completed' ),
				'orderby' => 'date',
				'order'   => 'ASC',
			),
			array(
				array(
					'key'   => EXTRACHILL_SHOP_TICKET_EVENT_META,
					'value' => (string) $event_id,
				),
			)
		)
	);
	return $orders;
}

/**
 * Tickets held by unfinished checkouts for one show.
 *
 * Events counts only issued passes against capacity, so tickets in an open
 * Checkout Session are held here: a pending ticket order holds its quantity
 * until its Session expires, and for as long as it is paid but not yet
 * issued (its PaymentIntent is stored before the passes are). Expired,
 * failed, issued and refunded orders hold nothing.
 *
 * @param int $event_id         Event post ID.
 * @param int $exclude_order_id Order not to count (0 for none).
 * @param int $now              Current Unix time (0 for now).
 * @return int
 */
function extrachill_shop_held_ticket_quantity( int $event_id, int $exclude_order_id = 0, int $now = 0 ): int {
	if ( $event_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}
	$now = $now > 0 ? $now : time();
	/** @var WC_Order[] $orders Not paginated. */
	$orders = wc_get_orders(
		extrachill_shop_ticket_order_query(
			array(
				'limit'  => -1,
				'status' => array( 'wc-pending' ),
			),
			array(
				array(
					'key'   => EXTRACHILL_SHOP_TICKET_EVENT_META,
					'value' => (string) $event_id,
				),
			)
		)
	);

	$held = 0;
	foreach ( $orders as $order ) {
		if ( $exclude_order_id > 0 && (int) $order->get_id() === $exclude_order_id ) {
			continue;
		}
		$paid   = '' !== (string) $order->get_meta( '_extrachill_payment_intent' );
		$active = (int) $order->get_meta( EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META ) > $now;
		if ( $paid || $active ) {
			$held += extrachill_shop_ticket_order_quantity( $order );
		}
	}
	return $held;
}

/**
 * The ticket order a PaymentIntent paid on a connected account.
 *
 * @param string $intent  PaymentIntent ID.
 * @param string $account Connected account.
 * @return WC_Order|null
 */
function extrachill_shop_find_ticket_order_by_payment_intent( string $intent, $account ) {
	$order = extrachill_shop_find_order_by_payment_intent( $intent, $account );
	return extrachill_shop_order_is_ticket( $order ) ? $order : null;
}

/**
 * The ticket state of an order (see the status mapping above).
 *
 * @param WC_Order $order Ticket order.
 * @return string pending|expired|failed|issued|refund_pending|refunded|disputed
 */
function extrachill_shop_ticket_order_state( $order ): string {
	if ( $order->has_status( 'refunded' ) ) {
		return 'refunded';
	}
	if ( $order->has_status( 'on-hold' ) ) {
		return 'disputed';
	}
	if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
		return '' !== (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META ) ? 'refund_pending' : 'issued';
	}
	if ( $order->has_status( 'cancelled' ) ) {
		return 'expired';
	}
	return $order->has_status( 'failed' ) ? 'failed' : 'pending';
}

/**
 * The payment split recorded at checkout, in minor units.
 *
 * @param WC_Order $order Ticket order.
 * @return array{subtotal_minor: int, service_fee_minor: int, total_minor: int, application_fee_minor: int, currency: string, processing_payer: string}
 */
function extrachill_shop_ticket_order_amounts( $order ): array {
	$stored = $order->get_meta( EXTRACHILL_SHOP_TICKET_AMOUNTS_META );
	$stored = is_array( $stored ) ? $stored : array();
	return array(
		'subtotal_minor'        => (int) ( $stored['subtotal_minor'] ?? 0 ),
		'service_fee_minor'     => (int) ( $stored['service_fee_minor'] ?? 0 ),
		'total_minor'           => (int) ( $stored['total_minor'] ?? round( (float) $order->get_total() * 100 ) ),
		'application_fee_minor' => (int) ( $stored['application_fee_minor'] ?? 0 ),
		'currency'              => (string) ( $stored['currency'] ?? strtolower( (string) $order->get_currency() ) ),
		'processing_payer'      => (string) ( $stored['processing_payer'] ?? 'account' ),
	);
}

/**
 * Tickets on the order (the ticket line's quantity).
 *
 * @param WC_Order $order Ticket order.
 * @return int
 */
function extrachill_shop_ticket_order_quantity( $order ): int {
	$quantity = 0;
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$quantity += (int) $item->get_quantity();
	}
	return $quantity;
}

/**
 * Create the pending ticket order for a checkout.
 *
 * @param array $sale {
 *     @type int    $buyer     Buyer user ID (0 for guests).
 *     @type string $name      Buyer name.
 *     @type string $email     Buyer email.
 *     @type array  $seller    Seller reference.
 *     @type string $account   Connected account.
 *     @type string $fee_payer Stripe processing fee payer.
 *     @type array  $offer     Event ticket offer (event_id, title, url, currency).
 *     @type int    $quantity  Tickets.
 *     @type array  $amounts   Payment split (extrachill_shop_ticket_order_amounts()).
 *     @type array  $terms     terms_accepted_at, terms_version.
 *     @type int    $hold_until Unix time the inventory hold ends.
 * }
 * @return WC_Order|WP_Error
 */
function extrachill_shop_create_ticket_order( array $sale ) {
	if ( ! function_exists( 'wc_create_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 503 ) );
	}
	$order = wc_create_order(
		array(
			'status'      => 'pending',
			'customer_id' => (int) $sale['buyer'],
			'created_via' => EXTRACHILL_SHOP_TICKET_KIND,
		)
	);
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$amounts  = $sale['amounts'];
	$offer    = $sale['offer'];
	$event_id = (int) $offer['event_id'];

	$item = new WC_Order_Item_Product();
	$item->set_name( 'Event tickets: ' . (string) $offer['title'] );
	$item->set_quantity( (int) $sale['quantity'] );
	$item->set_subtotal( (string) ( $amounts['subtotal_minor'] / 100 ) );
	$item->set_total( (string) ( $amounts['subtotal_minor'] / 100 ) );
	$item->add_meta_data( 'event_id', (string) $event_id, true );
	$order->add_item( $item );
	if ( $amounts['service_fee_minor'] > 0 ) {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Service fee' );
		$fee->set_tax_status( 'none' );
		$fee->set_amount( (string) ( $amounts['service_fee_minor'] / 100 ) );
		$fee->set_total( (string) ( $amounts['service_fee_minor'] / 100 ) );
		$order->add_item( $fee );
	}

	$name = explode( ' ', trim( (string) $sale['name'] ), 2 );
	$order->set_billing_first_name( $name[0] );
	$order->set_billing_last_name( $name[1] ?? '' );
	$order->set_billing_email( (string) $sale['email'] );
	$order->set_currency( strtoupper( (string) $amounts['currency'] ) );
	$order->set_payment_method( EXTRACHILL_SHOP_TICKET_PAYMENT_METHOD );
	$order->set_payment_method_title( 'Stripe Checkout' );
	$order->set_total( (string) ( $amounts['total_minor'] / 100 ) );

	$seller = $sale['seller'];
	$order->update_meta_data( EXTRACHILL_SHOP_ORDER_KIND_META, EXTRACHILL_SHOP_TICKET_KIND );
	$order->update_meta_data( '_extrachill_seller', extrachill_shop_seller_ref_key( $seller ) );
	// Immutable account binding: refunds never depend on a seller's later account changes.
	$order->update_meta_data( '_extrachill_stripe_account', (string) $sale['account'] );
	$order->update_meta_data( '_extrachill_fee_payer', (string) $sale['fee_payer'] );
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_AMOUNTS_META, $amounts );
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_EVENT_META, (string) $event_id );
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_EVENT_TITLE_META, (string) $offer['title'] );
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_EVENT_URL_META, (string) $offer['url'] );
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_HOLD_UNTIL_META, (string) (int) ( $sale['hold_until'] ?? 0 ) );
	foreach ( $sale['terms'] as $key => $value ) {
		$order->update_meta_data( '_extrachill_' . $key, (string) $value );
	}
	$order->save();
	return $order;
}
