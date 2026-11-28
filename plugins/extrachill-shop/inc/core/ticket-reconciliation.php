<?php
/**
 * Ticket order reconciliation.
 *
 * Ticket fulfilment is driven by Stripe webhooks. Stripe retries a failed
 * delivery for three days, but a webhook that never arrives (a misconfigured
 * endpoint or secret, an outage longer than the retries) would leave a buyer
 * who paid with no passes and a `pending` order. Pass emails that Events
 * could not queue at issuance would likewise never reach the buyer.
 *
 * A recurring job (Action Scheduler, every 15 minutes) closes both gaps:
 *
 * - Pending ticket orders older than the grace period are checked against
 *   their latest Checkout Session on the seller's account. A paid Session
 *   goes through the same fulfilment as the webhook
 *   (extrachill_shop_fulfil_ticket_session(), idempotent per step); an
 *   expired one cancels the order. Open Sessions are left alone.
 * - Issued orders flagged with `_extrachill_ticket_pass_email_pending` ask
 *   Events to email their active passes again, with a bounded number of
 *   attempts before the order is left for an operator.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/** Action hook the reconciliation job runs on. */
const EXTRACHILL_SHOP_TICKET_RECONCILE_HOOK = 'extrachill_shop_reconcile_ticket_orders';

/** Action Scheduler group. */
const EXTRACHILL_SHOP_TICKET_RECONCILE_GROUP = 'extrachill-shop-tickets';

/** Seconds between runs. */
const EXTRACHILL_SHOP_TICKET_RECONCILE_INTERVAL = 900;

/** Seconds a pending order is left to its webhook before it is reconciled. */
const EXTRACHILL_SHOP_TICKET_RECONCILE_GRACE = 600;

/** Oldest pending order reconciled, in seconds (well past Stripe's 3-day retries). */
const EXTRACHILL_SHOP_TICKET_RECONCILE_MAX_AGE = 7 * 86400;

/** Orders handled per run, for each of the two passes. */
const EXTRACHILL_SHOP_TICKET_RECONCILE_BATCH = 50;

/** Failed pass email retries before the order is left for an operator. */
const EXTRACHILL_SHOP_TICKET_PASS_EMAIL_MAX_ATTEMPTS = 8;

/** Order meta: the Session the reconciliation job fulfilled or closed. */
const EXTRACHILL_SHOP_TICKET_RECONCILED_META = '_extrachill_ticket_reconciled';

add_action( 'init', 'extrachill_shop_schedule_ticket_reconciliation', 20 );
add_action( EXTRACHILL_SHOP_TICKET_RECONCILE_HOOK, 'extrachill_shop_run_ticket_reconciliation' );

/**
 * Schedule the recurring reconciliation job once, where WooCommerce runs.
 *
 * @return bool Whether the job is scheduled.
 */
function extrachill_shop_schedule_ticket_reconciliation(): bool {
	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return false;
	}
	if ( as_has_scheduled_action( EXTRACHILL_SHOP_TICKET_RECONCILE_HOOK, array(), EXTRACHILL_SHOP_TICKET_RECONCILE_GROUP ) ) {
		return true;
	}
	return (bool) as_schedule_recurring_action( time() + EXTRACHILL_SHOP_TICKET_RECONCILE_INTERVAL, EXTRACHILL_SHOP_TICKET_RECONCILE_INTERVAL, EXTRACHILL_SHOP_TICKET_RECONCILE_HOOK, array(), EXTRACHILL_SHOP_TICKET_RECONCILE_GROUP );
}

/**
 * One reconciliation run: missed payments, then undelivered pass emails.
 *
 * @return array{payments: array, emails: array}
 */
function extrachill_shop_run_ticket_reconciliation(): array {
	return array(
		'payments' => extrachill_shop_reconcile_pending_ticket_orders(),
		'emails'   => extrachill_shop_retry_ticket_pass_emails(),
	);
}

