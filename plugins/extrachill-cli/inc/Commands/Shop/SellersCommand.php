<?php
/**
 * `wp extrachill shop sellers` — over extrachill/shop-list-sellers.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** List Shop sellers. */
class SellersCommand extends ShopCommandBase {

	/**
	 * List every seller with Shop products or a Stripe Connect record.
	 *
	 * Shows cached Stripe state. Use `wp extrachill shop seller status` for a
	 * live check against Stripe.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Only this seller type.
	 * ---
	 * options:
	 *   - artist
	 *   - venue
	 *   - promoter
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
	 *     wp extrachill shop sellers list --url=shop.extrachill.com --user=1
	 *     wp extrachill shop sellers list --type=venue --format=json --url=shop.extrachill.com --user=1
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ) {
		$input = array();
		if ( isset( $assoc_args['type'] ) ) {
			$input['type'] = (string) $assoc_args['type'];
		}
		$sellers = (array) ( $this->run( 'extrachill/shop-list-sellers', $input )['sellers'] ?? array() );

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'json' === $format ) {
			$this->print_json( $sellers );
			return;
		}

		$rows = array();
		foreach ( $sellers as $seller ) {
			$rows[] = array(
				'seller'    => $seller['key'] ?? '',
				'name'      => $seller['name'] ?? '',
				'products'  => $seller['products'] ?? 0,
				'stripe'    => $seller['stripe_account'] ?? '',
				'status'    => $seller['stripe_status'] ?? '',
				'onboarded' => ! empty( $seller['onboarding_complete'] ) ? 'yes' : 'no',
				'ship_from' => ! empty( $seller['ship_from'] ) ? 'yes' : 'no',
				'platform'  => ! empty( $seller['platform'] ) ? 'yes' : 'no',
			);
		}
		Utils\format_items( $format, $rows, array( 'seller', 'name', 'products', 'stripe', 'status', 'onboarded', 'ship_from', 'platform' ) );
	}
}
