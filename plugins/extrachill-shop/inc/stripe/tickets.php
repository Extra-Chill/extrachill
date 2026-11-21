<?php
/**
 * Seller-settled ticket payments, recorded as WooCommerce orders
 * (see ticket-orders.php). No cart, products or tax calculation.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

function extrachill_shop_start_ticket_checkout( array $input ) {
	if ( ! is_int( $input['quantity'] ?? null ) || $input['quantity'] < 1 || $input['quantity'] > 10 || empty( trim( $input['name'] ?? '' ) ) || ! is_email( $input['email'] ?? '' ) ) {
		return new WP_Error( 'invalid_ticket_input', 'A name, email and quantity from 1 to 10 are required.', array( 'status' => 400 ) );
	}
	// Ticket checkout skips the WooCommerce Checkout block, so it asks for the
	// same Terms consent itself and records it on the ticket order.
	if ( true !== ( $input['terms_accepted'] ?? null ) ) {
		return extrachill_shop_ticket_terms_required_error();
	}
	$terms       = array(
		'terms_accepted_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'terms_version'     => extrachill_shop_legal_document( 'terms', 'version' ),
	);
	$buyer       = get_current_user_id();
	$entered     = strtoupper( trim( (string) ( $input['code'] ?? '' ) ) );
	$offer_input = array( 'event' => $input['event'] );
	if ( '' !== $entered ) {
		$offer_input['code'] = $entered;
	}
	$offer = extrachill_shop_execute_owner_ability( 'events', 'extrachill/get-event-ticket-offer', 'GET', $offer_input );
	if ( is_wp_error( $offer ) ) {
		return $offer; }
	if ( empty( $offer['enabled'] ) || empty( $offer['sale_open'] ) ) {
		return new WP_Error( 'ticket_sale_closed', 'Ticket sales are closed.', array( 'status' => 409 ) ); }
	$priced = extrachill_shop_ticket_checkout_type( $offer, (string) ( $input['ticket_type'] ?? '' ), '' !== $entered );
	if ( is_wp_error( $priced ) ) {
		return $priced; }
	$ticket = $priced['ticket'];
	$code   = $priced['code'];
	// Name the type on the order and receipt unless it is a single-price show's only type.
	$ticket['named'] = count( (array) ( $offer['ticket_types'] ?? array() ) ) > 1 || EXTRACHILL_SHOP_DEFAULT_TICKET_TYPE !== $ticket['id'];
	if ( null !== $offer['remaining'] && $offer['remaining'] < $input['quantity'] ) {
		return new WP_Error( 'ticket_sold_out', 'Not enough tickets remain.', array( 'status' => 409 ) ); }
	if ( null !== $ticket['remaining'] && $ticket['remaining'] < $input['quantity'] ) {
		return new WP_Error( 'ticket_sold_out', sprintf( 'Not enough %s tickets remain.', $ticket['name'] ), array( 'status' => 409 ) ); }
	if ( $code && $code['max_uses'] > 0 && extrachill_shop_ticket_code_uses( (int) $offer['event_id'], $code['code'] ) + $input['quantity'] > $code['max_uses'] ) {
		return extrachill_shop_ticket_code_used_up_error(); }
	// Tickets in other open checkouts are held for them (see the hold check below).
	if ( extrachill_shop_ticket_holds_exceed( $offer, $ticket, $input['quantity'] ) ) {
		return extrachill_shop_tickets_held_error(); }
	$seller = extrachill_shop_validate_seller_ref( $offer['seller'] );
	if ( is_wp_error( $seller ) ) {
		return $seller; }
	$canonical = extrachill_shop_get_canonical_seller( $seller );
	if ( is_wp_error( $canonical ) ) {
		return $canonical; }
	$account = extrachill_shop_get_seller_stripe_account( $seller );
	if ( ! $account || ! extrachill_shop_stripe_init() || ! extrachill_shop_seller_can_receive_payments( $seller ) ) {
		return new WP_Error( 'seller_payments_unavailable', 'Seller cannot receive payments.', array( 'status' => 409 ) ); }
	$unit_price = $ticket['unit_price_minor'];
	if ( ! is_int( $unit_price ) || $unit_price < 1 ) {
		return new WP_Error( 'invalid_ticket_price', 'Invalid ticket price.' ); }
	// The buyer pays the Service fee; the venue nets the subtotal. The
	// application fee depends on who pays processing (see extrachill_commerce_ticket_fee()).
	// Rates resolve for this show and seller: global, then any seller and event override.
	// A code lowers the subtotal, and fees follow what the buyer actually pays.
	$subtotal  = $unit_price * $input['quantity'];
	$fee_payer = extrachill_shop_get_seller_fee_payer( $seller );
	$fees      = extrachill_commerce_fee( 'extrachill_ticket', $subtotal, $seller, $input['quantity'], $fee_payer, array( 'event_id' => (int) $offer['event_id'] ) );

	$amounts = array(
		'subtotal_minor'        => $subtotal,
		'service_fee_minor'     => $fees['buyer_fee'],
		'total_minor'           => $subtotal + $fees['buyer_fee'],
		'application_fee_minor' => $fees['application_fee'],
		'currency'              => $offer['currency'],
		// Who the application fee assumed pays processing: `account` or `platform`.
		'processing_payer'      => extrachill_commerce_processing_payer( $fee_payer ),
	);

	$expires_at = time() + EXTRACHILL_SHOP_TICKET_HOLD_SECONDS;
	$order      = extrachill_shop_create_ticket_order( array(
		'buyer'      => $buyer,
		'name'       => sanitize_text_field( $input['name'] ),
		'email'      => $input['email'],
		'seller'     => $seller,
		'account'    => $account,
		'fee_payer'  => $fee_payer,
		'offer'      => $offer,
		'ticket'     => $ticket,
		'code'       => $code ? $code['code'] : '',
		'quantity'   => $input['quantity'],
		'amounts'    => $amounts,
		'terms'      => $terms + array( 'venue_show_updates' => true === ( $input['venue_show_updates'] ?? false ) ? 'yes' : 'no' ),
		'hold_until' => $expires_at,
	) );
	if ( is_wp_error( $order ) ) {
		return new WP_Error( 'ticket_checkout_failed', 'Ticket checkout failed.', array( 'status' => 502 ) ); }
	// The order now holds its tickets. Re-count every hold, this one included:
	// two checkouts racing for the last tickets both see each other's holds
	// once both are saved, so at worst both step back; neither oversells.
	if ( extrachill_shop_ticket_holds_exceed( $offer, $ticket, 0 ) ) {
		$order->delete( true );
		return extrachill_shop_tickets_held_error(); }
	// Same for a capped code: racing checkouts see each other's uses.
	if ( $code && $code['max_uses'] > 0 && extrachill_shop_ticket_code_uses( (int) $offer['event_id'], $code['code'] ) > $code['max_uses'] ) {
		$order->delete( true );
		return extrachill_shop_ticket_code_used_up_error(); }

	$metadata = array(
		'event_id'    => (string) $offer['event_id'],
		'quantity'    => (string) $input['quantity'],
		'name'        => sanitize_text_field( $input['name'] ),
		'seller_type' => $seller['type'],
		'seller_id'   => (string) $seller['id'],
		'kind'        => EXTRACHILL_SHOP_TICKET_KIND,
		'order_id'    => (string) $order->get_id(),
		'ticket_type' => (string) $ticket['id'],
	) + $terms;
	if ( $code ) {
		$metadata['ticket_code'] = $code['code'];
	}
	if ( $buyer > 0 ) {
		$metadata['buyer_user_id'] = (string) $buyer;
	}
	$items = array(
		array(
			'quantity'   => $input['quantity'],
			'price_data' => array(
				'currency'     => $offer['currency'],
				'unit_amount'  => $unit_price,
				'product_data' => array( 'name' => extrachill_shop_ticket_line_name( $offer, $ticket, $code ) ),
			),
		),
	);
	if ( $fees['buyer_fee'] > 0 ) {
		$items[] = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => $offer['currency'],
				'unit_amount'  => $fees['buyer_fee'],
				'product_data' => array( 'name' => 'Service fee' ),
			),
		); }
	try {
		$session = \Stripe\Checkout\Session::create( array(
			'mode'                => 'payment',
			'line_items'          => $items,
			'customer_email'      => $input['email'],
			'metadata'            => $metadata,
			'payment_intent_data' => array(
				'application_fee_amount' => $fees['application_fee'],
				'metadata'               => $metadata,
			),
			'success_url'         => add_query_arg( 'tickets', 'success', $offer['url'] ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => add_query_arg( 'tickets', 'cancelled', $offer['url'] ),
			// Stripe closes the Session when the hold ends.
			'expires_at'          => $expires_at,
		), array( 'stripe_account' => $account ) );
	} catch ( \Exception $e ) {
		// Nobody ever saw this order; it would only clutter the order list.
		$order->delete( true );
		return new WP_Error( 'ticket_checkout_failed', 'Ticket checkout failed.', array( 'status' => 502 ) ); }
	// The Session ID stays the order reference Events keys the passes by.
	$order->update_meta_data( '_extrachill_checkout_sessions', array( $session->id => $account ) );
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_REFERENCE_META, $session->id );
	$order->save();
	return array(
		'checkout_url' => $session->url,
		'session_id'   => $session->id,
	);
}

/**
 * The ticket type a checkout buys, priced with the entered code.
 *
 * A show with one buyer-visible type sells it without asking. A code that
 * does not match, or does not work on the chosen type, is refused rather
 * than silently charging full price.
 *
 * @param array  $offer       Events ticket offer, read with the code.
 * @param string $type_id     Requested type ('' for the only one).
 * @param bool   $code_entered Whether the buyer entered a code.
 * @return array{ticket: array, code: array|null}|WP_Error
 */
