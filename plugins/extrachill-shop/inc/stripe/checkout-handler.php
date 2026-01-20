<?php
/**
 * Stripe Checkout Handler
 *
 * Handles WooCommerce checkout integration with Stripe Connect using the
 * "Separate Charges and Transfers" pattern. Groups cart items by artist and
 * transfers artist portions to connected accounts after WooCommerce payment.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Group cart items by artist.
 *
 * @param array $cart_items WooCommerce cart items.
 * @return array Grouped items keyed by artist_profile_id.
 */
function extrachill_shop_group_cart_by_artist( $cart_items ) {
	$grouped = array();

	foreach ( $cart_items as $cart_item_key => $cart_item ) {
		$product_id = $cart_item['product_id'];
		$artist_id  = extrachill_shop_get_product_artist_id( $product_id );

		// Non-artist and platform artist products go to platform group (artist_id = 0).
		if ( ! $artist_id || ( defined( 'EC_PLATFORM_ARTIST_ID' ) && $artist_id === EC_PLATFORM_ARTIST_ID ) ) {
			$artist_id = 0;
		}

		if ( ! isset( $grouped[ $artist_id ] ) ) {
			$grouped[ $artist_id ] = array(
				'artist_id' => $artist_id,
				'items'     => array(),
				'total'     => 0,
			);
		}

		$grouped[ $artist_id ]['items'][ $cart_item_key ] = $cart_item;
		$grouped[ $artist_id ]['total']                  += $cart_item['line_total'];
	}

	return $grouped;
}

/**
 * Validate that all artists in cart have connected Stripe accounts.
 *
 * @return array{valid: bool, invalid_products?: array}
 */
function extrachill_shop_validate_cart_artists() {
	$cart = WC()->cart;
	if ( ! $cart ) {
		return array( 'valid' => true );
	}

	$grouped          = extrachill_shop_group_cart_by_artist( $cart->get_cart() );
	$invalid_products = array();

	foreach ( $grouped as $artist_id => $group ) {
		// Skip platform products (no artist).
		if ( 0 === $artist_id ) {
			continue;
		}

		$stripe_account = extrachill_shop_get_artist_stripe_account( $artist_id );

		if ( ! $stripe_account ) {
			// Artist has no Stripe account connected.
			foreach ( $group['items'] as $cart_item ) {
				$product            = wc_get_product( $cart_item['product_id'] );
				$invalid_products[] = array(
					'product_id'   => $cart_item['product_id'],
					'product_name' => $product ? $product->get_name() : 'Unknown',
					'reason'       => 'artist_not_connected',
				);
			}
			continue;
		}

		// Check if account can receive payments.
		if ( ! extrachill_shop_account_can_receive_payments( $stripe_account ) ) {
			foreach ( $group['items'] as $cart_item ) {
				$product            = wc_get_product( $cart_item['product_id'] );
				$invalid_products[] = array(
					'product_id'   => $cart_item['product_id'],
					'product_name' => $product ? $product->get_name() : 'Unknown',
					'reason'       => 'artist_account_restricted',
				);
			}
		}
	}

	if ( ! empty( $invalid_products ) ) {
		return array(
			'valid'            => false,
			'invalid_products' => $invalid_products,
		);
	}

	return array( 'valid' => true );
}

/**
 * Validate cart at checkout.
 *
 * Hooked to woocommerce_check_cart_items.
 */
function extrachill_shop_validate_cart_at_checkout() {
	$validation = extrachill_shop_validate_cart_artists();

	if ( ! $validation['valid'] ) {
		$product_names = wp_list_pluck( $validation['invalid_products'], 'product_name' );
		$message       = sprintf(
			/* translators: %s: comma-separated list of product names */
			__( 'The following items cannot be purchased at this time: %s. Please remove them from your cart to continue.', 'extrachill-shop' ),
			implode( ', ', $product_names )
		);
		wc_add_notice( $message, 'error' );
	}
}
add_action( 'woocommerce_check_cart_items', 'extrachill_shop_validate_cart_at_checkout' );

/**
 * Calculate charge amounts per artist.
 *
 * @param array $grouped_items Grouped cart items from extrachill_shop_group_cart_by_artist().
 * @return array Charge data per artist.
 */
function extrachill_shop_calculate_artist_charges( $grouped_items ) {
	$charges = array();

	foreach ( $grouped_items as $artist_id => $group ) {
		// Skip platform products for now (handled separately).
		if ( 0 === $artist_id ) {
			continue;
		}

		$total           = $group['total'];
		$commission_rate = extrachill_shop_get_default_commission_rate();
		$application_fee = round( $total * $commission_rate, 2 );
		$artist_payout   = round( $total - $application_fee, 2 );

		$stripe_account = extrachill_shop_get_artist_stripe_account( $artist_id );

		$charges[ $artist_id ] = array(
			'artist_id'       => $artist_id,
			'stripe_account'  => $stripe_account,
			'total'           => $total,
			'application_fee' => $application_fee,
			'artist_payout'   => $artist_payout,
			'items'           => $group['items'],
		);
	}

	return $charges;
}

/**
 * Process artist transfers after WooCommerce payment completes.
 *
 * Uses Stripe's "Separate Charges and Transfers" pattern:
 * - WooCommerce Stripe Gateway charges customer once for full amount
 * - This function creates Transfer objects to move artist portions to connected accounts
 * - Platform keeps commission by simply not transferring it
 *
 * @param WC_Order $order     WooCommerce order.
 * @param string   $charge_id Stripe charge ID from WooCommerce payment.
 * @return array{success: bool, transfers?: array, error?: string}
 */
