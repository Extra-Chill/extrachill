<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-refund-order
 *
 * Issue a full refund for an artist's portion of an order.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_refund_order_ability' );

/**
 * Register the shop-refund-order ability.
 */
function extrachill_shop_register_refund_order_ability(): void {

	wp_register_ability(
		'extrachill/shop-refund-order',
		array(
			'label'       => __( 'Refund Shop Order', 'extrachill-shop' ),
			'description' => __( 'Issue a full Stripe refund for an artist\'s portion of an order.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => 'Order ID.',
					),
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
				),
				'required' => array( 'id', 'artist_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'order_id'      => array( 'type' => 'integer' ),
					'refund_amount' => array( 'type' => 'number' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_refund_order',
			'permission_callback' => static function ( array $input ): bool|WP_Error {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
				}
				$artist_id = isset( $input['artist_id'] ) ? (int) $input['artist_id'] : 0;
				if ( ! $artist_id ) {
					return new WP_Error( 'missing_artist_id', 'Artist ID is required.', array( 'status' => 400 ) );
				}
				if ( function_exists( 'extrachill_api_shop_user_can_manage_artist' ) ) {
					if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
						return new WP_Error( 'rest_forbidden', 'You do not have permission to manage this artist.', array( 'status' => 403 ) );
					}
					return true;
				}
				if ( function_exists( 'ec_can_manage_artist' ) ) {
					return ec_can_manage_artist( get_current_user_id(), $artist_id );
				}
				return current_user_can( 'manage_options' );
			},
			'meta' => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Issue a full refund for the artist's portion of an order.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_refund_order( array $input ): array|WP_Error {
	$order_id  = (int) ( $input['id'] ?? 0 );
	$artist_id = (int) ( $input['artist_id'] ?? 0 );

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}

	$payouts = $order->get_meta( '_artist_payouts' ) ?: array();
	if ( ! isset( $payouts[ $artist_id ] ) ) {
		return new WP_Error( 'invalid_artist', 'This order does not contain products from your artist.', array( 'status' => 400 ) );
	}

	$artist_payout = $payouts[ $artist_id ];
	$refund_amount = (float) ( $artist_payout['total'] ?? 0 );

	if ( $refund_amount <= 0 ) {
		return new WP_Error( 'invalid_refund_amount', 'No refundable amount found for this artist.', array( 'status' => 400 ) );
	}

	$charges            = $order->get_meta( '_stripe_charges' ) ?: array();
	$payment_intent_id  = $charges[ $artist_id ]['payment_intent_id'] ?? '';

	if ( ! $payment_intent_id ) {
		return new WP_Error( 'no_payment_intent', 'No payment intent found for this artist order.', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'extrachill_shop_stripe_init' ) || ! extrachill_shop_stripe_init() ) {
		return new WP_Error( 'stripe_not_configured', 'Stripe is not configured.', array( 'status' => 500 ) );
	}

	try {
		\Stripe\Refund::create( array(
			'payment_intent' => $payment_intent_id,
		) );
	} catch ( \Exception $e ) {
		return new WP_Error( 'refund_failed', 'Refund failed: ' . $e->getMessage(), array( 'status' => 500 ) );
	}

	$order->set_status( 'refunded', 'Full refund issued by artist.' );
	$order->save();

	return array(
		'success'       => true,
		'order_id'      => $order_id,
		'refund_amount' => $refund_amount,
	);
}