function extrachill_shop_ticket_checkout_type( array $offer, string $type_id, bool $code_entered ) {
	$code = is_array( $offer['code'] ?? null ) ? $offer['code'] : null;
	if ( $code_entered && ( ! $code || ! empty( $offer['code_invalid'] ) ) ) {
		return new WP_Error( 'ticket_code_invalid', 'That code does not work for this show.', array( 'status' => 400 ) );
	}
	// An Events offer from before ticket types sells its one price.
	$types = isset( $offer['ticket_types'] ) ? array_values( (array) $offer['ticket_types'] ) : array(
		array(
			'id'               => EXTRACHILL_SHOP_DEFAULT_TICKET_TYPE,
			'name'             => 'General admission',
			'price_minor'      => $offer['price_minor'] ?? 0,
			'unit_price_minor' => $offer['price_minor'] ?? 0,
			'remaining'        => $offer['remaining'] ?? null,
			'code_applies'     => false,
		),
	);
	if ( '' === $type_id && 1 === count( $types ) ) {
		$type_id = (string) $types[0]['id'];
	}
	foreach ( $types as $type ) {
		if ( (string) $type['id'] !== $type_id ) {
			continue;
		}
		if ( $code && empty( $type['code_applies'] ) ) {
			return new WP_Error( 'ticket_code_not_applicable', sprintf( 'That code does not work on %s tickets.', $type['name'] ), array( 'status' => 400 ) );
		}
		if ( $code && (int) $type['unit_price_minor'] < 1 ) {
			return new WP_Error( 'ticket_code_free', 'That code makes the ticket free. Ask the host to put you on the guest list.', array( 'status' => 400 ) );
		}
		return array(
			'ticket' => array(
				'id'               => (string) $type['id'],
				'name'             => (string) $type['name'],
				'price_minor'      => (int) $type['price_minor'],
				'unit_price_minor' => (int) $type['unit_price_minor'],
				'remaining'        => null === $type['remaining'] ? null : (int) $type['remaining'],
			),
			'code'   => $code ? array(
				'code'     => (string) $code['code'],
				'max_uses' => (int) ( $code['max_uses'] ?? 0 ),
			) : null,
		);
	}
	return new WP_Error( 'ticket_type_unavailable', 'Choose a ticket type that is on sale.', array( 'status' => 400 ) );
}

