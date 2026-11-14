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
			'amount'   => 2000,
			'created'  => 1700000000,
			'metadata' => (object) $params['metadata'],
		);
	}
}
