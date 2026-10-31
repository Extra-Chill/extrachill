<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-stripe-dashboard-link
 *
 * Generate a Stripe Express dashboard login link for an artist.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_stripe_dashboard_link_ability' );

/**
 * Register the shop-stripe-dashboard-link ability.
 */
function extrachill_shop_register_stripe_dashboard_link_ability(): void {

	wp_register_ability(
		'extrachill/shop-stripe-dashboard-link',
		array(
			'label'       => __( 'Stripe Dashboard Link', 'extrachill-shop' ),
			'description' => __( 'Generate a Stripe Express dashboard login link for an artist\'s connected account.', 'extrachill-shop' ),
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
					'success' => array( 'type' => 'boolean' ),
					'url'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_stripe_dashboard_link',
			'permission_callback' => static function ( array $input ): bool|WP_Error {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_not_logged_in', 'You must be logged in to access this endpoint.', array( 'status' => 401 ) );
				}
				$artist_id = isset( $input['artist_id'] ) ? (int) $input['artist_id'] : 0;
				if ( ! $artist_id ) {
					return new WP_Error( 'missing_artist_id', 'Artist ID is required.', array( 'status' => 400 ) );
				}
				return extrachill_shop_current_user_can_manage_artist( $artist_id );
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
 * Generate a Stripe dashboard link for the artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_stripe_dashboard_link( array $input ): array|WP_Error {
	$artist_id = (int) ( $input['artist_id'] ?? 0 );

	$account_id = extrachill_shop_get_artist_stripe_account( $artist_id );

	if ( empty( $account_id ) ) {
		return new WP_Error( 'no_stripe_account', 'No Stripe account connected.', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'extrachill_shop_create_dashboard_link' ) ) {
		return new WP_Error( 'stripe_not_available', 'Stripe integration is not available.', array( 'status' => 500 ) );
	}

	$link_result = extrachill_shop_create_dashboard_link( $account_id );

	if ( ! $link_result['success'] ) {
		return new WP_Error( 'stripe_dashboard_link_failed', $link_result['error'], array( 'status' => 500 ) );
	}

	return array(
		'success' => true,
		'url'     => $link_result['url'],
	);
}
