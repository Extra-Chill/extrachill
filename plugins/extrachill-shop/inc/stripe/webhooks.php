<?php
/**
 * Stripe Webhook Handler
 *
 * Handles incoming Stripe webhook events for payment processing and connected
 * account status updates. Verifies webhook signatures and routes events to
 * appropriate handlers.
 *
 * Webhook secret is configured via Network Admin > Extra Chill Multisite > Payments.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;


/**
 * Handle incoming Stripe webhook.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_shop_handle_webhook( $request ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return new WP_Error( 'stripe_not_configured', 'Stripe is not configured.', array( 'status' => 500 ) );
	}

	$payload    = $request->get_body();
	$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

	if ( empty( $sig_header ) ) {
		return new WP_Error( 'missing_signature', 'Missing Stripe signature.', array( 'status' => 400 ) );
	}

	$webhook_secret = apply_filters(
		'extrachill_stripe_webhook_secret',
		get_site_option( 'extrachill_stripe_webhook_secret', '' )
	);

	if ( empty( $webhook_secret ) ) {
		return new WP_Error( 'webhook_secret_missing', 'Webhook secret not configured.', array( 'status' => 500 ) );
	}

	try {
		$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $webhook_secret );
	} catch ( \UnexpectedValueException $e ) {
		return new WP_Error( 'invalid_payload', 'Invalid payload.', array( 'status' => 400 ) );
	} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
		return new WP_Error( 'invalid_signature', 'Invalid signature.', array( 'status' => 400 ) );
	}

	$result = extrachill_shop_route_webhook_event( $event );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( array( 'received' => true ), 200 );
}

/**
 * Route webhook event to appropriate handler.
 *
 * @param \Stripe\Event $event Stripe event object.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function extrachill_shop_route_webhook_event( $event ) {
	$event_type = $event->type;
	$data       = $event->data->object;

	switch ( $event_type ) {
		case 'account.updated':
			return extrachill_shop_handle_account_updated( $data );

		case 'payment_intent.succeeded':
			return extrachill_shop_handle_payment_succeeded( $data );

		case 'payment_intent.payment_failed':
			return extrachill_shop_handle_payment_failed( $data );

		case 'charge.refunded':
			return extrachill_shop_handle_charge_refunded( $data );

		default:
			// Log unhandled event types for debugging.
			do_action( 'extrachill_shop_unhandled_webhook', $event_type, $data );
			return true;
	}
}

/**
 * Handle account.updated webhook event.
 *
 * Updates local cache when connected account status changes.
 *
 * @param object $account Stripe account object.
 * @return bool True on success.
 */
function extrachill_shop_handle_account_updated( $account ) {
	$account_id   = $account->id;
	$account_data = array(
		'charges_enabled'   => $account->charges_enabled,
		'payouts_enabled'   => $account->payouts_enabled,
		'details_submitted' => $account->details_submitted,
	);

	extrachill_shop_update_account_status_cache( $account_id, $account_data );

	do_action( 'extrachill_shop_stripe_account_updated', $account_id, $account_data );

	return true;
}

/**
 * Handle payment_intent.succeeded webhook event.
 *
 * Updates order status when payment completes successfully.
 *
 * @param object $payment_intent Stripe PaymentIntent object.
 * @return bool True on success.
 */
function extrachill_shop_handle_payment_succeeded( $payment_intent ) {
	$order_id = isset( $payment_intent->metadata->order_id ) ? absint( $payment_intent->metadata->order_id ) : 0;

	if ( ! $order_id ) {
		return true;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return true;
	}

	// Only process if order is still pending.
	if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		return true;
	}

	$order->payment_complete( $payment_intent->id );

	$artist_id = isset( $payment_intent->metadata->artist_id ) ? absint( $payment_intent->metadata->artist_id ) : 0;
	$order->add_order_note(
		sprintf(
			/* translators: 1: Payment intent ID, 2: Artist ID */
			__( 'Stripe payment successful. PaymentIntent: %1$s. Artist ID: %2$d', 'extrachill-shop' ),
			$payment_intent->id,
			$artist_id
		)
	);

	do_action( 'extrachill_shop_payment_succeeded', $order, $payment_intent );

	return true;
}

/**
 * Handle payment_intent.payment_failed webhook event.
 *
 * Updates order status when payment fails.
 *
 * @param object $payment_intent Stripe PaymentIntent object.
 * @return bool True on success.
 */
function extrachill_shop_handle_payment_failed( $payment_intent ) {
	$order_id = isset( $payment_intent->metadata->order_id ) ? absint( $payment_intent->metadata->order_id ) : 0;

	if ( ! $order_id ) {
		return true;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return true;
	}

	$error_message = '';
	if ( isset( $payment_intent->last_payment_error->message ) ) {
		$error_message = $payment_intent->last_payment_error->message;
	}

	$order->update_status( 'failed' );
	$order->add_order_note(
		sprintf(
			/* translators: 1: Payment intent ID, 2: Error message */
			__( 'Stripe payment failed. PaymentIntent: %1$s. Error: %2$s', 'extrachill-shop' ),
			$payment_intent->id,
			$error_message
		)
	);

	do_action( 'extrachill_shop_payment_failed', $order, $payment_intent, $error_message );

	return true;
}

/**
 * Handle charge.refunded webhook event.
 *
 * Logs refund information on the order.
 *
 * @param object $charge Stripe Charge object.
 * @return bool True on success.
 */
function extrachill_shop_handle_charge_refunded( $charge ) {
	$payment_intent_id = $charge->payment_intent;

	if ( ! $payment_intent_id ) {
		return true;
	}

	// Find order by payment intent stored in charges meta.
	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_query' => array(
				array(
					'key'     => '_stripe_charges',
					'value'   => $payment_intent_id,
					'compare' => 'LIKE',
				),
			),
		)
	);

	if ( empty( $orders ) ) {
		return true;
	}

	$order         = $orders[0];
	$refund_amount = $charge->amount_refunded / 100;

	$order->add_order_note(
		sprintf(
			/* translators: 1: Refund amount, 2: Currency, 3: Charge ID */
			__( 'Stripe refund processed: %1$s %2$s. Charge: %3$s', 'extrachill-shop' ),
			number_format( $refund_amount, 2 ),
			strtoupper( $charge->currency ),
			$charge->id
		)
	);

	do_action( 'extrachill_shop_charge_refunded', $order, $charge, $refund_amount );

	return true;
}

/**
 * Log webhook event for debugging.
 *
 * @param string $event_type Event type.
 * @param object $data       Event data.
 */
function extrachill_shop_log_webhook_event( $event_type, $data ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'ExtraChill Shop Webhook: ' . $event_type );
	}
}
add_action( 'extrachill_shop_unhandled_webhook', 'extrachill_shop_log_webhook_event', 10, 2 );
