<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-get-shipping-address
 *
 * Retrieve an artist's shipping from-address.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_get_shipping_address_ability' );

/**
 * Register the shop-get-shipping-address ability.
 */
function extrachill_shop_register_get_shipping_address_ability(): void {

	wp_register_ability(
		'extrachill/shop-get-shipping-address',
		array(
			'label'               => __( 'Get Shipping Address', 'extrachill-shop' ),
			'description'         => __( 'Retrieve an artist\'s shipping from-address.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'seller'    => extrachill_shop_seller_input_schema(),
					'artist_id' => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'seller'    => extrachill_shop_seller_input_schema(),
					'artist_id' => array( 'type' => 'integer' ),
					'address'   => array( 'type' => 'object' ),
					'is_set'    => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_get_shipping_address',
			'permission_callback' => 'extrachill_shop_authorize_seller_input',
			'meta'                => array(
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
 * Get artist shipping address.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_get_shipping_address( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	$default = array(
		'name'    => '',
		'street1' => '',
		'street2' => '',
		'city'    => '',
		'state'   => '',
		'zip'     => '',
		'country' => 'US',
	);

	$address = extrachill_shop_get_seller_shipping_address( $seller );
	$address = is_array( $address ) ? array_merge( $default, $address ) : $default;

	return array(
		'seller'    => $seller,
		'artist_id' => 'artist' === $seller['type'] ? $seller['id'] : 0,
		'address'   => $address,
		'is_set'    => ! empty( $address['name'] ) && ! empty( $address['street1'] ),
	);
}