/**
 * Whether open checkouts leave too few tickets for this one.
 *
 * Checks the show's remaining and the type's own remaining against the
 * holds on each. Pass 0 for $quantity after this checkout's own order is
 * saved, which then counts among the holds.
 *
 * @param array $offer    Events ticket offer.
 * @param array $ticket   Ticket type from extrachill_shop_ticket_checkout_type().
 * @param int   $quantity Tickets wanted, or 0 once this order holds them.
 * @return bool
 */
function extrachill_shop_ticket_holds_exceed( array $offer, array $ticket, int $quantity ): bool {
	if ( null === $offer['remaining'] && null === $ticket['remaining'] ) {
		return false;
	}
	$holds = extrachill_shop_held_ticket_quantities( (int) $offer['event_id'] );
	if ( null !== $offer['remaining'] && $offer['remaining'] - $holds['held'] < $quantity ) {
		return true;
	}
	return null !== $ticket['remaining'] && $ticket['remaining'] - (int) ( $holds['by_type'][ $ticket['id'] ] ?? 0 ) < $quantity;
}

/**
 * Stripe line item name: the show, plus the type and code when they add anything.
 *
 * @param array      $offer  Events ticket offer.
 * @param array      $ticket Ticket type.
 * @param array|null $code   Code used.
 * @return string
 */
function extrachill_shop_ticket_line_name( array $offer, array $ticket, ?array $code ): string {
	$name = (string) $offer['title'];
	if ( ! empty( $ticket['named'] ) ) {
		$name .= ' — ' . $ticket['name'];
	}
	if ( $code && $ticket['unit_price_minor'] < $ticket['price_minor'] ) {
		$name .= ' (code ' . $code['code'] . ')';
	}
	return $name;
}

/**
 * The ticket type to issue passes as. The default type is left out, so
 * single-price shows send exactly what they always did.
 *
 * @param WC_Order $order Ticket order.
 * @return array `{ ticket_type }` or empty.
 */
function extrachill_shop_ticket_issue_type( $order ): array {
	$type = extrachill_shop_ticket_order_type( $order );
	return EXTRACHILL_SHOP_DEFAULT_TICKET_TYPE === $type ? array() : array( 'ticket_type' => $type );
}

/**
 * Error for a code that has bought all the tickets it may.
 *
 * @return WP_Error
 */
function extrachill_shop_ticket_code_used_up_error(): WP_Error {
	return new WP_Error( 'ticket_code_used_up', 'That code has no tickets left. Try fewer tickets or another code.', array( 'status' => 409 ) );
}

/**
 * Error for a checkout whose tickets are all held by other open checkouts.
 *
 * @return WP_Error
 */
function extrachill_shop_tickets_held_error(): WP_Error {
	return new WP_Error(
		'tickets_held',
		'The remaining tickets are held in other checkouts. Try again in a few minutes.',
		array(
			'status'      => 409,
			'retry_after' => EXTRACHILL_SHOP_TICKET_HOLD_SECONDS,
		)
	);
}

/**
 * Error for a ticket checkout without Terms of Service acceptance.
 *
 * @return WP_Error
 */
function extrachill_shop_ticket_terms_required_error(): WP_Error {
	return new WP_Error(
		'terms_required',
		'Please agree to the Terms of Service to buy tickets.',
		array(
			'status'        => 400,
			'terms_url'     => extrachill_shop_legal_document( 'terms', 'url' ),
			'terms_version' => extrachill_shop_legal_document( 'terms', 'version' ),
		)
	);
}

/**
 * Report a ticket sale, or a refund of one, to Events' settlement ledger.
 *
 * Idempotent on Events' side: the sale is keyed by the order reference and
 * each refund by its Stripe refund (or dispute) ID.
 *
 * @param WC_Order    $order  Ticket order.
 * @param object|null $refund Stripe refund (or a dispute shaped like one).
 * @return true|WP_Error
 */
