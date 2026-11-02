<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-refund-order
 *
 * Issue a full refund for an artist's portion of an order.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_refund_order_ability' );

/**
 * Register the shop-refund-order ability.
 */
function extrachill_shop_register_refund_order_ability(): void {

	wp_register_ability(
		'extrachill/shop-refund-order',
		array(
			'label'       => __( 'Refund Shop Order', 'extrachill-shop' ),
			'description' => __( 'Refund a seller\'s portion of an order and reverse that seller\'s payout.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => 'Order ID.',
					),
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
					'seller'    => extrachill_shop_seller_input_schema(),
					'reason'    => array(
						'type'        => 'string',
						'description' => 'Optional refund reason shown on the order.',
					),
				),
				'required' => array( 'id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'order_id'      => array( 'type' => 'integer' ),
					'refund_amount' => array( 'type' => 'number' ),
					'reversed'      => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_refund_order',
			'permission_callback' => 'extrachill_shop_authorize_seller_input',
			'meta' => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Refund a seller's portion of an order.
 *
 * Serialized per order so a double submit cannot race the resumable state in
 * `extrachill_shop_refund_seller_portion()`.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_refund_order( array $input ): array|WP_Error {
	global $wpdb;

	$order_id = (int) ( $input['id'] ?? 0 );
	$seller   = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$lock = 'ec_shop_refund_' . $order_id;
	if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
		return new WP_Error( 'refund_busy', 'Another refund for this order is in progress. Try again shortly.', array( 'status' => 409 ) );
	}

	try {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		return extrachill_shop_refund_seller_portion( $order, $seller, sanitize_text_field( (string) ( $input['reason'] ?? '' ) ) );
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}
