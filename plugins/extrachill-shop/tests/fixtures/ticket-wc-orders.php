<?php
/**
 * WooCommerce order doubles for ticket tests.
 *
 * Ticket sales are WooCommerce orders with no products. CI has no
 * WooCommerce, so this models the parts ticket code uses: `wc_create_order()`,
 * product-less line items and fee lines, status changes (including
 * `payment_complete()` going straight to `completed` for an order with
 * nothing to ship), refunds through the shared `wc_create_refund()` model,
 * and a `wc_get_orders()` matcher for meta, customer and status queries.
 *
 * @package ExtraChillShop
 */

// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
// The trait first: woo-refunds.php may be mid-load when this file runs.
require_once __DIR__ . '/woo-refund-tracking.php';
require_once __DIR__ . '/woo-refunds.php';
require_once dirname( __DIR__ ) . '/SellerListingTest.php';

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	/** A line item; ticket lines have no product. */
	class WC_Order_Item_Product {
		public $name = '', $quantity = 1, $subtotal = 0.0, $total = 0.0, $meta = array();
		public function set_name( $name ) { $this->name = $name; }
		public function set_quantity( $quantity ) { $this->quantity = (int) $quantity; }
		public function set_subtotal( $amount ) { $this->subtotal = (float) $amount; }
		public function set_total( $amount ) { $this->total = (float) $amount; }
		public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function get_name() { return $this->name; }
		public function get_quantity() { return $this->quantity; }
		public function get_subtotal() { return $this->subtotal; }
		public function get_total() { return $this->total; }
		public function get_total_tax() { return 0; }
		public function get_product_id() { return 0; }
		public function get_product() { return false; }
		public function get_type() { return 'line_item'; }
	}
}
if ( ! class_exists( 'WC_Order_Item_Fee' ) ) {
	/** A fee line. */
	class WC_Order_Item_Fee {
		public $name = '', $amount = '0', $total = '0', $tax_status = 'taxable';
		public function set_name( $name ) { $this->name = $name; }
		public function set_tax_status( $status ) { $this->tax_status = $status; }
		public function set_amount( $amount ) { $this->amount = $amount; }
		public function set_total( $amount ) { $this->total = $amount; }
		public function get_name() { return $this->name; }
		public function get_total() { return (float) $this->total; }
		public function get_type() { return 'fee'; }
	}
}

/** A WooCommerce order as ticket code uses it. */
final class TicketTestOrder {
	use WooRefundTracking;

	public $status         = 'pending';
	public $meta           = array();
	public $items          = array();
	public $billing        = array( 'first_name' => '', 'last_name' => '', 'email' => '' );
	public $notes          = array();
	public $status_changes = array();
	public $currency       = 'USD';
	public $total          = '0';
	public $payment_method = '';
	public $transaction_id = '';
	public $paid           = 0;
	public $saves          = 0;
	public $deleted        = false;
	public $created;

