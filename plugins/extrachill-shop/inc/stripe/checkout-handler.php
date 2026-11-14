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
	$fee            = $account ? min( $total_minor, extrachill_commerce_fee( 'extrachill_merch', $subtotal_minor, $seller )['application_fee'] + $shipping_minor ) : 0;
	$sessions       = extrachill_shop_order_checkout_sessions( $order );
	$closed         = extrachill_shop_expire_previous_checkout_session( $sessions );
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
		$order->update_meta_data( '_extrachill_seller', $seller );
		$order->update_meta_data( EXTRACHILL_SHOP_SHIPPING_HOLD_META, $shipping_minor );
		// Keep reporting and seller refund readers compatible with existing orders.
		if ( $seller ) {
			$payout = array(
				'total'           => (float) $order->get_total(),
				'artist_payout'   => ( $total_minor - $fee ) / 100,
				'application_fee' => $fee / 100,
				'shipping_held'   => $shipping_minor / 100,
				'stripe_account'  => $account,
			);
			$order->update_meta_data( 'artist' === $seller['type'] ? '_artist_payouts' : '_seller_payouts', array( extrachill_shop_seller_key( $seller ) => $payout ) );
		}
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
	$intent = (string) ( $dispute->payment_intent ?? '' );
	$orders = '' === $intent ? array() : wc_get_orders(
		array(
			'limit'          => 1,
			'payment_method' => 'extrachill_stripe_checkout',
			'meta_key'       => '_extrachill_payment_intent', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed lookup per dispute.
			'meta_value'     => $intent, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One indexed lookup per dispute.
		)
	);
	$order  = $orders ? $orders[0] : null;
	if ( ! $order || (string) $order->get_meta( '_extrachill_stripe_account' ) !== (string) $account ) {
		error_log( 'Extra Chill dispute ' . ( $dispute->id ?? '' ) . ' on ' . $account . ' matches no merch order.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; a retry would not find it either.
		return true;
	}
	$dispute_id = (string) ( $dispute->id ?? '' );
	if ( $dispute_id === (string) $order->get_meta( '_extrachill_dispute' ) ) {
		return true;
	}
	$amount = number_format( ( (int) ( $dispute->amount ?? 0 ) ) / 100, 2 );
	$reason = str_replace( '_', ' ', (string) ( $dispute->reason ?? 'unknown' ) );
	$order->update_meta_data( '_extrachill_dispute', $dispute_id );
	$order->update_status( 'on-hold', sprintf( 'The buyer disputed $%1$s with their bank (reason: %2$s, Stripe dispute %3$s). Respond in Stripe before the deadline. Do not ship until it is settled.', $amount, $reason, $dispute_id ) );
	$order->save();

	$number  = $order->get_order_number();
	$subject = sprintf( 'Order #%s was disputed', $number );
	$body    = '<p>' . esc_html( sprintf( 'The buyer on order #%1$s disputed their $%2$s payment with their bank (reason: %3$s).', $number, $amount, $reason ) ) . '</p>'
		. '<p>' . esc_html( 'Stripe has held the payment and a dispute fee. If you have not shipped yet, hold the order. Respond in your Stripe dashboard under Payments, then Disputes, before the deadline shown there, or the bank keeps the money with the buyer.' ) . '</p>';
	$seller  = $order->get_meta( '_extrachill_seller' );
	if ( is_array( $seller ) && ! is_wp_error( extrachill_shop_validate_seller_ref( $seller ) ) ) {
		extrachill_shop_notify_seller(
			$seller,
			array(
				'subject'   => $subject,
				'body_html' => $body,
				'preheader' => sprintf( 'A buyer disputed order #%s. Respond in Stripe before the deadline.', $number ),
				'cta_url'   => 'https://connect.stripe.com/express_login',
				'cta_label' => 'Open Stripe',
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
