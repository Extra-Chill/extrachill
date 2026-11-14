<?php
/**
 * Stripe Checkout Session stand-in for ticket tests.
 *
 * @package ExtraChillShop
 */

namespace Stripe\Checkout;

class Session {
	public $id;
	public $status;

	public static function create( $params, $options ) {
		$GLOBALS['ticket_session_request']    = array( $params, $options );
		$GLOBALS['stripe_sessions_created'][] = array( $params, $options );
		$id                                   = empty( $GLOBALS['stripe_session_ids'] ) ? 'cs_ticket' : array_shift( $GLOBALS['stripe_session_ids'] );
		return (object) array(
			'id'  => $id,
			'url' => 'https://checkout.stripe.com/test',
		);
	}

	public static function retrieve( $id, $options = null ) {
		if ( ! empty( $GLOBALS['stripe_session_retrieve_fail'] ) ) {
			throw new \RuntimeException( 'Stripe unavailable' );
		}
		$session                               = new self();
		$session->id                           = $id;
		$session->status                       = $GLOBALS['stripe_session_status'][ $id ] ?? 'open';
		$GLOBALS['stripe_session_retrieved'][] = array( $id, $options );
		return $session;
	}

	public function expire( $params = null, $options = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Signature mirrors the SDK.
		$GLOBALS['stripe_session_expired'][] = array( $this->id, $options );
		$this->status                        = 'expired';
		return $this;
	}
}
