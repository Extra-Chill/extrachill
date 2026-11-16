<?php
/** Single-seller merch checkout. */
defined( 'ABSPATH' ) || exit;

function extrachill_shop_get_product_payout_seller( $product_id ) {
	$seller = extrachill_shop_get_product_seller( $product_id );
	if ( ! $seller || ( 'artist' === $seller['type'] && defined( 'EC_PLATFORM_ARTIST_ID' ) && EC_PLATFORM_ARTIST_ID === $seller['id'] ) ) {
		return null;
	}
	return $seller;
}

/** Returns null for platform products, or an error for a mixed cart/order. */
function extrachill_shop_single_seller( array $product_ids ) {
	$seen   = false;
	$seller = null;
	foreach ( $product_ids as $id ) {
		$current = extrachill_shop_get_product_payout_seller( $id );
		if ( $seen && $current !== $seller ) {
			return new WP_Error( 'mixed_sellers', 'Your cart has items from another seller. Check out or empty it first.' );
		}
		$seller = $current;
		$seen   = true;
	}
	return $seller;
}

function extrachill_shop_validate_add_to_cart( $valid, $product_id ) {
	$ids    = array_column( WC()->cart ? WC()->cart->get_cart() : array(), 'product_id' );
	$ids[]  = $product_id;
	$result = extrachill_shop_single_seller( $ids );
	if ( is_wp_error( $result ) ) {
		wc_add_notice( $result->get_error_message(), 'error' );
		return false;
	}
	return $valid;
}
add_filter( 'woocommerce_add_to_cart_validation', 'extrachill_shop_validate_add_to_cart', 10, 2 );

function extrachill_shop_validate_cart_at_checkout() {
	if ( ! WC()->cart ) {
		return;
	}
	$seller = extrachill_shop_single_seller( array_column( WC()->cart->get_cart(), 'product_id' ) );
	if ( is_wp_error( $seller ) ) {
		wc_add_notice( $seller->get_error_message(), 'error' );
	} elseif ( $seller && ! extrachill_shop_seller_can_receive_payments( $seller ) ) {
		wc_add_notice( 'Seller cannot receive payments. Please try again later.', 'error' );
	}
}
add_action( 'woocommerce_check_cart_items', 'extrachill_shop_validate_cart_at_checkout' );