/**
 * Reconcile pending ticket orders whose payment webhook has not completed.
 *
 * @param int|null $now Current Unix time (tests).
 * @return array<string, int> Counts keyed `fulfilled`, `closed`, `open` and `failed`.
 */
function extrachill_shop_reconcile_pending_ticket_orders( ?int $now = null ): array {
	$counts = array(
		'fulfilled' => 0,
		'closed'    => 0,
		'open'      => 0,
		'failed'    => 0,
	);
	if ( ! function_exists( 'wc_get_orders' ) || ! extrachill_shop_stripe_init() ) {
		return $counts;
	}
	$now    = $now ?? time();
	$newest = $now - EXTRACHILL_SHOP_TICKET_RECONCILE_GRACE;
	$oldest = $now - EXTRACHILL_SHOP_TICKET_RECONCILE_MAX_AGE;
	/** @var WC_Order[] $orders Not paginated. */
	$orders = wc_get_orders(
		extrachill_shop_ticket_order_query(
			array(
				'limit'        => EXTRACHILL_SHOP_TICKET_RECONCILE_BATCH,
				'status'       => array( 'wc-pending' ),
				'date_created' => $oldest . '...' . $newest,
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		)
	);
	foreach ( $orders as $order ) {
		$created = $order->get_date_created();
		$created = $created ? $created->getTimestamp() : 0;
		if ( $created > $newest || $created < $oldest ) {
			continue;
		}
		$result = extrachill_shop_reconcile_ticket_order( $order );
		if ( is_wp_error( $result ) ) {
			++$counts['failed'];
			error_log( 'Extra Chill ticket reconciliation failed for order ' . $order->get_id() . ': ' . $result->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator visibility; the next run retries.
			continue;
		}
		++$counts[ $result ];
	}
	return $counts;
}

/**
 * Bring one pending ticket order in line with its latest Checkout Session.
 *
 * @param WC_Order $order Pending ticket order.
 * @return string|WP_Error `fulfilled`, `closed` or `open`; an error is retried next run.
 */
function extrachill_shop_reconcile_ticket_order( $order ) {
	if ( ! extrachill_shop_order_is_ticket( $order ) || ! $order->has_status( 'pending' ) ) {
		return 'open';
	}
	$sessions = extrachill_shop_order_checkout_sessions( $order );
	$id       = (string) array_key_last( $sessions );
	$account  = '' === $id ? '' : (string) $sessions[ $id ];
	// Tickets are always charged on the seller's account.
	if ( '' === $id || '' === $account ) {
		return 'open';
	}
	try {
		$session = \Stripe\Checkout\Session::retrieve( $id, array( 'stripe_account' => $account ) );
	} catch ( \Exception $e ) {
		return new WP_Error( 'ticket_session_lookup_failed', 'Could not read Checkout Session ' . $id . ': ' . $e->getMessage(), array( 'status' => 502 ) );
	}
	if ( 'paid' === (string) ( $session->payment_status ?? '' ) ) {
		$result = extrachill_shop_fulfil_ticket_session( $session, $account );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		extrachill_shop_note_ticket_reconciled( $order, $id, sprintf( 'Ticket payment reconciled from Stripe: Checkout Session %s was paid but its webhook never completed fulfilment.', $id ) );
		return 'fulfilled';
	}
	if ( 'expired' === (string) ( $session->status ?? '' ) ) {
		$result = extrachill_shop_close_checkout_session( $session, $account, 'expired', EXTRACHILL_SHOP_TICKET_KIND );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		extrachill_shop_note_ticket_reconciled( $order, $id, '' );
		return 'closed';
	}
	return 'open';
}

/**
 * Record on the order that reconciliation, not the webhook, settled it.
 *
 * @param WC_Order $order Ticket order (possibly a stale copy).
 * @param string   $id    Checkout Session ID.
 * @param string   $note  Order note, or '' for none.
 */
function extrachill_shop_note_ticket_reconciled( $order, string $id, string $note ): void {
	$fresh = wc_get_order( $order->get_id() );
	$order = $fresh instanceof WC_Order ? $fresh : $order;
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_RECONCILED_META, $id );
	if ( '' !== $note ) {
		$order->add_order_note( $note );
	}
	$order->save();
}

/**
 * Retry pass emails for issued orders that Events could not email.
 *
 * @return array{sent: int, failed: int, exhausted: int}
 */
function extrachill_shop_retry_ticket_pass_emails(): array {
	$counts = array(
		'sent'      => 0,
		'failed'    => 0,
		'exhausted' => 0,
	);
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $counts;
	}
	/** @var WC_Order[] $orders Not paginated. */
	$orders = wc_get_orders(
		extrachill_shop_ticket_order_query(
			array(
				'limit'   => EXTRACHILL_SHOP_TICKET_RECONCILE_BATCH,
				'status'  => array( 'wc-processing', 'wc-completed' ),
				'orderby' => 'date',
				'order'   => 'ASC',
			),
			array(
				array(
					'key'   => EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META,
					'value' => '1',
				),
			)
		)
	);
	foreach ( $orders as $order ) {
		$result = extrachill_shop_retry_ticket_order_pass_emails( $order );
		if ( true === $result ) {
			++$counts['sent'];
		} elseif ( 'exhausted' === (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) ) {
			++$counts['exhausted'];
		} else {
			++$counts['failed'];
		}
	}
	return $counts;
}

