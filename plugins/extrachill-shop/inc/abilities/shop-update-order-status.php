<?php
declare(strict_types=1);
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

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_update_order_status_ability' );

/**
 * Register the shop-update-order-status ability.
 */
function extrachill_shop_register_update_order_status_ability(): void {

	wp_register_ability(
		'extrachill/shop-update-order-status',
		array(
			'label'       => __( 'Update Order Status', 'extrachill-shop' ),
			'description' => __( 'Update the status of an order, optionally adding a tracking number.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'              => array(
						'type'        => 'integer',
						'description' => 'Order ID.',
					),
					'artist_id'       => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
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
				'required' => array( 'id', 'artist_id', 'status' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'              => array( 'type' => 'integer' ),
					'status'          => array( 'type' => 'string' ),
					'tracking_number' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_update_order_status',
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
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Update order status for an artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_update_order_status( array $input ): array|WP_Error {
	$order_id        = (int) ( $input['id'] ?? 0 );
	$artist_id       = (int) ( $input['artist_id'] ?? 0 );
	$new_status      = (string) ( $input['status'] ?? '' );
	$tracking_number = isset( $input['tracking_number'] ) ? sanitize_text_field( (string) $input['tracking_number'] ) : '';

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}

	$payouts = $order->get_meta( '_artist_payouts' ) ?: array();
	if ( ! isset( $payouts[ $artist_id ] ) ) {
		return new WP_Error( 'rest_forbidden', 'This order does not contain products from your artist.', array( 'status' => 403 ) );
	}

	if ( $tracking_number ) {
		$order->update_meta_data( '_artist_tracking_' . $artist_id, $tracking_number );
	}

	if ( 'completed' === $new_status ) {
		$order->set_status( 'completed', 'Order marked as shipped by artist.' );
	}

	$order->save();

	return extrachill_shop_ability_build_order_response( $order, $artist_id );
}