/** Build a direct charge, binding the order to its original seller account. */
function extrachill_shop_start_merch_checkout( $order ) {
	$ids = array();
	foreach ( $order->get_items() as $item ) {
		$ids[] = $item->get_product_id();
	}
	$seller = extrachill_shop_single_seller( $ids );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	if ( ! $ids || ! extrachill_shop_stripe_init() ) {
		return new WP_Error( 'checkout_unavailable', 'Checkout is unavailable.' );
	}
	$account = '';
	if ( $seller ) {
		$canonical = extrachill_shop_get_canonical_seller( $seller );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}
		$account = extrachill_shop_get_seller_stripe_account( $seller );
		if ( ! $account || ! extrachill_shop_seller_can_receive_payments( $seller ) ) {
			return new WP_Error( 'seller_payments_unavailable', 'Seller cannot receive payments. Please try again later.' );
		}
	}
	$items          = array();
	$subtotal_minor = 0;
	$total_minor    = (int) round( $order->get_total() * 100 );
	$items_minor    = 0;
	foreach ( $order->get_items() as $item ) {
		// One Stripe line per discounted order line avoids unit rounding drift.
		$amount          = (int) round( ( $item->get_total() + $item->get_total_tax() ) * 100 );
		$items_minor    += $amount;
		$items[]         = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => strtolower( $order->get_currency() ),
				'unit_amount'  => $amount,
				'product_data' => array( 'name' => $item->get_name() . ' × ' . $item->get_quantity() ),
			),
		);
		$subtotal_minor += (int) round( $item->get_total() * 100 );
	}
	// Preserve Woo shipping/fee/tax totals without calculating tax here.
	if ( $total_minor < $items_minor || $total_minor <= 0 ) {
		return new WP_Error( 'invalid_checkout_total', 'This order total cannot be processed.' );
	}
	if ( $total_minor > $items_minor ) {
		$items[] = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => strtolower( $order->get_currency() ),
				'unit_amount'  => $total_minor - $items_minor,
				'product_data' => array( 'name' => 'Shipping and order fees' ),
			),
		);
	}
	// Extra Chill buys the postage, so the buyer's shipping joins the fee. It
	// goes back to the seller if they ship it themselves; see shipping-recovery.php.
	$shipping_minor = $seller ? min( $total_minor - $items_minor, extrachill_shop_order_shipping_minor( $order ) ) : 0;
	// Merch sellers pay card processing. When Extra Chill pays it (Express
	// accounts), the fee adds the processing estimate on the whole charge; an
	// unknown payer counts as the seller paying (commission only), so the
	// seller is never charged processing twice. See extrachill_commerce_merch_fee().
	$fee_payer = $account ? extrachill_shop_get_seller_fee_payer( $seller ) : '';
	$fee_parts = $account ? extrachill_commerce_merch_fee( $subtotal_minor, $total_minor, $seller, $fee_payer, $shipping_minor ) : extrachill_commerce_merch_fee( 0, 0, null );
	$fee       = $fee_parts['application_fee'];
	$sessions  = extrachill_shop_order_checkout_sessions( $order );
	$closed    = extrachill_shop_expire_previous_checkout_session( $sessions );
	if ( is_wp_error( $closed ) ) {
		return $closed;
	}
	$metadata   = array(
		'kind'     => 'extrachill_merch',
		'order_id' => (string) $order->get_id(),
	);
	$data       = array(
		'mode'                => 'payment',
		'line_items'          => $items,
		'metadata'            => $metadata,
		'payment_intent_data' => array( 'metadata' => $metadata ),
		'success_url'         => $order->get_checkout_order_received_url(),
		'cancel_url'          => wc_get_cart_url(),
		'customer_email'      => $order->get_billing_email(),
	);
	$expires_at = extrachill_shop_checkout_session_expires_at();
	if ( $expires_at ) {
		$data['expires_at'] = $expires_at;
	}
	// A new key per attempt: Stripe would otherwise replay a Session expired above.
	$options = array( 'idempotency_key' => 'ec-merch-' . $order->get_id() . '-' . count( $sessions ) . '-' . hash( 'sha256', wp_json_encode( array( $account, $data ) ) ) );
	if ( $account ) {
		$options['stripe_account']                             = $account;
		$data['payment_intent_data']['application_fee_amount'] = $fee;
	}
	try {
		$session                  = \Stripe\Checkout\Session::create( $data, $options );
		$sessions[ $session->id ] = $account;
		$order->update_meta_data( '_extrachill_stripe_account', $account );
		$order->update_meta_data( '_extrachill_checkout_sessions', $sessions );
		// The canonical order seller record (see order-seller-index.php). The
		// application fee includes the shipping hold (see shipping-recovery.php).
		$order->update_meta_data( '_extrachill_seller', $seller ? $seller['type'] . ':' . (int) $seller['id'] : '' );
		$order->update_meta_data( '_extrachill_application_fee', $fee );
		$order->update_meta_data( EXTRACHILL_SHOP_SHIPPING_HOLD_META, $shipping_minor );
		// The processing part of the fee, kept by Extra Chill on refunds
		// because the processor does not return it (see refunds.php).
		$order->update_meta_data( EXTRACHILL_SHOP_PROCESSING_FEE_META, $fee_parts['processing'] );
		$order->update_meta_data( '_extrachill_processing_payer', $account ? $fee_parts['processing_payer'] : '' );
		$order->save();
		return $session;
	} catch ( \Exception $e ) {
		return new WP_Error( 'merch_checkout_failed', 'Checkout failed. Please try again.' );
	}
}

/**
 * Every Checkout Session started for an order, oldest first, mapped to the
 * account it was created on ('' for the platform account).
 *
 * @param WC_Order $order Order.
 * @return array<string,string>
 */
function extrachill_shop_order_checkout_sessions( $order ) {
	$sessions = $order->get_meta( '_extrachill_checkout_sessions' );
	return is_array( $sessions ) ? $sessions : array();
}

/**
 * Stripe request options for an account ('' means the platform account).
 *
 * @param string $account Connected account ID or ''.
 * @return array
 */
function extrachill_shop_stripe_account_options( $account ) {
	return '' === (string) $account ? array() : array( 'stripe_account' => (string) $account );
}