/**
 * Ask Events to email one order's active passes to the buyer again.
 *
 * Success clears the flag. A failure counts an attempt; after
 * EXTRACHILL_SHOP_TICKET_PASS_EMAIL_MAX_ATTEMPTS the flag becomes
 * `exhausted` and an order note asks an operator to step in. The buyer can
 * always open their passes from My Account > Tickets.
 *
 * @param WC_Order $order Issued ticket order.
 * @return true|WP_Error
 */
function extrachill_shop_retry_ticket_order_pass_emails( $order ) {
	if ( '1' !== (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META ) ) {
		return true;
	}
	$reference = (string) $order->get_meta( EXTRACHILL_SHOP_TICKET_REFERENCE_META );
	$email     = (string) $order->get_billing_email();
	if ( '' === $reference || ! is_email( $email ) ) {
		$result = new WP_Error( 'ticket_pass_email_unaddressable', 'The order has no order reference or buyer email.' );
	} else {
		$result = extrachill_shop_execute_ticket_owner_ability(
			'resend-ticket-pass-emails',
			array(
				'order_reference' => $reference,
				'email'           => $email,
			)
		);
		if ( ! is_wp_error( $result ) && (int) ( $result['failed'] ?? 0 ) > 0 ) {
			$result = new WP_Error( 'ticket_pass_email_failed', sprintf( '%d pass email(s) still could not be sent.', (int) $result['failed'] ) );
		}
	}
	if ( ! is_wp_error( $result ) ) {
		$sent = (int) ( $result['emailed'] ?? 0 );
		$order->delete_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META );
		$order->delete_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META );
		$order->add_order_note( $sent > 0 ? sprintf( 'Re-sent %d pass email(s) to the buyer.', $sent ) : 'No active passes left to email; stopped retrying pass emails.' );
		$order->save();
		return true;
	}
	$attempts = (int) $order->get_meta( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META ) + 1;
	$order->update_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_ATTEMPTS_META, (string) $attempts );
	if ( $attempts >= EXTRACHILL_SHOP_TICKET_PASS_EMAIL_MAX_ATTEMPTS ) {
		$order->update_meta_data( EXTRACHILL_SHOP_TICKET_PASS_EMAIL_PENDING_META, 'exhausted' );
		$order->add_order_note( sprintf( 'Pass emails could not be sent after %1$d attempts (%2$s). Send the buyer their passes manually; they can also open them from My Account > Tickets.', $attempts, $result->get_error_message() ) );
	}
	$order->save();
	return $result;
}