function extrachill_shop_record_ticket_sales( $order, $refund = null ) {
	$reference = (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META );
	$amounts   = extrachill_shop_ticket_order_amounts( $order );
	$tickets   = extrachill_shop_ticket_order_quantity( $order );
	$created   = $order->get_date_created();
	$input     = array(
		'event_id'           => (int) $order->get_meta( EXTRACHILL_SHOP_TICKET_EVENT_META ),
		'tickets_sold'       => $tickets,
		'gross_minor'        => $amounts['total_minor'],
		'fees_minor'         => $amounts['application_fee_minor'],
		'currency'           => $amounts['currency'],
		'period_start'       => gmdate( 'Y-m-d H:i:s', $created ? $created->getTimestamp() : time() ),
		'external_report_id' => $refund ? (string) ( $refund->id ?? '' ) : $reference,
		'order_reference'    => $reference,
	);
	if ( $refund ) {
		$refunded             = (int) ( $refund->amount ?? 0 );
		$input['gross_minor'] = - $refunded;
		$input['fees_minor']  = - (int) ( $refund->metadata->application_fee_minor ?? 0 );
		// Only a full refund cancels tickets; partial refunds are money only.
		$input['tickets_sold'] = $refunded === $amounts['total_minor'] ? -$tickets : 0;
		$input['period_start'] = gmdate( 'Y-m-d H:i:s', (int) ( $refund->created ?? time() ) );
	}
	$result = extrachill_shop_execute_ticket_owner_ability( 'record-checkout-ticket-sales', $input );
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Reason shown on the WooCommerce refund for a ticket refund reason.
 *
 * @param string $reason `seller_refund`, `sold_out` or `stripe_refund`.
 * @return string
 */
function extrachill_shop_ticket_refund_label( string $reason ): string {
	if ( 'sold_out' === $reason ) {
		return 'The show sold out before the tickets were issued.';
	}
	return 'seller_refund' === $reason ? 'Refunded by the seller.' : 'Refunded from the seller\'s payout dashboard.';
}

/**
 * Record money returned to the buyer as a WooCommerce refund, without
 * refunding the payment again.
 *
 * Idempotent: a Stripe refund (or dispute) already recorded on the order is
 * skipped, and the amount is capped at what the order has left to refund.
 * A fully refunded order becomes `refunded`.
 *
 * @param WC_Order $order     Ticket order.
 * @param string   $refund_id Stripe refund or dispute ID.
 * @param int      $minor     Amount returned, minor units.
 * @param string   $reason    Refund reason shown on the order.
 * @return true|WP_Error
 */
function extrachill_shop_record_ticket_refund( $order, string $refund_id, int $minor, string $reason ) {
	if ( '' === $refund_id || in_array( $refund_id, extrachill_shop_recorded_stripe_refunds( $order ), true ) ) {
		return true;
	}
	// Earlier refunds may have been recorded through another copy of the order.
	$fresh = wc_get_order( $order->get_id() );
	$order = $fresh instanceof WC_Order ? $fresh : $order;
	$minor = min( $minor, (int) round( (float) $order->get_remaining_refund_amount() * 100 ) );
	if ( $minor <= 0 ) {
		return true;
	}
	$wc_refund = wc_create_refund(
		array(
			'order_id'       => (int) $order->get_id(),
			'amount'         => round( $minor / 100, 2 ),
			'reason'         => $reason,
			'refund_payment' => false,
		)
	);
	if ( is_wp_error( $wc_refund ) ) {
		return new WP_Error( 'ticket_refund_record_failed', 'Could not record refund ' . $refund_id . ': ' . $wc_refund->get_error_message(), array( 'status' => 500 ) );
	}
	$wc_refund->update_meta_data( EXTRACHILL_SHOP_REFUND_ID_META, $refund_id );
	$wc_refund->save();
	return true;
}

/**
 * Refund a ticket order in full.
 *
 * No amount means the whole charge: the tickets and the Service fee go back to
 * the buyer, and `refund_application_fee` returns Extra Chill's share to the
 * venue, so Extra Chill keeps nothing on a refunded order. The refund is then
 * recorded with Events and as a WooCommerce refund.
 *
 * @param WC_Order $order  Ticket order.
 * @param string   $reason Refund reason (`seller_refund`, `sold_out`).
 * @return true|WP_Error
 */
function extrachill_shop_ticket_refund_payment( $order, $reason ) {
	$reference = (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META );
	// A new idempotency key per attempt. Stripe keeps a refused request's
	// result under its key for 24 hours, so a retry under the same key would
	// replay the refusal instead of trying again. A full refund cannot be
	// issued twice: Stripe refuses a second refund of a refunded charge.
	$attempt = (int) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_ATTEMPTS_META ) + 1;
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_REFUND_ATTEMPTS_META, (string) $attempt );
	$order->save();
	try {
		$refund = \Stripe\Refund::create( array(
			'payment_intent'         => (string) $order->get_meta( '_extrachill_payment_intent' ),
			'refund_application_fee' => true,
			'metadata'               => array(
				'order_reference'       => $reference,
				'order_id'              => (string) $order->get_id(),
				'reason'                => $reason,
				'application_fee_minor' => (string) extrachill_shop_ticket_order_amounts( $order )['application_fee_minor'],
			),
		), array(
			'stripe_account'  => (string) $order->get_meta( '_extrachill_stripe_account' ),
			'idempotency_key' => 'ticket-refund-' . $reference . '-' . $attempt,
		) );
	} catch ( \Exception $e ) {
		return new WP_Error( 'ticket_refund_failed', 'Ticket refund failed.', array( 'status' => 502 ) ); }
	if ( 'succeeded' !== ( $refund->status ?? 'succeeded' ) ) {
		return new WP_Error( 'ticket_refund_pending', 'Ticket refund has not succeeded yet.', array( 'status' => 409 ) );
	}
	// The money has moved, so never report this refund as failed. The
	// charge.refunded webhook re-records both observations idempotently.
	$result = extrachill_shop_record_ticket_sales( $order, $refund );
	if ( is_wp_error( $result ) ) {
		error_log( 'Extra Chill ticket settlement record deferred for refund ' . $refund->id . ': ' . $result->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; webhook retries the record.
	}
	$order->delete_meta_data( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META );
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_REFUND_REASON_META, $reason );
	$order->save();
	$result = extrachill_shop_record_ticket_refund( $order, (string) $refund->id, (int) $refund->amount, extrachill_shop_ticket_refund_label( $reason ) );
	if ( is_wp_error( $result ) ) {
		error_log( 'Extra Chill ticket refund record deferred for ' . $refund->id . ': ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; webhook retries the record.
	}
	return true;
}

