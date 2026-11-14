<?php
/**
 * `wp extrachill shop order` — over extrachill/shop-get-order and
 * extrachill/shop-refund-order.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Inspect or refund one Shop order. */
class OrderCommand extends ShopCommandBase {

	/**
	 * Show one order: seller, Stripe references, items, refunds and Priority Boost receipts.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Order ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shop order show 1234 --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function show( $args, $assoc_args ) {
		$order = $this->run( 'extrachill/shop-get-order', array( 'id' => $this->positive_int( $args[0] ?? '', 'Order ID' ) ) );

		if ( $this->wants_json( $assoc_args ) ) {
			$this->print_json( $order );
			return;
		}

		$seller = (array) ( $order['seller'] ?? array() );
		$this->print_fields(
			array(
				'id'               => $order['id'] ?? '',
				'number'           => $order['number'] ?? '',
				'status'           => $order['status'] ?? '',
				'created'          => $order['date_created'] ?? '',
				'paid'             => $order['date_paid'] ?? '',
				'seller'           => $seller ? $seller['type'] . ':' . $seller['id'] : 'platform',
				'customer'         => trim( ( $order['customer']['name'] ?? '' ) . ' <' . ( $order['customer']['email'] ?? '' ) . '>' ),
				'payment_method'   => $order['payment_method'] ?? '',
				'stripe_account'   => $order['stripe_account'] ?? '',
				'checkout_session' => $order['checkout_session'] ?? '',
				'payment_intent'   => $order['payment_intent'] ?? '',
				'total'            => sprintf( '%s %s', $order['total'] ?? 0, $order['currency'] ?? '' ),
				'shipping'         => $order['shipping_total'] ?? 0,
				'refunded'         => $order['total_refunded'] ?? 0,
			)
		);

		$items = array();
		foreach ( (array) ( $order['items'] ?? array() ) as $item ) {
			$boost   = $item['priority_boost'] ?? null;
			$items[] = array(
				'item_id' => $item['item_id'] ?? '',
				'product' => $item['product_id'] ?? '',
				'name'    => $item['name'] ?? '',
				'qty'     => $item['quantity'] ?? '',
				'total'   => $item['total'] ?? '',
				'boost'   => $boost ? ( $boost['event'] . ' (' . ( $boost['granted'] ? 'granted' : 'NOT granted' ) . ')' ) : '',
			);
		}
		if ( $items ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Items:' );
			Utils\format_items( 'table', $items, array( 'item_id', 'product', 'name', 'qty', 'total', 'boost' ) );
		}
		if ( ! empty( $order['refunds'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Refunds:' );
			Utils\format_items( 'table', (array) $order['refunds'], array( 'id', 'amount', 'reason', 'date' ) );
		}
	}

	/**
	 * Refund a seller order through Stripe.
	 *
	 * Asks for confirmation first; --yes skips it. The seller defaults to the
	 * order's seller.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Order ID.
	 *
	 * [--amount=<amount>]
	 * : Partial refund amount. Accepted only once extrachill/shop-refund-order
	 * declares an `amount` input; until then refunds are always full.
	 *
	 * [--reason=<reason>]
	 * : Reason recorded on the refund.
	 *
	 * [--seller=<seller>]
	 * : Seller as type:id. Defaults to the order's seller.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shop order refund 1234 --reason="Out of stock" --url=shop.extrachill.com --user=1
	 *     wp extrachill shop order refund 1234 --yes --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function refund( $args, $assoc_args ) {
		$order_id = $this->positive_int( $args[0] ?? '', 'Order ID' );
		$refund   = $this->ability( 'extrachill/shop-refund-order' );
		$input    = array( 'id' => $order_id );

		if ( isset( $assoc_args['amount'] ) ) {
			$schema = method_exists( $refund, 'get_input_schema' ) ? (array) $refund->get_input_schema() : array();
			if ( ! isset( $schema['properties']['amount'] ) ) {
				WP_CLI::error( 'extrachill/shop-refund-order only issues full refunds; --amount is not supported yet. Re-run without --amount for a full refund.' );
			}
			if ( ! is_numeric( $assoc_args['amount'] ) || (float) $assoc_args['amount'] <= 0 ) {
				WP_CLI::error( '--amount must be a positive number.' );
			}
			$input['amount'] = (float) $assoc_args['amount'];
		}
		if ( isset( $assoc_args['reason'] ) ) {
			$input['reason'] = (string) $assoc_args['reason'];
		}

		$order = $this->run( 'extrachill/shop-get-order', array( 'id' => $order_id ) );
		if ( isset( $assoc_args['seller'] ) ) {
			$input['seller'] = $this->parse_seller( $assoc_args['seller'] );
		} elseif ( ! empty( $order['seller'] ) ) {
			$input['seller'] = $order['seller'];
		} else {
			WP_CLI::error( sprintf( 'Order %d has no seller (platform order). Refund it from WooCommerce.', $order_id ) );
		}

		WP_CLI::confirm(
			sprintf(
				'Refund %s of order %d (%s, total %s %s, already refunded %s)?',
				isset( $input['amount'] ) ? $input['amount'] . ' ' . ( $order['currency'] ?? '' ) : 'the full amount',
				$order_id,
				$input['seller']['type'] . ':' . $input['seller']['id'],
				$order['total'] ?? 0,
				$order['currency'] ?? '',
				$order['total_refunded'] ?? 0
			),
			$assoc_args
		);

		$result = $refund->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$result = is_array( $result ) ? $result : array();

		if ( $this->wants_json( $assoc_args ) ) {
			$this->print_json( $result );
			return;
		}
		WP_CLI::success( sprintf( 'Refunded %s on order %d.', $result['refund_amount'] ?? '', $result['order_id'] ?? $order_id ) );
	}
}
