<?php
/** Seller-settled ticket payments. No WooCommerce orders or tax calculation. */
defined( 'ABSPATH' ) || exit;

function extrachill_shop_ticket_fees( $subtotal, array $settings ) {
	$buyer  = max( 0, (int) ( $settings['buyer_fee_flat_minor'] ?? 0 ) ) + (int) round( $subtotal * max( 0, (float) ( $settings['buyer_fee_percent'] ?? 0 ) ) / 100 );
	$seller = (int) round( $subtotal * max( 0, min( 100, (float) ( $settings['seller_fee_percent'] ?? 0 ) ) ) / 100 );
	return array(
		'buyer'       => $buyer,
		'application' => $buyer + $seller,
	);
}

function extrachill_shop_start_ticket_checkout( array $input ) {
	if ( ! is_int( $input['quantity'] ?? null ) || $input['quantity'] < 1 || $input['quantity'] > 10 || empty( trim( $input['name'] ?? '' ) ) || ! is_email( $input['email'] ?? '' ) ) {
		return new WP_Error( 'invalid_ticket_input', 'A name, email and quantity from 1 to 10 are required.', array( 'status' => 400 ) );
	}
	$offer = extrachill_shop_execute_owner_ability( 'events', 'extrachill/get-event-ticket-offer', 'GET', array( 'event' => $input['event'] ) );
	if ( is_wp_error( $offer ) ) {
		return $offer; }
	if ( empty( $offer['enabled'] ) || empty( $offer['sale_open'] ) ) {
		return new WP_Error( 'ticket_sale_closed', 'Ticket sales are closed.', array( 'status' => 409 ) ); }
	if ( null !== $offer['remaining'] && $offer['remaining'] < $input['quantity'] ) {
		return new WP_Error( 'ticket_sold_out', 'Not enough tickets remain.', array( 'status' => 409 ) ); }
	$seller = extrachill_shop_validate_seller_ref( $offer['seller'] );
	if ( is_wp_error( $seller ) ) {
		return $seller; }
	$canonical = extrachill_shop_get_canonical_seller( $seller );
	if ( is_wp_error( $canonical ) ) {
		return $canonical; }
	$account = extrachill_shop_get_seller_stripe_account( $seller );
	if ( ! $account || ! extrachill_shop_stripe_init() || ! extrachill_shop_account_can_receive_payments( $account ) ) {
		return new WP_Error( 'seller_payments_unavailable', 'Seller cannot receive payments.', array( 'status' => 409 ) ); }
	if ( ! is_int( $offer['price_minor'] ) || $offer['price_minor'] < 1 ) {
		return new WP_Error( 'invalid_ticket_price', 'Invalid ticket price.' ); }
	$fees     = extrachill_shop_ticket_fees( $offer['price_minor'] * $input['quantity'], get_site_option( 'extrachill_ticket_fees', array() ) );
	$metadata = array(
		'event_id'    => (string) $offer['event_id'],
		'quantity'    => (string) $input['quantity'],
		'name'        => sanitize_text_field( $input['name'] ),
		'seller_type' => $seller['type'],
		'seller_id'   => (string) $seller['id'],
		'kind'        => 'extrachill_ticket',
	);
	$items    = array(
		array(
			'quantity'   => $input['quantity'],
			'price_data' => array(
				'currency'     => $offer['currency'],
				'unit_amount'  => $offer['price_minor'],
				'product_data' => array( 'name' => $offer['title'] ),
			),
		),
	);
	if ( $fees['buyer'] > 0 ) {
		$items[] = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => $offer['currency'],
				'unit_amount'  => $fees['buyer'],
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
				'application_fee_amount' => $fees['application'],
				'metadata'               => $metadata,
			),
			'success_url'         => add_query_arg( 'tickets', 'success', $offer['url'] ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => add_query_arg( 'tickets', 'cancelled', $offer['url'] ),
			'expires_at'          => time() + 1800,
		), array( 'stripe_account' => $account ) );
		// Immutable account binding: refunds never depend on a seller's later account changes.
		update_option( 'extrachill_ticket_order_' . $session->id, array(
			'account'        => $account,
			'seller'         => $seller,
			'event'          => $offer['event_id'],
			'payment_intent' => '',
			'status'         => 'pending',
			'sales'          => array(
				'event_id'     => (int) $offer['event_id'],
				'tickets_sold' => $input['quantity'],
				'gross_minor'  => $offer['price_minor'] * $input['quantity'] + $fees['buyer'],
				'fees_minor'   => $fees['application'],
				'currency'     => $offer['currency'],
				'period_start' => gmdate( 'Y-m-d H:i:s' ),
			),
		), false );
		return array(
			'checkout_url' => $session->url,
			'session_id'   => $session->id,
		);
	} catch ( \Exception $e ) {
		return new WP_Error( 'ticket_checkout_failed', 'Ticket checkout failed.', array( 'status' => 502 ) ); }
}

