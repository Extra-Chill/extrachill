<?php
/**
 * `wp extrachill shop webhook` — over extrachill/shop-replay-webhook.
 *
 * @package ExtraChill\CLI\Commands\Shop
 */

namespace ExtraChill\CLI\Commands\Shop;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stripe webhook recovery. */
class WebhookCommand extends ShopCommandBase {

	/**
	 * Fetch a Stripe event from Stripe and route it through the Shop webhook router again.
	 *
	 * Use after a missed or failed delivery. The event is read from the
	 * Stripe API, not supplied by the caller, and the handlers are idempotent.
	 *
	 * ## OPTIONS
	 *
	 * <event-id>
	 * : Stripe event ID (evt_...).
	 *
	 * [--account=<acct>]
	 * : Connected account the event belongs to (acct_...). Required for Connect events such as merch and ticket checkouts.
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
	 *     wp extrachill shop webhook replay evt_1Abc --account=acct_1Xyz --url=shop.extrachill.com --user=1
	 *
	 * @when after_wp_load
	 */
	public function replay( $args, $assoc_args ) {
		$input = array( 'event_id' => (string) ( $args[0] ?? '' ) );
		if ( ! preg_match( '/^evt_[A-Za-z0-9]+$/', $input['event_id'] ) ) {
			WP_CLI::error( 'Event ID must look like evt_...' );
		}
		if ( isset( $assoc_args['account'] ) ) {
			$input['account'] = (string) $assoc_args['account'];
			if ( ! preg_match( '/^acct_[A-Za-z0-9]+$/', $input['account'] ) ) {
				WP_CLI::error( '--account must look like acct_...' );
			}
		}

		$result = $this->run( 'extrachill/shop-replay-webhook', $input );
		if ( $this->wants_json( $assoc_args ) ) {
			$this->print_json( $result );
			return;
		}
		WP_CLI::success(
			sprintf(
				'Replayed %s (%s%s, %s).',
				$result['event_id'] ?? $input['event_id'],
				$result['type'] ?? '',
				! empty( $result['account'] ) ? ' on ' . $result['account'] : '',
				! empty( $result['livemode'] ) ? 'live' : 'test'
			)
		);
	}
}