/**
 * Expire the order's latest Session before starting another one.
 *
 * A buyer who comes back to checkout must not be able to pay an older
 * Session as well as the new one. Only the latest can still be open, since
 * every earlier one was expired when its successor started.
 *
 * @param array<string,string> $sessions The order's recorded Sessions.
 * @return true|WP_Error An error when the old Session was already paid or could not be closed.
 */
function extrachill_shop_expire_previous_checkout_session( array $sessions ) {
	if ( ! $sessions ) {
		return true;
	}
	$id      = (string) array_key_last( $sessions );
	$options = extrachill_shop_stripe_account_options( $sessions[ $id ] );
	try {
		$previous = \Stripe\Checkout\Session::retrieve( $id, $options );
		if ( 'open' === $previous->status ) {
			$previous->expire( null, $options );
		} elseif ( 'complete' === $previous->status ) {
			return new WP_Error( 'checkout_already_paid', 'This order has already been paid. Check your email for the receipt.' );
		}
	} catch ( \Exception $e ) {
		return new WP_Error( 'merch_checkout_failed', 'Checkout failed. Please try again.' );
	}
	return true;
}

/**
 * Session expiry matching how long WooCommerce holds stock for an unpaid order.
 *
 * Stripe accepts 30 minutes to 24 hours. With no hold set, Stripe's 24-hour
 * default applies.
 *
 * @return int|null Unix timestamp, or null for Stripe's default.
 */
function extrachill_shop_checkout_session_expires_at() {
	$minutes = (int) get_option( 'woocommerce_hold_stock_minutes', '60' );
	if ( $minutes < 1 ) {
		return null;
	}
	return time() + min( 1440, max( 30, $minutes ) ) * 60;
}

/**
 * The merch order a Session event belongs to, checked against the account
 * the Session was created on.
 *
 * @param object $session Checkout Session.
 * @param string $account Connected account from the event ('' for platform).
 * @return WC_Order|WP_Error
 */
function extrachill_shop_get_merch_session_order( $session, $account ) {
	$order    = wc_get_order( (int) ( $session->metadata->order_id ?? 0 ) );
	$sessions = $order ? extrachill_shop_order_checkout_sessions( $order ) : array();
	if ( ! $order || 'extrachill_stripe_checkout' !== $order->get_payment_method() || ! array_key_exists( (string) $session->id, $sessions ) || (string) $sessions[ $session->id ] !== (string) $account ) {
		return new WP_Error( 'merch_account_mismatch', 'Unknown merch order or account.', array( 'status' => 409 ) );
	}
	return $order;
}

/**
 * Fulfil a paid merch Session. Any Session recorded for the order counts,
 * so a buyer who paid from an older tab is still fulfilled.
 *
 * @param object $session Checkout Session.
 * @param string $account Connected account from the event.
 * @return true|WP_Error
 */
function extrachill_shop_fulfil_merch_session( $session, $account ) {
	if ( 'paid' !== $session->payment_status ) {
		return true;
	}
	$order = extrachill_shop_get_merch_session_order( $session, $account );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	if ( $order->get_meta( '_extrachill_payment_intent' ) || $order->is_paid() ) {
		return true;
	}
	// Refunds go to the account that holds the paid charge.
	$order->update_meta_data( '_extrachill_stripe_account', (string) $account );
	$order->update_meta_data( '_extrachill_payment_intent', $session->payment_intent );
	$order->payment_complete( $session->payment_intent );
	$order->save();
	return true;
}

/**
 * A merch Session ended without payment: it expired, or its delayed payment
 * (such as a bank debit) failed.
 *
 * The order is cancelled (expired) or marked failed only while it is still
 * unpaid and this is its latest Session. Starting a new checkout expires the
 * previous Session, and that expiry must not cancel the order the buyer is
 * still paying for.
 *
 * @param object $session Checkout Session.
 * @param string $account Connected account from the event.
 * @param string $outcome 'expired' or 'failed'.
 * @return true|WP_Error
 */
function extrachill_shop_close_merch_session( $session, $account, $outcome ) {
	$order = extrachill_shop_get_merch_session_order( $session, $account );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$sessions = extrachill_shop_order_checkout_sessions( $order );
	if ( (string) array_key_last( $sessions ) !== (string) $session->id || $order->is_paid() || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
		return true;
	}
	if ( 'failed' === $outcome ) {
		$order->update_status( 'failed', 'The Stripe payment failed after checkout (Session ' . $session->id . ').' );
	} else {
		$order->update_status( 'cancelled', 'The Stripe Checkout Session expired before payment (Session ' . $session->id . ').' );
	}
	return true;
}

