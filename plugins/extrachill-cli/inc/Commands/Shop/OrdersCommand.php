<?php
/**
 * `wp extrachill shop orders` — over extrachill/shop-operator-list-orders.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** List Shop orders. */
class OrdersCommand extends ShopCommandBase {

	/**
	 * List orders across sellers, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--seller=<seller>]
	 * : Only this seller, as type:id.
	 *
	 * [--status=<status>]
	 * : WooCommerce order status.
	 * ---
	 * default: any
	 * options:
	 *   - any
	 *   - pending
	 *   - processing
	 *   - on-hold
	 *   - completed
	 *   - cancelled
	 *   - refunded
	 *   - failed
	 * ---
	 *
	 * [--page=<page>]
	 * : Page number.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--per-page=<n>]
	 * : Orders per page (max 100).
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill shop orders list --url=shop.extrachill.com --user=1
	 *     wp extrachill shop orders list --seller=artist:12 --status=processing --url=shop.extrachill.com --user=1
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ) {
		$input = array(
			'status'   => (string) Utils\get_flag_value( $assoc_args, 'status', 'any' ),
			'page'     => $this->positive_int( Utils\get_flag_value( $assoc_args, 'page', '1' ), '--page' ),
			'per_page' => $this->positive_int( Utils\get_flag_value( $assoc_args, 'per-page', '20' ), '--per-page' ),
		);
		if ( isset( $assoc_args['seller'] ) ) {
			$input['seller'] = $this->parse_seller( $assoc_args['seller'] );
		}
		$result = $this->run( 'extrachill/shop-operator-list-orders', $input );

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'json' === $format ) {
			$this->print_json( $result );
			return;
		}

		$orders = (array) ( $result['orders'] ?? array() );
		if ( ! $orders ) {
			\WP_CLI::log( 'No orders found.' );
			return;
		}
		Utils\format_items( $format, $orders, array( 'id', 'number', 'status', 'date_created', 'seller', 'customer', 'total', 'currency' ) );
		if ( 'table' === $format ) {
			\WP_CLI::log( sprintf( 'Page %d of %d (%d orders).', $result['page'] ?? 1, max( 1, (int) ( $result['total_pages'] ?? 1 ) ), $result['total'] ?? 0 ) );
		}
	}
}
