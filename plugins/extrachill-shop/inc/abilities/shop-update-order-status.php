<?php
/**
 * Ability: extrachill/shop-update-order-status
 *
 * Update the status of an order (e.g. mark as shipped/completed).
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_update_order_status_ability' );

/**
 * Register the shop-update-order-status ability.
 */
function extrachill_shop_register_update_order_status_ability(): void {

	wp_register_ability(
		'extrachill/shop-update-order-status',
		array(
			'label'               => __( 'Update Order Status', 'extrachill-shop' ),
			'description'         => __( 'Update the status of an order, optionally adding a tracking number.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'              => array(
						'type'        => 'integer',
						'description' => 'Order ID.',
					),
					'seller'          => extrachill_shop_seller_input_schema(),
					'status'          => array(
						'type'        => 'string',
						'enum'        => array( 'completed' ),
						'description' => 'New order status.',
					),
					'tracking_number' => array(
						'type'        => 'string',
						'description' => 'Optional tracking number.',
					),
				),
				'required'             => array( 'id', 'seller', 'status' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'              => array( 'type' => 'integer' ),
					'status'          => array( 'type' => 'string' ),
					'tracking_number' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_update_order_status',
			'permission_callback' => 'extrachill_shop_authorize_seller_input',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Update order status for a seller.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_update_order_status( array $input ): array|WP_Error {
	$order_id        = (int) ( $input['id'] ?? 0 );
	$new_status      = (string) ( $input['status'] ?? '' );
	$tracking_number = isset( $input['tracking_number'] ) ? sanitize_text_field( (string) $input['tracking_number'] ) : '';

	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}
	/** @var WC_Order $order */

	if ( ! extrachill_shop_order_has_seller( $order, $seller ) ) {
		return new WP_Error( 'rest_forbidden', 'This order does not contain products from this seller.', array( 'status' => 403 ) );
	}

	// A disputed order is on hold until the bank decides; shipping it now
	// risks losing both the goods and the money.
	if ( extrachill_shop_order_is_disputed( $order ) ) {
		return extrachill_shop_order_disputed_error();
	}

	// Shipped without an Extra Chill label: the seller paid the postage, so
	// the shipping held in the application fee goes back to them.
	if ( 'completed' === $new_status && '' === extrachill_shop_get_order_shipment( $order )['label_url'] ) {
		$released = extrachill_shop_release_shipping_hold( $order );
		if ( is_wp_error( $released ) ) {
			return $released;
		}
	}

	// Moving to completed sends WooCommerce's Completed order email, which
	// carries the tracking (buyer-order-details.php). An order that is
	// already completed gets no new status email, so new tracking reaches
	// the buyer as a customer note instead.
	$already_shipped = 'completed' === $order->get_status();
	$new_tracking    = '' !== $tracking_number && extrachill_shop_get_order_shipment( $order )['tracking_number'] !== $tracking_number;

	if ( $tracking_number ) {
		extrachill_shop_update_order_shipment( $order, array( 'tracking_number' => $tracking_number ) );
	}

	if ( 'completed' === $new_status ) {
		$order->set_status( 'completed', 'Order marked as shipped by seller.' );
	}

	$order->save();

	if ( $already_shipped && $new_tracking ) {
		$order->add_order_note( __( 'Your seller added tracking to this order.', 'extrachill-shop' ), 1, false );
	}

	return extrachill_shop_ability_build_order_response( $order );
}