/**
 * The merch order paid by a PaymentIntent on a connected account.
 *
 * Charges, refunds and disputes do not carry the Checkout Session metadata,
 * so webhooks find the order by the PaymentIntent fulfilment stored on it.
 *
 * @param string $intent  PaymentIntent ID.
 * @param string $account Connected account the charge is on.
 * @return WC_Order|null Null when no order matches this intent and account.
 */
function extrachill_shop_find_merch_order_by_payment_intent( string $intent, $account ) {
	$orders = '' === $intent ? array() : wc_get_orders(
		array(
			'limit'          => 1,
			'payment_method' => 'extrachill_stripe_checkout',
			'meta_key'       => '_extrachill_payment_intent', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed lookup per webhook.
			'meta_value'     => $intent, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One indexed lookup per webhook.
		)
	);
	$order  = $orders ? $orders[0] : null;
	if ( ! $order || (string) $order->get_meta( '_extrachill_stripe_account' ) !== (string) $account ) {
		return null;
	}
	return $order;
}

/**
 * A buyer disputed a merch payment with their bank.
 *
 * On Express direct charges the platform covers a seller's negative
 * balance, so the order goes on hold, gets a note, and the seller and the
 * site admin are emailed to respond before Stripe's deadline.
 *
 * @param object $dispute Stripe dispute.
 * @param string $account Connected account the charge is on.
 * @return true|WP_Error
 */
