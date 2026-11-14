<?php
/**
 * `wp extrachill shop boost` — over extrachill/shop-retry-priority-boost.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Priority Boost recovery. */
class BoostCommand extends ShopCommandBase {

	/**
	 * Retry ungranted Priority Boost grants on a paid order.
	 *
	 * Items that already have a receipt are skipped, and the Events owner is
	 * idempotent per order item, so re-running is safe.
	 *
	 * ## OPTIONS
	 *
	 * <order>
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
	 *     wp extrachill shop boost retry 1234 --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function retry( $args, $assoc_args ) {
		$result = $this->run( 'extrachill/shop-retry-priority-boost', array( 'order_id' => $this->positive_int( $args[0] ?? '', 'Order ID' ) ) );

		if ( $this->wants_json( $assoc_args ) ) {
			$this->print_json( $result );
			return;
		}

		$items = (array) ( $result['items'] ?? array() );
		Utils\format_items(
			'table',
			array_map(
				static function ( $item ) {
					return array(
						'item_id' => $item['item_id'] ?? '',
						'event'   => $item['event'] ?? '',
						'result'  => $item['result'] ?? '',
						'error'   => $item['error'] ?? '',
					);
				},
				$items
			),
			array( 'item_id', 'event', 'result', 'error' )
		);

		$failed = array_filter( $items, static fn( $item ) => 'error' === ( $item['result'] ?? '' ) );
		if ( $failed ) {
			WP_CLI::error( sprintf( '%d Priority Boost grant(s) still failing.', count( $failed ) ) );
		}
		WP_CLI::success( 'Every Priority Boost on this order is granted.' );
	}
}
