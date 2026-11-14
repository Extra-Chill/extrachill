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
		extrachill_shop_resolve_stripe_webhook_secret()
	);

	// This untrusted hint only selects a signing key; constructEvent authenticates
	// the entire payload (including account) before any routing or state change.
	$hint = json_decode( $payload, true );
	if ( ! empty( $hint['account'] ) ) {
		$webhook_secret = apply_filters( 'extrachill_stripe_connect_webhook_secret', '' );
	}

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
 * Platform destination: `checkout.session.*` for platform-owned products.
 * Connect destination: `checkout.session.*` for seller merch and tickets,
 * `charge.refunded` and `charge.dispute.created` for tickets and merch
 * disputes, and `account.updated` for seller payability.
 *
 * @param \Stripe\Event $event Stripe event object.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function extrachill_shop_route_webhook_event( $event ) {
	$event_type = $event->type;
	$data       = $event->data->object;
	$account    = (string) ( $event->account ?? '' );
	$kind       = (string) ( $data->metadata->kind ?? '' );

	if ( str_starts_with( $event_type, 'checkout.session.' ) ) {
		return extrachill_shop_route_checkout_session_event( $event_type, $data, $account, $kind );
	}
	if ( 'account.updated' === $event_type && '' !== $account ) {
		return extrachill_shop_handle_account_updated( $data );
	}
	if ( '' !== $account && in_array( $event_type, array( 'charge.refunded', 'charge.dispute.created' ), true ) ) {
		// Charges and disputes do not inherit PaymentIntent metadata, so ticket
		// payments are recognised by the index written at fulfilment.
		$is_ticket = ! empty( $data->payment_intent ) && get_option( 'extrachill_ticket_payment_' . $account . '_' . $data->payment_intent );
		if ( 'charge.refunded' === $event_type ) {
			// Merch refunds start in WooCommerce, so only tickets need this.
			return $is_ticket ? extrachill_shop_ticket_charge_refunded( $data, $account ) : true;
		}
		return $is_ticket ? extrachill_shop_ticket_charge_disputed( $data, $account ) : extrachill_shop_merch_charge_disputed( $data, $account );
	}

	do_action( 'extrachill_shop_unhandled_webhook', $event_type, $data );
	return true;
}

/**
 * Route a Checkout Session event by the kind recorded in its metadata.
 *
 * @param string $event_type Event type.
 * @param object $session    Checkout Session.
 * @param string $account    Connected account ('' for the platform account).
 * @param string $kind       `extrachill_merch` or `extrachill_ticket`.
 * @return bool|WP_Error
 */
function extrachill_shop_route_checkout_session_event( $event_type, $session, $account, $kind ) {
	$paid = in_array( $event_type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true );
	if ( 'extrachill_merch' === $kind ) {
		if ( $paid ) {
			return extrachill_shop_fulfil_merch_session( $session, $account );
		}
		if ( 'checkout.session.expired' === $event_type ) {
			return extrachill_shop_close_merch_session( $session, $account, 'expired' );
		}
		if ( 'checkout.session.async_payment_failed' === $event_type ) {
			return extrachill_shop_close_merch_session( $session, $account, 'failed' );
		}
		return true;
	}
	// Tickets are always charged on the seller's account.
	if ( 'extrachill_ticket' === $kind && '' !== $account ) {
		if ( $paid ) {
			return extrachill_shop_fulfil_ticket_session( $session, $account );
		}
		if ( 'checkout.session.expired' === $event_type ) {
			return extrachill_shop_close_ticket_session( $session, $account, 'expired' );
		}
		if ( 'checkout.session.async_payment_failed' === $event_type ) {
			return extrachill_shop_close_ticket_session( $session, $account, 'failed' );
		}
	}
	return true;
}

/**
 * Handle account.updated webhook event.
 *
 * Stores the seller's payability, which checkout reads instead of asking
 * Stripe on every cart render.
 *
 * @param object $account Stripe account object.
 * @return bool True on success.
 */
function extrachill_shop_handle_account_updated( $account ) {
	$updated = extrachill_shop_update_account_status_cache(
		$account->id,
		array(
			'charges_enabled'   => $account->charges_enabled,
			'payouts_enabled'   => $account->payouts_enabled,
			'details_submitted' => $account->details_submitted,
		)
	);
	if ( ! $updated ) {
		// A retry cannot bind an account no seller owns, so acknowledge it.
		error_log( 'Extra Chill account.updated for an account no seller is bound to: ' . $account->id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility for an unknown connected account.
	}
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
