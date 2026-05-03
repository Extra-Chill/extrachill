<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-create-shipping-label
 *
 * Purchase a shipping label for an artist's portion of an order via Shippo.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_create_shipping_label_ability' );

/**
 * Register the shop-create-shipping-label ability.
 */
function extrachill_shop_register_create_shipping_label_ability(): void {

	wp_register_ability(
		'extrachill/shop-create-shipping-label',
		array(
			'label'       => __( 'Purchase Shipping Label', 'extrachill-shop' ),
			'description' => __( 'Purchase a USPS shipping label via Shippo for an artist\'s portion of an order. Automatically selects the cheapest rate, updates order status, and emails the label.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'order_id'  => array(
						'type'        => 'integer',
						'description' => 'WooCommerce order ID.',
					),
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
				),
				'required' => array( 'order_id', 'artist_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'         => array( 'type' => 'boolean' ),
					'order_id'        => array( 'type' => 'integer' ),
					'artist_id'       => array( 'type' => 'integer' ),
					'label_url'       => array( 'type' => 'string' ),
					'tracking_number' => array( 'type' => 'string' ),
					'carrier'         => array( 'type' => 'string' ),
					'service'         => array( 'type' => 'string' ),
					'cost'            => array( 'type' => 'number' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_create_shipping_label',
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
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Purchase a shipping label for an artist's order items.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_create_shipping_label( array $input ): array|WP_Error {
	$order_id  = (int) ( $input['order_id'] ?? 0 );
	$artist_id = (int) ( $input['artist_id'] ?? 0 );
	$user_id   = get_current_user_id();

	if ( ! function_exists( 'extrachill_api_artist_has_shipping_address' ) ) {
		return new WP_Error( 'configuration_error', 'Shipping address API not available.', array( 'status' => 500 ) );
	}

	if ( ! extrachill_api_artist_has_shipping_address( $artist_id ) ) {
		return new WP_Error(
			'no_shipping_address',
			'Please set up your shipping address in the Settings tab before printing labels.',
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'extrachill_shop_is_shippo_configured' ) || ! extrachill_shop_is_shippo_configured() ) {
		return new WP_Error( 'shippo_not_configured', 'Shipping service is not configured. Please contact support.', array( 'status' => 500 ) );
	}

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

	// Check if order contains only ships-free products.
	if ( function_exists( 'extrachill_api_shop_order_ships_free_only' ) ) {
		$artist_payout = $payouts[ $artist_id ] ?? array();
		if ( extrachill_api_shop_order_ships_free_only( $artist_payout ) ) {
			return new WP_Error(
				'ships_free_order',
				'This order contains only "Ships Free" items. No shipping label is needed—ship these items yourself.',
				array( 'status' => 400 )
			);
		}
	}

	// Return existing label if already purchased.
	$existing_label = $order->get_meta( '_artist_label_' . $artist_id );
	if ( ! empty( $existing_label ) ) {
		$tracking   = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';
		$label_data = $order->get_meta( '_artist_label_data_' . $artist_id ) ?: array();

		return array(
			'success'         => true,
			'reprint'         => true,
			'order_id'        => $order_id,
			'artist_id'       => $artist_id,
			'label_url'       => $existing_label,
			'tracking_number' => $tracking,
			'carrier'         => $label_data['carrier'] ?? 'USPS',
			'service'         => $label_data['service'] ?? '',
			'cost'            => $label_data['cost'] ?? 0,
		);
	}

	$from_address = extrachill_api_get_artist_shipping_address( $artist_id );

	$shipping = $order->get_address( 'shipping' );
	$billing  = $order->get_address( 'billing' );
	$address  = ! empty( $shipping['address_1'] ) ? $shipping : $billing;

	$to_address = array(
		'name'    => trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) ),
		'street1' => $address['address_1'] ?? '',
		'street2' => $address['address_2'] ?? '',
		'city'    => $address['city'] ?? '',
		'state'   => $address['state'] ?? '',
		'zip'     => $address['postcode'] ?? '',
		'country' => $address['country'] ?? 'US',
	);

	if ( 'US' !== $to_address['country'] ) {
		return new WP_Error( 'international_not_supported', 'International shipping is not currently supported.', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'extrachill_shop_shippo_create_label' ) ) {
		return new WP_Error( 'shippo_not_available', 'Shippo integration is not available.', array( 'status' => 500 ) );
	}

	$label_result = extrachill_shop_shippo_create_label( $from_address, $to_address );

	if ( is_wp_error( $label_result ) ) {
		return $label_result;
	}

	$order->update_meta_data( '_artist_label_' . $artist_id, $label_result['label_url'] );
	$order->update_meta_data( '_artist_tracking_' . $artist_id, $label_result['tracking_number'] );
	$order->update_meta_data( '_artist_label_data_' . $artist_id, array(
		'carrier'        => $label_result['carrier'],
		'service'        => $label_result['service'],
		'cost'           => $label_result['cost'],
		'tracking_url'   => $label_result['tracking_url'],
		'rate_id'        => $label_result['rate_id'],
		'transaction_id' => $label_result['transaction_id'],
		'purchased_at'   => current_time( 'mysql' ),
		'purchased_by'   => $user_id,
	) );

	$order->set_status( 'completed', sprintf(
		'Shipping label purchased by %s. Tracking: %s',
		wp_get_current_user()->display_name,
		$label_result['tracking_number']
	) );
	$order->save();

	// Send email notification.
	$user       = get_userdata( $user_id );
	$user_email = $user ? $user->user_email : '';

	if ( $user_email && function_exists( 'extrachill_api_send_label_email' ) ) {
		extrachill_api_send_label_email(
			$user_email,
			$order,
			$artist_id,
			$label_result,
			$payouts[ $artist_id ]
		);
	}

	return array(
		'success'         => true,
		'order_id'        => $order_id,
		'artist_id'       => $artist_id,
		'label_url'       => $label_result['label_url'],
		'tracking_number' => $label_result['tracking_number'],
		'tracking_url'    => $label_result['tracking_url'] ?? '',
		'carrier'         => $label_result['carrier'],
		'service'         => $label_result['service'],
		'cost'            => $label_result['cost'],
	);
}
