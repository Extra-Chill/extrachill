<?php
/**
 * `wp extrachill shop seller` — over extrachill/shop-stripe-status and
 * extrachill/shop-get-shipping-address.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Inspect one Shop seller. */
class SellerCommand extends ShopCommandBase {

	/**
	 * Show a seller's live Stripe Connect status and ship-from address.
	 *
	 * Asks Stripe for the account's current state (which also refreshes the
	 * Shop's cached record) and reads the seller's ship-from address.
	 *
	 * ## OPTIONS
	 *
	 * <seller>
	 * : Seller as type:id, e.g. artist:12, venue:1524, promoter:8.
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
	 *     wp extrachill shop seller status artist:12 --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$seller = $this->parse_seller( $args[0] ?? '' );
		$stripe = $this->run( 'extrachill/shop-stripe-status', array( 'seller' => $seller ) );
		$ship   = $this->run( 'extrachill/shop-get-shipping-address', array( 'seller' => $seller ) );

		if ( $this->wants_json( $assoc_args ) ) {
			$this->print_json(
				array(
					'seller'   => $seller,
					'stripe'   => $stripe,
					'shipping' => $ship,
				)
			);
			return;
		}

		$address = (array) ( $ship['address'] ?? array() );
		$this->print_fields(
			array(
				'seller'               => $seller['type'] . ':' . $seller['id'],
				'stripe_connected'     => ! empty( $stripe['connected'] ),
				'stripe_status'        => (string) ( $stripe['status'] ?? '' ),
				'can_receive_payments' => ! empty( $stripe['can_receive_payments'] ),
				'charges_enabled'      => ! empty( $stripe['charges_enabled'] ),
				'payouts_enabled'      => ! empty( $stripe['payouts_enabled'] ),
				'details_submitted'    => ! empty( $stripe['details_submitted'] ),
				'ship_from'            => empty( $ship['is_set'] ) ? 'not set' : implode( ', ', array_filter( array( $address['city'] ?? '', $address['state'] ?? '', $address['country'] ?? '' ) ) ),
			)
		);
	}
}
