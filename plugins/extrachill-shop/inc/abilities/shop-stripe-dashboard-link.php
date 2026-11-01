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
			'description' => __( 'Generate a Stripe Express dashboard login link for a seller\'s connected account (artist, venue, or promoter).', 'extrachill-shop' ),
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
					'success' => array( 'type' => 'boolean' ),
					'url'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_stripe_dashboard_link',
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
 * Generate a Stripe dashboard link for the artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_stripe_dashboard_link( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	$account_id = extrachill_shop_get_seller_stripe_account( $seller );

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
