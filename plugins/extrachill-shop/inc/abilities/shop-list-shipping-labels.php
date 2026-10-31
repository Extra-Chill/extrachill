<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-list-shipping-labels
 *
 * List shipping labels across orders for a given artist.
 * Canonical implementation — no existing REST route; this is a new
 * shop-domain ability.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_list_shipping_labels_ability' );

/**
 * Register the shop-list-shipping-labels ability.
 */
function extrachill_shop_register_list_shipping_labels_ability(): void {

	wp_register_ability(
		'extrachill/shop-list-shipping-labels',
		array(
			'label'       => __( 'List Shipping Labels', 'extrachill-shop' ),
			'description' => __( 'List all shipping labels purchased for a given artist across their orders.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
				),
				'required' => array( 'artist_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'labels' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_list_shipping_labels',
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
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * List shipping labels for an artist.
 *
 * Scans all completed/processing orders that have artist payouts,
 * and returns label data for those that have a purchased label.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_list_shipping_labels( array $input ): array|WP_Error {
	$artist_id = (int) ( $input['artist_id'] ?? 0 );

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$orders = wc_get_orders( array(
		'limit'      => -1,
		'status'     => array( 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-on-hold' ),
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => array(
			array(
				'key'     => '_artist_payouts',
				'compare' => 'EXISTS',
			),
		),
	) );

	$labels = array();

	foreach ( $orders as $order ) {
		$payouts = $order->get_meta( '_artist_payouts' ) ?: array();
		if ( ! isset( $payouts[ $artist_id ] ) ) {
			continue;
		}

		$label_url = $order->get_meta( '_artist_label_' . $artist_id ) ?: '';
		if ( empty( $label_url ) ) {
			continue;
		}

		$tracking_number = $order->get_meta( '_artist_tracking_' . $artist_id ) ?: '';
		$label_data      = $order->get_meta( '_artist_label_data_' . $artist_id ) ?: array();

		$labels[] = array(
			'order_id'        => $order->get_id(),
			'order_number'    => $order->get_order_number(),
			'artist_id'       => $artist_id,
			'label_url'       => $label_url,
			'tracking_number' => $tracking_number,
			'carrier'         => $label_data['carrier'] ?? '',
			'service'         => $label_data['service'] ?? '',
			'cost'            => $label_data['cost'] ?? 0,
			'purchased_at'    => $label_data['purchased_at'] ?? '',
		);
	}

	return array( 'labels' => $labels );
}
