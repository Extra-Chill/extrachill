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
	} elseif ( $seller ) {
		$account = extrachill_shop_get_seller_stripe_account( $seller );
		if ( ! $account || ! extrachill_shop_account_can_receive_payments( $account ) ) {
			wc_add_notice( 'Seller cannot receive payments. Please try again later.', 'error' );
		}
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
		if ( ! $account || ! extrachill_shop_account_can_receive_payments( $account ) ) {
			return new WP_Error( 'seller_payments_unavailable', 'Seller cannot receive payments. Please try again later.' );
		}
	}
	$items       = array();
	$fee         = 0;
	$total_minor = (int) round( $order->get_total() * 100 );
	$items_minor = 0;
	foreach ( $order->get_items() as $item ) {
		// One Stripe line per discounted order line avoids unit rounding drift.
		$amount       = (int) round( ( $item->get_total() + $item->get_total_tax() ) * 100 );
		$items_minor += $amount;
		$items[]      = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => strtolower( $order->get_currency() ),
				'unit_amount'  => $amount,
				'product_data' => array( 'name' => $item->get_name() . ' × ' . $item->get_quantity() ),
			),
		);
		$fee         += (int) round( extrachill_shop_calculate_commission( $item->get_total(), $item->get_product_id() ) * 100 );
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
	$metadata = array(
		'kind'     => 'extrachill_merch',
		'order_id' => (string) $order->get_id(),
	);
	$data     = array(
		'mode'                => 'payment',
		'line_items'          => $items,
		'metadata'            => $metadata,
		'payment_intent_data' => array( 'metadata' => $metadata ),
		'success_url'         => $order->get_checkout_order_received_url(),
		'cancel_url'          => wc_get_cart_url(),
		'customer_email'      => $order->get_billing_email(),
	);
	$options  = array( 'idempotency_key' => 'ec-merch-' . $order->get_id() . '-' . hash( 'sha256', wp_json_encode( array( $account, $data ) ) ) );
	if ( $account ) {
		$options['stripe_account']                             = $account;
		$data['payment_intent_data']['application_fee_amount'] = min( $total_minor, max( 0, $fee ) );
	}
	try {
		$session = \Stripe\Checkout\Session::create( $data, $options );
		$fee     = $account ? min( $total_minor, max( 0, $fee ) ) : 0;
		$order->update_meta_data( '_extrachill_stripe_account', $account );
		$order->update_meta_data( '_extrachill_checkout_session', $session->id );
		// The canonical order seller record (see order-seller-index.php).
		$order->update_meta_data( '_extrachill_seller', $seller ? $seller['type'] . ':' . (int) $seller['id'] : '' );
		$order->update_meta_data( '_extrachill_application_fee', $fee );
		$order->save();
		return $session;
	} catch ( \Exception $e ) {
		return new WP_Error( 'merch_checkout_failed', 'Checkout failed. Please try again.' );
	}
}

function extrachill_shop_fulfil_merch_session( $session, $account ) {
	if ( 'paid' !== $session->payment_status ) {
		return true;
	}
	$order = wc_get_order( (int) ( $session->metadata->order_id ?? 0 ) );
	if ( ! $order || 'extrachill_stripe_checkout' !== $order->get_payment_method() || $order->get_meta( '_extrachill_checkout_session' ) !== $session->id || (string) $order->get_meta( '_extrachill_stripe_account' ) !== (string) $account ) {
		return new WP_Error( 'merch_account_mismatch', 'Unknown merch order or account.', array( 'status' => 409 ) );
	}
	if ( $order->get_meta( '_extrachill_payment_intent' ) || $order->is_paid() ) {
		return true;
	}
	$order->update_meta_data( '_extrachill_payment_intent', $session->payment_intent );
	$order->payment_complete( $session->payment_intent );
	$order->save();
	return true;
}
