<?php
/**
 * Stripe Refund stand-in for ticket tests.
 *
 * @package ExtraChillShop
 */

namespace Stripe;

class Refund {
	public static function create( $params, $options ) {
		if ( ! empty( $GLOBALS['ticket_refund_fail'] ) ) {
			throw new \RuntimeException( 'Stripe unavailable' ); }
		$GLOBALS['ticket_refunds'][] = array( $params, $options );
		return (object) array(
			'id'       => 're_ticket',
			// No `amount` refunds the whole charge, Service fee included.
			'amount'   => $params['amount'] ?? ( $GLOBALS['ticket_charge_amount'] ?? 2000 ),
			'created'  => 1700000000,
			'metadata' => (object) $params['metadata'],
		);
	}

	/** Lists `$GLOBALS['stripe_refund_list']`; calls go to `$GLOBALS['stripe_refund_list_calls']`. */
	public static function all( $params, $options ) {
		$GLOBALS['stripe_refund_list_calls'][] = array( $params, $options );
		$rows                                  = $GLOBALS['stripe_refund_list'] ?? array();
		return new class( $rows ) {
			public function __construct( private array $rows ) {}
			public function autoPagingIterator() { return new \ArrayIterator( $this->rows ); }
		};
	}
}