/**
 * Defend against a stale sold-out response when this order already holds passes.
 * Status lookup failures must retry, never trigger a speculative refund.
 *
 * @param WC_Order $order Ticket order.
 * @return true|WP_Error
 */
function extrachill_shop_ticket_sold_out_refund( $order ) {
	$status = extrachill_shop_execute_ticket_owner_ability( 'get-ticket-order-status', array(
		'order_reference' => (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META ),
	) );
	if ( is_wp_error( $status ) ) {
		return $status;
	}
	if ( ! is_array( $status ) || ! isset( $status['passes'] ) || ! is_array( $status['passes'] ) ) {
		return new WP_Error( 'ticket_status_unavailable', 'Please retry ticket fulfilment.', array( 'status' => 502 ) );
	}
	foreach ( $status['passes'] as $pass ) {
		if ( in_array( $pass['status'] ?? '', array( 'active', 'redeemed' ), true ) ) {
			$order->payment_complete( (string) $order->get_meta( '_extrachill_payment_intent' ) );
			return true;
		}
	}
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META, 'sold_out' );
	$order->save();
	return extrachill_shop_ticket_refund_payment( $order, 'sold_out' );
}

/**
 * Issue the passes for a paid ticket Session.
 *
 * The PaymentIntent is stored first (it is how refunds and disputes find the
 * order), then the sale is recorded and the passes issued, and only then is
 * the order marked paid. A failed step returns an error so Stripe retries;
 * every step is idempotent. A show that sold out meanwhile is refunded.
 *
 * @param object $session Checkout Session.
 * @param string $account Connected account.
 * @return true|WP_Error
 */
function extrachill_shop_fulfil_ticket_session( $session, $account ) {
	if ( 'paid' !== ( $session->payment_status ?? '' ) ) {
		return true; }
	$session_id = (string) ( $session->id ?? '' );
	$intent     = (string) ( $session->payment_intent ?? '' );
	$order      = extrachill_shop_get_session_order( $session, $account, EXTRACHILL_SHOP_TICKET_KIND );
	if ( is_wp_error( $order ) ) {
		return $order; }
	if ( ! $order->has_status( array( 'pending', 'failed' ) ) ) {
		return true; }
	$order->update_meta_data( '_extrachill_payment_intent', $intent );
	$order->set_transaction_id( $intent );
	$order->save();
	$result = extrachill_shop_record_ticket_sales( $order );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 'sold_out' === $order->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META ) ) {
		return extrachill_shop_ticket_refund_payment( $order, 'sold_out' );
	}
	$issue  = array(
		'event'              => (int) $order->get_meta( EXTRACHILL_SHOP_TICKET_EVENT_META ),
		'quantity'           => extrachill_shop_ticket_order_quantity( $order ),
		'name'               => (string) ( $session->metadata->name ?? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
		'email'              => $session->customer_details->email ?? $session->customer_email ?? $order->get_billing_email(),
		'idempotency_key'    => $session_id,
		'order_reference'    => $session_id,
		'payment_reference'  => $intent,
		'buyer_user_id'      => (int) $order->get_customer_id(),
		'venue_show_updates' => 'yes' === $order->get_meta( '_extrachill_venue_show_updates' ),
		'seller'             => extrachill_shop_get_order_seller( $order ),
	) + extrachill_shop_ticket_issue_type( $order );
	$result = extrachill_shop_execute_ticket_owner_ability( 'issue-ticket-passes', $issue );
	if ( is_wp_error( $result ) ) {
		return $result; }
	if ( 'sold_out' === ( $result['status'] ?? '' ) ) {
		return extrachill_shop_ticket_sold_out_refund( $order ); }
	if ( 'issued' !== ( $result['status'] ?? '' ) ) {
		return new WP_Error( 'ticket_fulfilment_failed', 'Unexpected ticket response.', array( 'status' => 502 ) ); }
	// Pass emails Events could not queue are retried by the reconciliation job.
	if ( (int) ( $result['emails_failed'] ?? 0 ) > 0 ) {
		$order->update_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META, '1' );
		$order->add_order_note( sprintf( '%d pass email(s) could not be sent to the buyer; they will be retried.', (int) $result['emails_failed'] ) );
	}
	// Nothing to ship, so WooCommerce moves it straight to `completed`.
	$order->payment_complete( $intent );
	$order->save();
	return true;
}

