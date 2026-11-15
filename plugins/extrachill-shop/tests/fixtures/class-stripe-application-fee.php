<?php
/**
 * Stripe PaymentIntent and ApplicationFee stand-ins for application fee refunds.
 *
 * `$GLOBALS['stripe_fee']` is the fee on the charge (`id`, `amount`,
 * `amount_refunded`); fee refunds add to `amount_refunded` like Stripe does.
 * Every call is recorded in `$GLOBALS['stripe_fee_calls']`.
 *
 * @package ExtraChillShop
 */

// phpcs:disable -- Test doubles.
namespace Stripe;

if ( ! class_exists( '\Stripe\PaymentIntent', false ) ) {
	class PaymentIntent {
		public static function retrieve( $params, $options = null ) {
			if ( ! empty( $GLOBALS['stripe_fee_fail'] ) ) {
				throw new \RuntimeException( 'Stripe unavailable' );
			}
			$GLOBALS['stripe_fee_calls'][] = array( 'pi.retrieve', $params, $options );
			return (object) array(
				'id'            => $params['id'],
				'latest_charge' => (object) array(
					'id'              => 'ch_merch',
					'application_fee' => $GLOBALS['stripe_fee']['id'] ?? null,
				),
			);
		}
	}
}

if ( ! class_exists( '\Stripe\ApplicationFee', false ) ) {
	class ApplicationFee {
		public static function retrieve( $id, $options = null ) {
			$GLOBALS['stripe_fee_calls'][] = array( 'fee.retrieve', $id, $options );
			return (object) $GLOBALS['stripe_fee'];
		}
		public static function createRefund( $id, $params = null, $options = null ) {
			if ( ! empty( $GLOBALS['stripe_fee_refund_fail'] ) ) {
				throw new \RuntimeException( 'Fee refund unavailable' );
			}
			$GLOBALS['stripe_fee_calls'][]          = array( 'fee.refund', $id, $params, $options );
			$GLOBALS['stripe_fee']['amount_refunded'] = ( $GLOBALS['stripe_fee']['amount_refunded'] ?? 0 ) + $params['amount'];
			return (object) array( 'id' => 'fr_1', 'amount' => $params['amount'] );
		}
	}
}
