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
			'description' => __( 'Get the Stripe Connect account status for an artist.', 'extrachill-shop' ),
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
					'connected'            => array( 'type' => 'boolean' ),
					'account_id'           => array(
						'anyOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'null' ),
						),
					),
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
			'permission_callback' => static function ( array $input ): bool|WP_Error {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_not_logged_in', 'You must be logged in to access this endpoint.', array( 'status' => 401 ) );
				}
				$artist_id = isset( $input['artist_id'] ) ? (int) $input['artist_id'] : 0;
				if ( ! $artist_id ) {
					return new WP_Error( 'missing_artist_id', 'Artist ID is required.', array( 'status' => 400 ) );
				}
				if ( function_exists( 'ec_can_manage_artist' ) ) {
					if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
						return new WP_Error( 'cannot_manage_artist', 'You do not have access to this artist.', array( 'status' => 403 ) );
					}
					return true;
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
 * Get Stripe Connect status for an artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_stripe_status( array $input ): array|WP_Error {
	$artist_id = (int) ( $input['artist_id'] ?? 0 );

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error( 'configuration_error', 'Artist blog is not configured.', array( 'status' => 500 ) );
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'configuration_error', 'Artist blog is not configured.', array( 'status' => 500 ) );
	}

	switch_to_blog( $artist_blog_id );
	try {
		$account_id = (string) get_post_meta( $artist_id, '_stripe_connect_account_id', true );
	} finally {
		restore_current_blog();
	}

	if ( empty( $account_id ) ) {
		return array(
			'connected'            => false,
			'account_id'           => null,
			'status'               => null,
			'can_receive_payments' => false,
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
	if ( $safe_status ) {
		switch_to_blog( $artist_blog_id );
		try {
			update_post_meta( $artist_id, '_stripe_connect_status', $safe_status );
			update_post_meta( $artist_id, '_stripe_connect_onboarding_complete', ! empty( $status['details_submitted'] ) ? '1' : '0' );
		} finally {
			restore_current_blog();
		}
	}

	return array(
		'connected'            => true,
		'account_id'           => $account_id,
		'status'               => $safe_status,
		'can_receive_payments' => ! empty( $status['can_receive_payments'] ),
		'charges_enabled'      => ! empty( $status['charges_enabled'] ),
		'payouts_enabled'      => ! empty( $status['payouts_enabled'] ),
		'details_submitted'    => ! empty( $status['details_submitted'] ),
	);
}