/**
 * Permission for refunding one ticket order: manage the order's seller.
 *
 * @param array $input `{ order_reference }`.
 * @return true|WP_Error
 */
function extrachill_shop_ticket_refund_permission( array $input ) {
	$order = extrachill_shop_get_ticket_order( (string) ( $input['order_reference'] ?? '' ) );
	if ( ! $order ) {
		return new WP_Error( 'ticket_order_not_found', 'Ticket order not found.', array( 'status' => 404 ) ); }
	return extrachill_shop_can_manage_seller( get_current_user_id(), extrachill_shop_get_order_seller( $order ) );
}

/**
 * Permission for refunding a show's ticket orders: manage the show's ticket seller.
 *
 * @param array $input `{ event_id }`.
 * @return true|WP_Error
 */
function extrachill_shop_event_ticket_refund_permission( array $input ) {
	$offer = extrachill_shop_execute_owner_ability( 'events', 'extrachill/get-event-ticket-offer', 'GET', array( 'event' => (int) ( $input['event_id'] ?? 0 ) ) );
	if ( is_wp_error( $offer ) ) {
		return $offer; }
	return extrachill_shop_can_manage_seller( get_current_user_id(), $offer['seller'] ?? null );
}

/**
 * Refund a ticket order for its seller.
 *
 * Never after check-in: Events reports whether any pass was redeemed, and
 * revoking the passes first makes Events refuse atomically if a check-in won
 * the race. A refund whose passes are revoked but whose payment refund failed
 * stays `refund_pending` and can be retried.
 *
 * @param array $input `{ order_reference }`.
 * @return array|WP_Error `{ refunded: true }`.
 */
function extrachill_shop_refund_ticket_order( array $input ) {
	$permission = extrachill_shop_ticket_refund_permission( $input );
	if ( true !== $permission ) {
		return $permission; }
	$order = extrachill_shop_get_ticket_order( (string) $input['order_reference'] );
	$state = extrachill_shop_ticket_order_state( $order );
	if ( 'refunded' === $state ) {
		return array( 'refunded' => true ); }
	// Stripe refuses refunds on a disputed charge; the bank decides it now.
	if ( 'disputed' === $state ) {
		return new WP_Error( 'ticket_disputed', 'This payment is disputed, so it can only be settled in your payout dashboard.', array( 'status' => 409 ) ); }
	if ( ! in_array( $state, array( 'issued', 'refund_pending' ), true ) ) {
		return new WP_Error( 'ticket_not_paid', 'This ticket order was never paid.', array( 'status' => 409 ) ); }
	$status = extrachill_shop_execute_ticket_owner_ability( 'get-ticket-order-status', array( 'order_reference' => (string) $input['order_reference'] ) );
	if ( is_wp_error( $status ) ) {
		return $status; }
	if ( ! empty( $status['any_redeemed'] ) || ( empty( $status['refundable'] ) && 'refund_pending' !== $state ) ) {
		return new WP_Error( 'ticket_already_redeemed', 'This ticket order is not refundable.', array( 'status' => 409 ) ); }
	if ( ! extrachill_shop_stripe_init() || '' === (string) $order->get_meta( '_extrachill_payment_intent' ) ) {
		return new WP_Error( 'ticket_payment_unavailable', 'Ticket payment unavailable.' ); }
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META, 'seller_refund' );
	$order->save();
	$result = extrachill_shop_execute_ticket_owner_ability( 'revoke-ticket-passes', array(
		'order_reference' => (string) $input['order_reference'],
		'reason'          => 'seller_refund',
	) );
	if ( is_wp_error( $result ) ) {
		return $result; }
	$result = extrachill_shop_ticket_refund_payment( $order, 'seller_refund' );
	return is_wp_error( $result ) ? $result : array( 'refunded' => true );
}

/**
 * Reconcile `charge.refunded` on a ticket payment.
 *
 * Each succeeded refund is recorded with Events and as a WooCommerce refund
 * (once per Stripe refund ID, without calling Stripe again). A full refund
 * also cancels the passes; a partial one (from the seller's dashboard) is
 * money only, so the passes keep working.
 *
 * @param object $charge  Stripe charge.
 * @param string $account Connected account.
 * @return true|WP_Error WP_Error asks Stripe to retry.
 */