function extrachill_shop_merch_charge_disputed( $dispute, $account ) {
	$order = extrachill_shop_find_merch_order_by_payment_intent( (string) ( $dispute->payment_intent ?? '' ), $account );
	if ( ! $order ) {
		error_log( 'Extra Chill dispute ' . ( $dispute->id ?? '' ) . ' on ' . $account . ' matches no merch order.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; a retry would not find it either.
		return true;
	}
	$dispute_id = (string) ( $dispute->id ?? '' );
	// A replay, or a `created` event that arrives after the dispute closed.
	if ( $dispute_id === (string) $order->get_meta( '_extrachill_dispute' ) || extrachill_shop_dispute_settled_on( $order, $dispute_id ) ) {
		return true;
	}
	$amount = number_format( ( (int) ( $dispute->amount ?? 0 ) ) / 100, 2 );
	$reason = str_replace( '_', ' ', (string) ( $dispute->reason ?? 'unknown' ) );
	// Remembered so a won dispute puts the order back where it was.
	$order->update_meta_data( EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META, (string) $order->get_status() );
	$order->update_meta_data( '_extrachill_dispute', $dispute_id );
	$order->update_status( 'on-hold', sprintf( 'The buyer disputed $%1$s with their bank (reason: %2$s, Stripe dispute %3$s). Respond in Stripe before the deadline. Do not ship until it is settled.', $amount, $reason, $dispute_id ) );
	$order->save();

	$number  = $order->get_order_number();
	$subject = sprintf( 'Order #%s was disputed', $number );
	$body    = '<p>' . esc_html( sprintf( 'The buyer on order #%1$s disputed their $%2$s payment with their bank (reason: %3$s).', $number, $amount, $reason ) ) . '</p>'
		. '<p>' . esc_html( 'The payment and a dispute fee are on hold. If you have not shipped yet, do not ship: the order is on hold in your shop manager. Respond in your payout dashboard under Payments, then Disputes, before the deadline shown there, or the bank keeps the money with the buyer.' ) . '</p>';
	$seller  = extrachill_shop_get_order_seller( $order );
	if ( null !== $seller ) {
		extrachill_shop_notify_seller(
			$seller,
			array(
				'subject'   => $subject,
				'body_html' => $body,
				'preheader' => sprintf( 'A buyer disputed order #%s. Respond in your payout dashboard before the deadline.', $number ),
				'cta_url'   => 'https://connect.stripe.com/express_login',
				'cta_label' => 'Open payout dashboard',
			)
		);
	}
	$admin = (string) get_option( 'admin_email' );
	if ( '' !== $admin && function_exists( 'ec_send_email' ) ) {
		ec_send_email(
			array(
				'to'       => $admin,
				'subject'  => $subject,
				'template' => 'extrachill/branded',
				'context'  => array(
					'subject_html' => esc_html( $subject ),
					'body_html'    => $body . '<p>' . esc_html( sprintf( 'Connected account %1$s, dispute %2$s. Express sellers\' negative balances fall on the platform.', $account, $dispute_id ) ) . '</p>',
				),
			)
		);
	}
	return true;
}

/**
 * Order meta: the status an order had before a dispute put it on hold.
 */
const EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META = '_extrachill_dispute_prior_status';

/**
 * Order meta: `{dispute ID}:{won|lost}` once a dispute has closed.
 */
const EXTRACHILL_SHOP_DISPUTE_OUTCOME_META = '_extrachill_dispute_outcome';

/**
 * Whether this dispute has already closed on the order.
 *
 * @param WC_Order $order      Order.
 * @param string   $dispute_id Stripe dispute ID.
 * @return bool
 */
function extrachill_shop_dispute_settled_on( $order, string $dispute_id ): bool {
	return '' !== $dispute_id && str_starts_with( (string) $order->get_meta( EXTRACHILL_SHOP_DISPUTE_OUTCOME_META ), $dispute_id . ':' );
}

/**
 * A dispute on a merch payment closed (`charge.dispute.closed`).
 *
 * - Won (`won`, or `warning_closed` for an inquiry that never became a
 *   chargeback): the dispute flag is cleared and the order goes back to the
 *   status it had before the hold (`processing` when that is unknown), so it
 *   returns to Needs Fulfillment and can be shipped, labelled or refunded.
 * - Lost: the bank returned the disputed amount to the buyer. That amount
 *   is recorded as a WooCommerce refund without refunding the payment
 *   again, tagged with the dispute ID. It is capped at what the order has
 *   left to refund, so a `charge.refunded` for the same money, before or
 *   after, is not recorded twice. A fully refunded order becomes
 *   `refunded`; a partial loss puts the order back where it was.
 *
 * The seller is emailed and the order gets a note. Replays are no-ops.
 *
 * @param object $dispute Stripe dispute.
 * @param string $account Connected account the charge is on.
 * @return true|WP_Error WP_Error asks Stripe to retry.
 */
function extrachill_shop_merch_dispute_closed( $dispute, $account ) {
	$order = extrachill_shop_find_merch_order_by_payment_intent( (string) ( $dispute->payment_intent ?? '' ), $account );
	if ( ! $order ) {
		error_log( 'Extra Chill closed dispute ' . ( $dispute->id ?? '' ) . ' on ' . $account . ' matches no merch order.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; a retry would not find it either.
		return true;
	}
	$dispute_id = (string) ( $dispute->id ?? '' );
	$status     = (string) ( $dispute->status ?? '' );
	$won        = in_array( $status, array( 'won', 'warning_closed' ), true );
	if ( '' === $dispute_id || ( ! $won && 'lost' !== $status ) || extrachill_shop_dispute_settled_on( $order, $dispute_id ) ) {
		return true;
	}
	$flag = (string) $order->get_meta( '_extrachill_dispute' );
	if ( '' !== $flag && $flag !== $dispute_id ) {
		// Another dispute on the same order is still open; leave the hold.
		return true;
	}

	$prior = (string) $order->get_meta( EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META );
	if ( '' === $prior || in_array( $prior, array( 'on-hold', 'pending', 'failed', 'cancelled' ), true ) ) {
		$prior = 'processing';
	}
	$amount_minor = (int) ( $dispute->amount ?? 0 );
	$amount       = number_format( $amount_minor / 100, 2 );
	$number       = $order->get_order_number();

	if ( $won ) {
		$note = sprintf( 'The dispute was settled in the seller\'s favor (Stripe dispute %1$s, %2$s). The hold is lifted and the order is back to %3$s.', $dispute_id, $status, $prior );
		$next = $order->has_status( 'on-hold' ) ? $prior : '';
	} else {
		if ( ! in_array( $dispute_id, extrachill_shop_recorded_merch_refunds( $order ), true ) ) {
			$remaining = (int) round( (float) $order->get_remaining_refund_amount() * 100 );
			$minor     = min( $amount_minor, $remaining );
			if ( $minor > 0 ) {
				$wc_refund = wc_create_refund(
					array(
						'order_id'       => (int) $order->get_id(),
						'amount'         => round( $minor / 100, 2 ),
						'reason'         => 'The buyer\'s bank returned the payment after a lost dispute.',
						'refund_payment' => false,
					)
				);
				if ( is_wp_error( $wc_refund ) ) {
					return new WP_Error( 'merch_dispute_record_failed', 'Could not record lost dispute ' . $dispute_id . ': ' . $wc_refund->get_error_message(), array( 'status' => 500 ) );
				}
				$wc_refund->update_meta_data( EXTRACHILL_SHOP_REFUND_ID_META, $dispute_id );
				$wc_refund->save();
			}
		}
		$fresh = wc_get_order( $order->get_id() );
		$left  = (float) ( $fresh instanceof WC_Order ? $fresh : $order )->get_remaining_refund_amount();
		$next  = $left <= 0 ? 'refunded' : ( $order->has_status( 'on-hold' ) ? $prior : '' );
		$note  = sprintf( 'The dispute was lost (Stripe dispute %1$s): the bank returned $%2$s to the buyer. Recorded as a refund; no further payment refund was issued.', $dispute_id, $amount );
	}

	$order->delete_meta_data( '_extrachill_dispute' );
	$order->delete_meta_data( EXTRACHILL_SHOP_DISPUTE_PRIOR_STATUS_META );
	$order->update_meta_data( EXTRACHILL_SHOP_DISPUTE_OUTCOME_META, $dispute_id . ':' . ( $won ? 'won' : 'lost' ) );
	if ( '' !== $next && ! $order->has_status( $next ) ) {
		$order->update_status( $next, $note );
	} else {
		$order->add_order_note( $note );
	}
	$order->save();

	$seller = extrachill_shop_get_order_seller( $order );
	if ( null === $seller ) {
		return true;
	}
	$manager = extrachill_shop_get_shop_manager_url( $seller );
	if ( $won ) {
		$message = array(
			'subject'   => sprintf( 'Order #%s: dispute settled in your favor', $number ),
			'body_html' => '<p>' . esc_html( sprintf( 'The bank settled the dispute on order #%1$s in your favor. You keep the $%2$s payment.', $number, $amount ) ) . '</p>'
				. '<p>' . esc_html( 'The order is no longer on hold. If you have not shipped it yet, it is back in Needs Fulfillment in your shop manager.' ) . '</p>',
			'preheader' => sprintf( 'Order #%s is off hold. You keep the payment.', $number ),
			'cta_url'   => $manager,
			'cta_label' => 'Open shop manager',
		);
	} else {
		$message = array(
			'subject'   => sprintf( 'Order #%s: dispute lost', $number ),
			'body_html' => '<p>' . esc_html( sprintf( 'The bank settled the dispute on order #%1$s in the buyer\'s favor and returned $%2$s to them.', $number, $amount ) ) . '</p>'
				. '<p>' . esc_html( 'We recorded it as a refund on the order. Do not ship it if you have not already. The disputed amount and the dispute fee stay out of your balance.' ) . '</p>',
			'preheader' => sprintf( 'The buyer on order #%s won the dispute.', $number ),
			'cta_url'   => $manager,
			'cta_label' => 'Open shop manager',
		);
	}
	extrachill_shop_notify_seller( $seller, $message );
	return true;
}

// Plugin-owned WooCommerce Stripe Checkout gateway and its blocks payment method.
add_filter( 'woocommerce_payment_gateways', 'extrachill_shop_register_merch_gateway' );
add_action( 'woocommerce_blocks_payment_method_type_registration', 'extrachill_shop_register_merch_blocks_payment_method' );

/**
 * Register the classic gateway.
 *
 * @param array $gateways Gateway class names.
 * @return array
 */
function extrachill_shop_register_merch_gateway( $gateways ) {
	require_once __DIR__ . '/class-merch-gateway.php';
	$gateways[] = 'ExtraChill_Merch_Gateway';
	return $gateways;
}

/**
 * Register the blocks payment method so block checkout can select the gateway.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Payment method registry.
 */
function extrachill_shop_register_merch_blocks_payment_method( $registry ) {
	require_once __DIR__ . '/class-merch-blocks-payment-method.php';
	$registry->register( new ExtraChill_Merch_Blocks_Payment_Method() );
}