	public function __construct( private int $id, public int $customer_id = 0, public string $created_via = '' ) {
		$this->created = time();
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_order_number() { return (string) $this->id; }
	public function get_customer_id() { return $this->customer_id; }
	public function get_status() { return $this->status; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
	public function update_status( $status, $note = '' ) {
		$this->status_changes[] = array( $this->status, $status );
		$this->status           = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
		return true;
	}
	/** Nothing to ship, so WooCommerce goes straight to `completed`. */
	public function payment_complete( $transaction_id = '' ) {
		++$this->paid;
		$this->transaction_id = (string) $transaction_id;
		$this->update_status( 'completed' );
		return true;
	}
	public function is_paid() { return $this->has_status( array( 'processing', 'completed' ) ); }
	public function mark_fully_refunded() { $this->update_status( 'refunded' ); }
	public function add_order_note( $note ) { $this->notes[] = $note; return count( $this->notes ); }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save() { ++$this->saves; return $this->id; }
	public function save_meta_data() { ++$this->saves; }
	public function delete( $force = false ) {
		$this->deleted = true;
		unset( $GLOBALS['test_orders'][ $this->id ] );
		return true;
	}
	public function add_item( $item ) { $this->items[] = $item; }
	public function get_items( $type = 'line_item' ) {
		return array_values( array_filter( $this->items, static fn( $item ) => in_array( $item->get_type(), (array) $type, true ) ) );
	}
	public function set_billing_first_name( $value ) { $this->billing['first_name'] = $value; }
	public function set_billing_last_name( $value ) { $this->billing['last_name'] = $value; }
	public function set_billing_email( $value ) { $this->billing['email'] = $value; }
	public function get_billing_first_name() { return $this->billing['first_name']; }
	public function get_billing_last_name() { return $this->billing['last_name']; }
	public function get_billing_email() { return $this->billing['email']; }
	public function set_currency( $value ) { $this->currency = $value; }
	public function get_currency() { return $this->currency; }
	public function set_payment_method( $value ) { $this->payment_method = $value; }
	public function set_payment_method_title( $value ) {}
	public function get_payment_method() { return $this->payment_method; }
	public function set_transaction_id( $value ) { $this->transaction_id = $value; }
	public function set_total( $value ) { $this->total = $value; }
	public function get_total() { return (float) $this->total; }
	public function get_shipping_total() { return 0.0; }
	public function get_date_created() {
		return new DateTimeImmutable( '@' . $this->created );
	}
}

if ( ! function_exists( 'wc_create_order' ) ) {
	function wc_create_order( $args = array() ) {
		if ( ! empty( $GLOBALS['test_wc_create_order_fail'] ) ) {
			return new WP_Error( 'order_failed', 'Could not create the order.' );
		}
		$GLOBALS['test_order_seq']    = ( $GLOBALS['test_order_seq'] ?? 0 ) + 1;
		$id                           = 500 + $GLOBALS['test_order_seq'];
		$order                        = new TicketTestOrder( $id, (int) ( $args['customer_id'] ?? 0 ), (string) ( $args['created_via'] ?? '' ) );
		$order->status                = (string) ( $args['status'] ?? 'pending' );
		$GLOBALS['test_orders'][ $id ] = $order;
		return $order;
	}
}

/**
 * Whether an order matches one meta clause (or a nested OR/AND group).
 *
 * @param object $order  Order.
 * @param array  $clause Meta query clause.
 * @return bool
 */
function ticket_test_meta_clause_matches( $order, array $clause ): bool {
	if ( isset( $clause['relation'] ) ) {
		$groups  = array_filter( $clause, 'is_array' );
		$matches = array_map( static fn( $group ) => ticket_test_meta_clause_matches( $order, $group ), $groups );
		return 'OR' === $clause['relation'] ? in_array( true, $matches, true ) : ! in_array( false, $matches, true );
	}
	$exists  = array_key_exists( $clause['key'], $order->meta );
	$value   = (string) ( $order->meta[ $clause['key'] ] ?? '' );
	$compare = $clause['compare'] ?? '=';
	if ( 'NOT EXISTS' === $compare ) {
		return ! $exists;
	}
	if ( 'EXISTS' === $compare ) {
		return $exists;
	}
	if ( '!=' === $compare ) {
		return $exists && (string) $clause['value'] !== $value;
	}
	return $exists && (string) $clause['value'] === $value;
}

/**
 * `wc_get_orders()` over `$GLOBALS['test_orders']`, honoring the query vars
 * ticket and merch code pass. Every query is logged to
 * `$GLOBALS['ticket_order_queries']`.
 *
 * @param array $args Order query args.
 * @return array|object
 */
function ticket_test_wc_get_orders( array $args ) {
	$GLOBALS['ticket_order_queries'][] = $args;
	$clauses                           = array_merge( (array) ( $args['meta_query'] ?? array() ), (array) ( $args['extrachill_meta_query'] ?? array() ) );
	if ( isset( $args['meta_key'] ) ) {
		$clauses[] = array( 'key' => $args['meta_key'], 'value' => $args['meta_value'] );
	}
	if ( isset( $args['extrachill_seller'] ) ) {
		$clauses[] = array( 'key' => '_extrachill_seller', 'value' => $args['extrachill_seller'] );
	}
	$orders = array_values( array_filter( $GLOBALS['test_orders'] ?? array(), static function ( $order ) use ( $args, $clauses ) {
		if ( ! $order instanceof TicketTestOrder ) {
			return false;
		}
		if ( isset( $args['customer_id'] ) && (int) $args['customer_id'] !== $order->get_customer_id() ) {
			return false;
		}
		if ( isset( $args['status'] ) && ! in_array( 'wc-' . $order->get_status(), (array) $args['status'], true ) ) {
			return false;
		}
		foreach ( $clauses as $clause ) {
			if ( ! ticket_test_meta_clause_matches( $order, $clause ) ) {
				return false;
			}
		}
		return true;
	} ) );
	if ( 'DESC' === ( $args['order'] ?? 'DESC' ) ) {
		$orders = array_reverse( $orders );
	}
	$total = count( $orders );
	$limit = (int) ( $args['limit'] ?? -1 );
	if ( $limit > 0 ) {
		$orders = array_slice( $orders, ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $limit, $limit );
	}
	if ( 'ids' === ( $args['return'] ?? '' ) ) {
		$orders = array_map( static fn( $order ) => $order->get_id(), $orders );
	}
	return empty( $args['paginate'] ) ? $orders : (object) array(
		'orders'        => $orders,
		'total'         => $total,
		'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
	);
}