function extrachill_shop_ticket_charge_refunded( $charge, $account ) {
	$order = extrachill_shop_find_ticket_order_by_payment_intent( (string) ( $charge->payment_intent ?? '' ), $account );
	if ( ! $order ) {
		return new WP_Error( 'ticket_order_not_found', 'Ticket payment not indexed yet.', array( 'status' => 409 ) ); }
	$known = extrachill_shop_recorded_stripe_refunds( $order );
	try {
		foreach ( extrachill_shop_charge_refund_rows( $charge, $account ) as $refund ) {
			if ( 'succeeded' !== ( $refund->status ?? '' ) ) {
				continue;
			}
			$result = extrachill_shop_record_ticket_sales( $order, $refund );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$refund_id = (string) ( $refund->id ?? '' );
			if ( in_array( $refund_id, $known, true ) ) {
				continue;
			}
			$result = extrachill_shop_record_ticket_refund( $order, $refund_id, (int) ( $refund->amount ?? 0 ), extrachill_shop_ticket_refund_label( (string) ( $refund->metadata->reason ?? 'stripe_refund' ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$known[] = $refund_id;
		}
	} catch ( \Exception $e ) {
		return new WP_Error( 'ticket_refund_lookup_failed', 'Could not list refunds for charge ' . ( $charge->id ?? '' ) . ': ' . $e->getMessage(), array( 'status' => 500 ) );
	}
	if ( empty( $charge->refunded ) ) {
		return true;
	}
	// Recording a refund can change the status; work on the saved order.
	$fresh = wc_get_order( $order->get_id() );
	$order = $fresh instanceof WC_Order ? $fresh : $order;
	if ( '' === (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFUND_REASON_META ) ) {
		$order->update_meta_data( EXTRACHILL_SHOP_TICKET_REFUND_REASON_META, 'stripe_refund' );
	}
	$order->delete_meta_data( EXTRACHILL_SHOP_TICKET_REFUND_PENDING_META );
	$order->save();
	// Stripe says the whole charge is back with the buyer, whatever cents
	// WooCommerce has left after rounding.
	if ( ! $order->has_status( 'refunded' ) ) {
		$order->update_status( 'refunded', 'The whole payment was refunded (charge ' . (string) ( $charge->id ?? '' ) . ').' );
	}
	$result = extrachill_shop_execute_ticket_owner_ability( 'revoke-ticket-passes', array(
		'order_reference' => (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META ),
		'reason'          => 'stripe_refund',
	) );
	if ( is_wp_error( $result ) && 'ticket_already_redeemed' === $result->get_error_code() ) {
		error_log( 'Extra Chill ticket refund after check-in: order ' . $order->get_id() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator alert: money returned for an admitted guest.
		return true;
	}
	return is_wp_error( $result ) ? $result : true;
}

/**
 * A buyer disputed a ticket payment with their bank.
 *
 * Their passes stop working (unless they already checked in), the order goes
 * on hold with the dispute flag and can no longer be refunded here, and
 * Events emails the seller to respond.
 *
 * @param object $dispute Stripe dispute.
 * @param string $account Connected account.
 * @return true|WP_Error
 */
function extrachill_shop_ticket_charge_disputed( $dispute, $account ) {
	$order = extrachill_shop_find_ticket_order_by_payment_intent( (string) ( $dispute->payment_intent ?? '' ), $account );
	if ( ! $order ) {
		return new WP_Error( 'ticket_account_mismatch', 'Ticket account mismatch.' ); }
	$dispute_id = (string) ( $dispute->id ?? '' );
	// A replay, or a `created` that arrives after the dispute closed.
	if ( $dispute_id === (string) $order->get_meta( '_extrachill_dispute' ) || extrachill_shop_dispute_settled_on( $order, $dispute_id ) ) {
		return true; }
	// Revoke before marking, so a failed revoke is retried by Stripe.
	$result = extrachill_shop_execute_ticket_owner_ability( 'revoke-ticket-passes', array(
		'order_reference' => (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META ),
		'reason'          => 'dispute',
	) );
	if ( is_wp_error( $result ) ) {
		return $result; }
	$order->update_meta_data( EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META, (string) $order->get_status() );
	$order->update_meta_data( '_extrachill_dispute', $dispute_id );
	$order->update_status( 'on-hold', sprintf( 'The buyer disputed $%1$s with their bank (Stripe dispute %2$s). The passes were cancelled; the seller responds in their payout dashboard.', number_format( ( (int) ( $dispute->amount ?? 0 ) ) / 100, 2 ), $dispute_id ) );
	$order->save();
	return true;
}

/**
 * A dispute on a ticket payment closed (`charge.dispute.closed`).
 *
 * - Won (`won`, or `warning_closed` for an inquiry that never became a
 *   chargeback): Events restores the passes the dispute cancelled, keeping
 *   their codes, and emails them to the buyer again. The order goes back to
 *   its status before the hold, so the seller can refund it again.
 * - Lost: the bank returned the disputed amount to the buyer. The passes stay
 *   cancelled. The amount is recorded with Events as a refund observation
 *   and as a WooCommerce refund, both tagged with the dispute ID; the
 *   platform fee is not returned on a lost dispute, so no fee reversal is
 *   recorded. A fully returned order becomes `refunded`.
 *
 * Either way the outcome is recorded (`_extrachill_dispute_outcome`), the
 * order gets a note, and the seller is emailed. A failed Events call returns
 * an error so Stripe retries; replays are no-ops, and the restore itself is
 * idempotent, so the buyer is emailed once.
 *
 * @param object $dispute Stripe dispute.
 * @param string $account Connected account the charge is on.
 * @return true|WP_Error WP_Error asks Stripe to retry.
 */
function extrachill_shop_ticket_dispute_closed( $dispute, $account ) {
	$order      = extrachill_shop_find_ticket_order_by_payment_intent( (string) ( $dispute->payment_intent ?? '' ), $account );
	$dispute_id = (string) ( $dispute->id ?? '' );
	$status     = (string) ( $dispute->status ?? '' );
	$outcome    = in_array( $status, array( 'won', 'warning_closed' ), true ) ? 'won' : ( 'lost' === $status ? 'lost' : '' );
	if ( ! $order || '' === $outcome || '' === $dispute_id || extrachill_shop_dispute_settled_on( $order, $dispute_id ) ) {
		return true; }
	$minor     = (int) ( $dispute->amount ?? 0 );
	$amount    = number_format( $minor / 100, 2 );
	$reference = (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META );
	$was_open  = $dispute_id === (string) $order->get_meta( '_extrachill_dispute' ) && $order->has_status( 'on-hold' );
	$prior     = (string) $order->get_meta( EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META );
	$next      = '';

	$restored = 0;
	if ( 'won' === $outcome ) {
		if ( $was_open ) {
			$result = extrachill_shop_execute_ticket_owner_ability( 'restore-ticket-passes', array_filter( array(
				'order_reference' => $reference,
				'reason'          => 'dispute',
				'email'           => (string) $order->get_billing_email(),
			) ) );
			if ( is_wp_error( $result ) ) {
				return $result; }
			$restored = (int) ( $result['restored'] ?? 0 );
			if ( $restored > 0 ) {
				// Events just emailed the restored passes to the buyer.
				$order->delete_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META );
				$order->delete_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META );
			}
			$next = in_array( $prior, array( 'processing', 'completed' ), true ) ? $prior : 'completed';
		}
		$note = sprintf( 'The dispute was settled in the seller\'s favor (Stripe dispute %1$s, %2$s). ', $dispute_id, $status )
			. ( $restored > 0 ? sprintf( '%d pass(es) cancelled by the dispute work again and were emailed to the buyer.', $restored ) : 'No passes needed restoring.' )
			. ( $was_open ? ' The order can be refunded again.' : '' );
	} else {
		if ( $was_open && $minor > 0 ) {
			// Recorded like a refund, keyed by the dispute ID so a retry is
			// the same observation. The application fee is not returned.
			$result = extrachill_shop_record_ticket_sales( $order, (object) array(
				'id'       => $dispute_id,
				'amount'   => $minor,
				'created'  => (int) ( $dispute->created ?? time() ),
				'metadata' => (object) array( 'application_fee_minor' => 0 ),
			) );
			if ( is_wp_error( $result ) ) {
				return $result; }
			$result = extrachill_shop_record_ticket_refund( $order, $dispute_id, $minor, 'The buyer\'s bank returned the payment after a lost dispute.' );
			if ( is_wp_error( $result ) ) {
				return $result; }
			$fresh = wc_get_order( $order->get_id() );
			$order = $fresh instanceof WC_Order ? $fresh : $order;
		}
		$note = sprintf( 'The dispute was lost (Stripe dispute %1$s): the bank returned $%2$s to the buyer. The passes stay cancelled and the order can\'t be refunded.', $dispute_id, $amount );
	}

	$order->delete_meta_data( '_extrachill_dispute' );
	$order->delete_meta_data( EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META );
	$order->update_meta_data( EXTRACHILL_SHOP_DISPUTE_OUTCOME_META, $dispute_id . ':' . $outcome );
	if ( '' !== $next && ! $order->has_status( $next ) ) {
		$order->update_status( $next, $note );
	} else {
		$order->add_order_note( $note );
	}
	$order->save();

	$seller = extrachill_shop_get_order_seller( $order );
	if ( null !== $seller ) {
		$show = (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_EVENT_TITLE_META );
		$show = '' !== $show ? $show : 'your show';
		if ( 'won' === $outcome ) {
			$message = array(
				'subject'   => sprintf( 'Ticket dispute for %s settled in your favor', $show ),
				'body_html' => '<p>' . esc_html( sprintf( 'The bank settled a ticket dispute for %1$s in your favor. You keep the $%2$s payment.', $show, $amount ) ) . '</p>'
					. '<p>' . esc_html( ( $restored > 0 ? 'The buyer\'s passes work again and we emailed them to the buyer. ' : '' ) . 'You can refund the order again until someone on it checks in.' ) . '</p>',
				'preheader' => 'You keep the payment.',
			);
		} else {
			$message = array(
				'subject'   => sprintf( 'Ticket dispute for %s lost', $show ),
				'body_html' => '<p>' . esc_html( sprintf( 'The bank settled a ticket dispute for %1$s in the buyer\'s favor and returned $%2$s to them.', $show, $amount ) ) . '</p>'
					. '<p>' . esc_html( 'Their passes stay cancelled. The disputed amount and the dispute fee stay out of your balance.' ) . '</p>',
				'preheader' => 'The buyer won the ticket dispute.',
			);
		}
		extrachill_shop_notify_seller( $seller, $message );
	}
	return true;
}

/**
 * Refund every order for a show that nobody has checked in on.
 *
 * Reads the show's paid ticket orders from WooCommerce (event and status).
 * Each goes through the normal refund, with its own seller check and the
 * never-after-check-in rule. Refunded orders drop out of the query, so
 * running it again only picks up what's left.
 *
 * @param array $input `{ event_id }`.
 * @return array|WP_Error Counts of refunded, checked-in and failed orders.
 */
function extrachill_shop_refund_event_ticket_orders( array $input ) {
	$permission = extrachill_shop_event_ticket_refund_permission( $input );
	if ( true !== $permission ) {
		return $permission; }
	$counts = array(
		'refunded'   => 0,
		'checked_in' => 0,
		'failed'     => 0,
	);
	foreach ( extrachill_shop_get_event_ticket_orders( (int) $input['event_id'] ) as $order ) {
		$result = extrachill_shop_refund_ticket_order( array( 'order_reference' => (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META ) ) );
		if ( ! is_wp_error( $result ) ) {
			++$counts['refunded'];
		} elseif ( 'ticket_already_redeemed' === $result->get_error_code() ) {
			++$counts['checked_in'];
		} else {
			++$counts['failed'];
		}
	}
	return $counts;
}
