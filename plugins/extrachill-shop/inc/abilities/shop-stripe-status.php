<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-stripe-status
 *
 * Get the Stripe Connect account status for an artist.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_stripe_status_ability' );

/**
 * Register the shop-stripe-status ability.
 */
function extrachill_shop_register_stripe_status_ability(): void {

	wp_register_ability(
		'extrachill/shop-stripe-status',
		array(
			'label'       => __( 'Stripe Connect Status', 'extrachill-shop' ),
			'description' => __( 'Get the Stripe Connect account status for a seller (artist, venue, or promoter).', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
					'seller'    => extrachill_shop_seller_input_schema(),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'connected'            => array( 'type' => 'boolean' ),
					'status'               => array(
						'anyOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'null' ),
						),
					),
					'can_receive_payments' => array( 'type' => 'boolean' ),
					'charges_enabled'      => array( 'type' => 'boolean' ),
					'payouts_enabled'      => array( 'type' => 'boolean' ),
					'details_submitted'    => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_stripe_status',
			'permission_callback' => 'extrachill_shop_authorize_seller_input',
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
 * Get Stripe Connect status for an artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_stripe_status( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	$account_id = extrachill_shop_get_seller_stripe_account( $seller );

	if ( empty( $account_id ) ) {
		return array(
			'connected'            => false,
			'status'               => null,
			'can_receive_payments' => false,
			'charges_enabled'      => false,
			'payouts_enabled'      => false,
			'details_submitted'    => false,
		);
	}

	if ( ! function_exists( 'extrachill_shop_get_account_status' ) ) {
		return new WP_Error( 'stripe_not_available', 'Stripe integration is not available.', array( 'status' => 500 ) );
	}

	$status = extrachill_shop_get_account_status( $account_id );

	if ( ! $status['success'] ) {
		return new WP_Error( 'stripe_status_check_failed', $status['error'], array( 'status' => 500 ) );
	}

	$safe_status = isset( $status['status'] ) ? (string) $status['status'] : '';
	extrachill_shop_set_seller_stripe_account_record(
		$seller,
		array(
			'account_id'          => $account_id,
			'status'              => $safe_status,
			'onboarding_complete' => ! empty( $status['details_submitted'] ),
		)
	);

	return array(
		'connected'            => true,
		'status'               => $safe_status,
		'can_receive_payments' => ! empty( $status['can_receive_payments'] ),
		'charges_enabled'      => ! empty( $status['charges_enabled'] ),
		'payouts_enabled'      => ! empty( $status['payouts_enabled'] ),
		'details_submitted'    => ! empty( $status['details_submitted'] ),
	);
}
