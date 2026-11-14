<?php
/**
 * Stripe Checkout Session stand-in for ticket tests.
 *
 * @package ExtraChillShop
 */

namespace Stripe\Checkout;

class Session {
	public static function create( $params, $options ) {
		$GLOBALS['ticket_session_request'] = array( $params, $options );
		return (object) array(
			'id'  => 'cs_ticket',
			'url' => 'https://checkout.stripe.com/test',
		);
	}
}