function extrachill_shop_process_artist_transfers( $order, $charge_id ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	$cart_items = array();
	foreach ( $order->get_items() as $item ) {
		$cart_items[] = array(
			'product_id' => $item->get_product_id(),
			'line_total' => $item->get_total(),
			'quantity'   => $item->get_quantity(),
		);
	}

	$grouped = extrachill_shop_group_cart_by_artist( $cart_items );
	$charges = extrachill_shop_calculate_artist_charges( $grouped );

	$successful_transfers = array();
	$transfer_group       = 'ORDER_' . $order->get_id();

	try {
		foreach ( $charges as $artist_id => $charge_data ) {
			if ( ! $charge_data['stripe_account'] ) {
				throw new \Exception( 'Artist ' . $artist_id . ' does not have a connected Stripe account.' );
			}

			// Transfer artist payout (total minus commission) in cents.
			$transfer_amount_cents = intval( $charge_data['artist_payout'] * 100 );

			$transfer = \Stripe\Transfer::create(
				array(
					'amount'             => $transfer_amount_cents,
					'currency'           => strtolower( get_woocommerce_currency() ),
					'destination'        => $charge_data['stripe_account'],
					'source_transaction' => $charge_id,
					'transfer_group'     => $transfer_group,
					'metadata'           => array(
						'order_id'        => $order->get_id(),
						'artist_id'       => $artist_id,
						'platform'        => 'extrachill',
						'commission_rate' => extrachill_shop_get_default_commission_rate(),
					),
				)
			);

			$successful_transfers[ $artist_id ] = array(
				'transfer_id'     => $transfer->id,
				'amount'          => $charge_data['artist_payout'],
				'application_fee' => $charge_data['application_fee'],
				'status'          => 'transferred',
			);
		}

		// Store transfer data on order.
		$order->update_meta_data( '_stripe_transfers', $successful_transfers );
		$order->update_meta_data( '_artist_payouts', $charges );
		$order->update_meta_data( '_stripe_transfer_group', $transfer_group );
		$order->save();

		return array(
			'success'   => true,
			'transfers' => $successful_transfers,
		);

	} catch ( \Exception $e ) {
		// Transfers can be reversed if needed, but typically we'd investigate manually.
		// Log the error and failed state for admin review.
		error_log( 'Stripe transfer failed for order ' . $order->get_id() . ': ' . $e->getMessage() );

		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}
}

/**
 * Get or create a Stripe customer for the order.
 *
 * @param WC_Order $order WooCommerce order.
 * @return \Stripe\Customer Stripe customer object.
 */
function extrachill_shop_get_or_create_stripe_customer( $order ) {
	$user_id       = $order->get_user_id();
	$customer_id   = '';
	$billing_email = $order->get_billing_email();

	// Check for existing customer ID.
	if ( $user_id ) {
		$customer_id = get_user_meta( $user_id, '_stripe_customer_id', true );
	}

	if ( $customer_id ) {
		try {
			return \Stripe\Customer::retrieve( $customer_id );
		} catch ( \Exception $e ) {
			// Customer doesn't exist, create new one.
		}
	}

	// Create new customer.
	$customer = \Stripe\Customer::create(
		array(
			'email'    => $billing_email,
			'name'     => $order->get_formatted_billing_full_name(),
			'metadata' => array(
				'wordpress_user_id' => $user_id,
				'platform'          => 'extrachill',
			),
		)
	);

	// Store customer ID for future use.
	if ( $user_id ) {
		update_user_meta( $user_id, '_stripe_customer_id', $customer->id );
	}

	return $customer;
}

/**
 * Handle charge failure with rollback.
 *
 * @param array  $successful_charges Charges that succeeded before failure.
 * @param int    $failed_artist_id   The artist whose charge failed.
 * @param string $error_message      The error message.
 * @return array Rollback result.
 */
function extrachill_shop_handle_charge_failure( $successful_charges, $failed_artist_id, $error_message ) {
	$refunded = array();
	$failed   = array();

	foreach ( $successful_charges as $artist_id => $charge ) {
		try {
			\Stripe\Refund::create(
				array(
					'payment_intent' => $charge['payment_intent_id'],
				)
			);
			$refunded[] = $artist_id;
		} catch ( \Exception $e ) {
			$failed[ $artist_id ] = $e->getMessage();
			error_log( 'Rollback refund failed for artist ' . $artist_id . ': ' . $e->getMessage() );
		}
	}

	return array(
		'refunded'         => $refunded,
		'failed_refunds'   => $failed,
		'original_error'   => $error_message,
		'failed_artist_id' => $failed_artist_id,
	);
}

/**
 * Check if order contains only platform products (no artist products).
 *
 * @param WC_Order $order WooCommerce order.
 * @return bool True if order has no artist products.
 */
function extrachill_shop_order_is_platform_only( $order ) {
	foreach ( $order->get_items() as $item ) {
		$artist_id = extrachill_shop_get_product_artist_id( $item->get_product_id() );
		// Platform artist products are treated as platform products.
		if ( $artist_id && ( ! defined( 'EC_PLATFORM_ARTIST_ID' ) || $artist_id !== EC_PLATFORM_ARTIST_ID ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Get order charges by artist.
 *
 * @param WC_Order $order WooCommerce order.
 * @return array Charges indexed by artist ID.
 */
function extrachill_shop_get_order_charges( $order ) {
	return $order->get_meta( '_stripe_charges' ) ?: array();
}

/**
 * Get order artist payouts.
 *
 * @param WC_Order $order WooCommerce order.
 * @return array Payout data indexed by artist ID.
 */
function extrachill_shop_get_order_artist_payouts( $order ) {
	return $order->get_meta( '_artist_payouts' ) ?: array();
}
