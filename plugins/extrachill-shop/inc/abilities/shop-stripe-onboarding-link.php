<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-stripe-onboarding-link
 *
 * Generate a Stripe Connect onboarding link for an artist. Creates
 * the Stripe account if it doesn't exist yet.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_stripe_onboarding_link_ability' );

/**
 * Register the shop-stripe-onboarding-link ability.
 */
function extrachill_shop_register_stripe_onboarding_link_ability(): void {

	wp_register_ability(
		'extrachill/shop-stripe-onboarding-link',
		array(
			'label'       => __( 'Stripe Onboarding Link', 'extrachill-shop' ),
			'description' => __( 'Generate a Stripe Connect onboarding link for a seller (artist, venue, or promoter). Creates the account if needed.', 'extrachill-shop' ),
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
			'execute_callback'    => 'extrachill_shop_ability_stripe_onboarding_link',
			'permission_callback' => 'extrachill_shop_authorize_seller_input',
			'meta' => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Generate a Stripe onboarding link for the artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_stripe_onboarding_link( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	if ( ! function_exists( 'extrachill_shop_create_seller_stripe_account' ) ) {
		return new WP_Error( 'stripe_not_available', 'Stripe integration is not available.', array( 'status' => 500 ) );
	}

	$result = extrachill_shop_create_seller_stripe_account( $seller );
	if ( ! $result['success'] ) {
		return new WP_Error( 'stripe_account_creation_failed', $result['error'], array( 'status' => 500 ) );
	}

	$account_id = $result['account_id'];

	if ( ! function_exists( 'extrachill_shop_create_account_link' ) ) {
		return new WP_Error( 'stripe_not_available', 'Stripe integration is not available.', array( 'status' => 500 ) );
	}

	$link_result = extrachill_shop_create_account_link( $account_id, 'account_onboarding', $seller );

	if ( ! $link_result['success'] ) {
		return new WP_Error( 'stripe_link_creation_failed', $link_result['error'], array( 'status' => 500 ) );
	}

	return array(
		'success' => true,
		'url'     => $link_result['url'],
	);
}