function extrachill_shop_record_ticket_sales( $reference, array $order, $refund = null ) {
	if ( empty( $order['sales'] ) ) {
		return new WP_Error( 'ticket_sales_missing', 'Ticket financial snapshot is unavailable.' );
	}
	$input                       = $order['sales'];
	$input['external_report_id'] = $refund ? $refund->id : $reference;
	$input['order_reference']    = $reference;
	if ( $refund ) {
		$input['gross_minor'] = - (int) $refund->amount;
		$input['fees_minor']  = - (int) ( $refund->metadata->application_fee_minor ?? 0 );
		// Only a full refund cancels tickets; partial refunds are money only.
		$input['tickets_sold'] = (int) $refund->amount === $order['sales']['gross_minor'] ? -$order['sales']['tickets_sold'] : 0;
		$input['period_start'] = ( new DateTimeImmutable( '@' . (int) $refund->created ) )->format( 'Y-m-d H:i:s' );
	}
	$result = extrachill_shop_execute_ticket_owner_ability( 'record-checkout-ticket-sales', $input );
	return is_wp_error( $result ) ? $result : true;
}

function extrachill_shop_ticket_refund_payment( $reference, array $order, $reason ) {
	try {
		$refund = \Stripe\Refund::create( array(
			'payment_intent'         => $order['payment_intent'],
			'refund_application_fee' => true,
			'metadata'               => array(
				'order_reference'       => $reference,
				'reason'                => $reason,
				'application_fee_minor' => (string) ( $order['sales']['fees_minor'] ?? 0 ),
			),
		), array(
			'stripe_account'  => $order['account'],
			'idempotency_key' => 'ticket-refund-' . $reference,
		) );
		if ( 'succeeded' !== ( $refund->status ?? 'succeeded' ) ) {
			return new WP_Error( 'ticket_refund_pending', 'Ticket refund has not succeeded yet.', array( 'status' => 409 ) );
		}
		// The money has moved, so never report this refund as failed. The
		// charge.refunded webhook re-records the observation idempotently.
		$result = extrachill_shop_record_ticket_sales( $reference, $order, $refund );
		if ( is_wp_error( $result ) ) {
			error_log( 'Extra Chill ticket settlement record deferred for refund ' . $refund->id . ': ' . $result->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; webhook retries the record.
		}
		$order['status']        = 'refunded';
		$order['refund_reason'] = $reason;
		update_option( 'extrachill_ticket_order_' . $reference, $order, false );
		return true;
	} catch ( \Exception $e ) {
		return new WP_Error( 'ticket_refund_failed', 'Ticket refund failed.', array( 'status' => 502 ) ); }
}

function extrachill_shop_fulfil_ticket_session( $session, $account ) {
	if ( 'paid' !== $session->payment_status ) {
		return true; }
	$order = get_option( 'extrachill_ticket_order_' . $session->id );
	if ( ! $order || $order['account'] !== $account ) {
		return new WP_Error( 'ticket_account_mismatch', 'Unknown ticket order or account.', array( 'status' => 409 ) ); }
	if ( in_array( $order['status'], array( 'issued', 'refunded' ), true ) ) {
		return true; }
	$order['payment_intent'] = $session->payment_intent;
	update_option( 'extrachill_ticket_order_' . $session->id, $order, false );
	update_option( 'extrachill_ticket_payment_' . $account . '_' . $session->payment_intent, $session->id, false );
	$result = extrachill_shop_record_ticket_sales( $session->id, $order );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$result = extrachill_shop_execute_ticket_owner_ability( 'issue-ticket-passes', array(
		'event'             => $order['event'],
		'quantity'          => (int) $session->metadata->quantity,
		'name'              => $session->metadata->name,
		'email'             => $session->customer_details->email ?? $session->customer_email,
		'idempotency_key'   => $session->id,
		'order_reference'   => $session->id,
		'payment_reference' => $session->payment_intent,
	) );
	if ( is_wp_error( $result ) ) {
		return $result; }
	if ( 'sold_out' === ( $result['status'] ?? '' ) ) {
		return extrachill_shop_ticket_refund_payment( $session->id, $order, 'sold_out' ); }
	if ( 'issued' !== ( $result['status'] ?? '' ) ) {
		return new WP_Error( 'ticket_fulfilment_failed', 'Unexpected ticket response.', array( 'status' => 502 ) ); }
	$order['status'] = 'issued';
	update_option( 'extrachill_ticket_order_' . $session->id, $order, false );
	return true;
}

function extrachill_shop_ticket_refund_permission( array $input ) {
	$order = get_option( 'extrachill_ticket_order_' . $input['order_reference'] );
	if ( ! $order ) {
		return new WP_Error( 'ticket_order_not_found', 'Ticket order not found.', array( 'status' => 404 ) ); }
	return extrachill_shop_current_user_can_manage_seller( $order['seller'] );
}

function extrachill_shop_refund_ticket_order( array $input ) {
	$permission = extrachill_shop_ticket_refund_permission( $input );
	if ( true !== $permission ) {
		return $permission; }
	$reference = $input['order_reference'];
	$status    = extrachill_shop_execute_ticket_owner_ability( 'get-ticket-order-status', array( 'order_reference' => $reference ) );
	if ( is_wp_error( $status ) ) {
		return $status; }
	$order = get_option( 'extrachill_ticket_order_' . $reference );
	if ( 'refunded' === $order['status'] ) {
		return array( 'refunded' => true ); }
	// Stripe refuses refunds on a disputed charge; the bank decides it now.
	if ( 'disputed' === $order['status'] ) {
		return new WP_Error( 'ticket_disputed', 'This payment is disputed, so it can only be settled in Stripe.', array( 'status' => 409 ) ); }
	if ( ! empty( $status['any_redeemed'] ) || ( empty( $status['refundable'] ) && 'refund_pending' !== $order['status'] ) ) {
		return new WP_Error( 'ticket_already_redeemed', 'This ticket order is not refundable.', array( 'status' => 409 ) ); }
	if ( ! extrachill_shop_stripe_init() || empty( $order['payment_intent'] ) ) {
		return new WP_Error( 'ticket_payment_unavailable', 'Ticket payment unavailable.' ); }
	// Revoke first: Events atomically refuses if check-in won the race after the status read.
	$order['status'] = 'refund_pending';
	update_option( 'extrachill_ticket_order_' . $reference, $order, false );
	$result = extrachill_shop_execute_ticket_owner_ability( 'revoke-ticket-passes', array(
		'order_reference' => $reference,
		'reason'          => 'seller_refund',
	) );
	if ( is_wp_error( $result ) ) {
		return $result; }
	$result = extrachill_shop_ticket_refund_payment( $reference, $order, 'seller_refund' );
	return is_wp_error( $result ) ? $result : array( 'refunded' => true );
}

function extrachill_shop_ticket_charge_refunded( $charge, $account ) {
	$reference = get_option( 'extrachill_ticket_payment_' . $account . '_' . $charge->payment_intent );
	if ( ! $reference ) {
		return new WP_Error( 'ticket_order_not_found', 'Ticket payment not indexed yet.', array( 'status' => 409 ) ); }
	$order = get_option( 'extrachill_ticket_order_' . $reference );
	if ( ! $order || $order['account'] !== $account ) {
		return new WP_Error( 'ticket_account_mismatch', 'Ticket account mismatch.' ); }
	// Stripe embeds only a bounded refund list; fetch all pages when needed.
	$refunds = $charge->refunds ?? null;
	if ( ! $refunds || ! empty( $refunds->has_more ) ) {
		// @phpstan-ignore-next-line staticMethod.notFound -- Refund::all comes from the SDK's ApiOperations\All trait.
		$refunds = \Stripe\Refund::all( array(
			'charge' => $charge->id,
			'limit'  => 100,
		), array( 'stripe_account' => $account ) );
		$rows    = $refunds->autoPagingIterator();
	} else {
		$rows = $refunds->data;
	}
	foreach ( $rows as $refund ) {
		if ( 'succeeded' !== $refund->status ) {
			continue;
		}
		$result = extrachill_shop_record_ticket_sales( $reference, $order, $refund );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	// A partial refund (from the seller's Stripe dashboard) returns some money
	// but cancels no tickets, so the passes keep working.
	if ( empty( $charge->refunded ) ) {
		return true;
	}
	$order['status']        = 'refunded';
	$order['refund_reason'] = 'stripe_refund';
	update_option( 'extrachill_ticket_order_' . $reference, $order, false );
	$result = extrachill_shop_execute_ticket_owner_ability( 'revoke-ticket-passes', array(
		'order_reference' => $reference,
		'reason'          => 'stripe_refund',
	) );
	if ( is_wp_error( $result ) && 'ticket_already_redeemed' === $result->get_error_code() ) {
		error_log( 'Extra Chill ticket refund after check-in: ' . $reference ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator alert: money returned for an admitted guest.
		return true;
	}
	return is_wp_error( $result ) ? $result : true;
}

/**
 * A buyer disputed a ticket payment with their bank.
 *
 * Their passes stop working (unless they already checked in), the order can
 * no longer be refunded here, and Events emails the seller to respond.
 *
 * @param object $dispute Stripe dispute.
 * @param string $account Connected account.
 * @return true|WP_Error
 */
function extrachill_shop_ticket_charge_disputed( $dispute, $account ) {
	$reference = get_option( 'extrachill_ticket_payment_' . $account . '_' . (string) ( $dispute->payment_intent ?? '' ) );
	$order     = $reference ? get_option( 'extrachill_ticket_order_' . $reference ) : false;
	if ( ! $order || $order['account'] !== $account ) {
		return new WP_Error( 'ticket_account_mismatch', 'Ticket account mismatch.' ); }
	if ( 'disputed' === $order['status'] ) {
		return true; }
	// Revoke before marking, so a failed revoke is retried by Stripe.
	$result = extrachill_shop_execute_ticket_owner_ability( 'revoke-ticket-passes', array(
		'order_reference' => $reference,
		'reason'          => 'dispute',
	) );
	if ( is_wp_error( $result ) ) {
		return $result; }
	$order['status']  = 'disputed';
	$order['dispute'] = (string) ( $dispute->id ?? '' );
	update_option( 'extrachill_ticket_order_' . $reference, $order, false );
	return true;
}

/**
 * References of the paid ticket orders for one show.
 *
 * @param int $event_id Event post ID.
 * @return string[]
 */
function extrachill_shop_event_ticket_order_references( $event_id ) {
	global $wpdb;
	$prefix = 'extrachill_ticket_order_';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Orders are option rows; a prefix scan is the only index.
	$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	$refs  = array();
	foreach ( $names as $name ) {
		$order = get_option( $name );
		if ( is_array( $order ) && (int) ( $order['event'] ?? 0 ) === (int) $event_id && in_array( $order['status'] ?? '', array( 'issued', 'refund_pending' ), true ) ) {
			$refs[] = substr( $name, strlen( $prefix ) );
		}
	}
	return $refs;
}

/**
 * Refund every order for a show that nobody has checked in on.
 *
 * Each order goes through the normal refund, with its own seller check and
 * the never-after-check-in rule. Already refunded orders are left alone, so
 * running it again only picks up what's left.
 *
 * @param array $input `{ event_id }`.
 * @return array|WP_Error Counts of refunded, checked-in and failed orders.
 */
function extrachill_shop_refund_event_ticket_orders( array $input ) {
	$offer = extrachill_shop_execute_owner_ability( 'events', 'extrachill/get-event-ticket-offer', 'GET', array( 'event' => (int) $input['event_id'] ) );
	if ( is_wp_error( $offer ) ) {
		return $offer; }
	$permission = extrachill_shop_current_user_can_manage_seller( $offer['seller'] );
	if ( true !== $permission ) {
		return $permission; }
	$counts = array(
		'refunded'   => 0,
		'checked_in' => 0,
		'failed'     => 0,
	);
	foreach ( extrachill_shop_event_ticket_order_references( (int) $input['event_id'] ) as $reference ) {
		$result = extrachill_shop_refund_ticket_order( array( 'order_reference' => $reference ) );
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
