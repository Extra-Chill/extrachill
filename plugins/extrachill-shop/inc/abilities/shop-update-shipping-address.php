<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-update-shipping-address
 *
 * Update an artist's shipping from-address.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_update_shipping_address_ability' );

/**
 * Register the shop-update-shipping-address ability.
 */
function extrachill_shop_register_update_shipping_address_ability(): void {

	wp_register_ability(
		'extrachill/shop-update-shipping-address',
		array(
			'label'               => __( 'Update Shipping Address', 'extrachill-shop' ),
			'description'         => __( 'Update a seller\'s ship-from address and/or their published shipping times and return policy.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'seller'        => extrachill_shop_seller_input_schema(),
					'name'          => array(
						'type'        => 'string',
						'description' => 'Full name.',
					),
					'street1'       => array(
						'type'        => 'string',
						'description' => 'Street address line 1.',
					),
					'street2'       => array(
						'type'        => 'string',
						'description' => 'Street address line 2.',
					),
					'city'          => array(
						'type'        => 'string',
						'description' => 'City.',
					),
					'state'         => array(
						'type'        => 'string',
						'description' => 'State.',
					),
					'zip'           => array(
						'type'        => 'string',
						'description' => 'ZIP code.',
					),
					'country'       => array(
						'type'        => 'string',
						'default'     => 'US',
						'description' => 'Country code.',
					),
					'return_policy' => array(
						'type'        => 'string',
						'description' => 'Shipping times and return policy shown on the storefront and product pages. Send alone to update only the policy.',
					),
				),
				'required'             => array( 'seller' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'seller'        => extrachill_shop_seller_input_schema(),
					'address'       => array( 'type' => 'object' ),
					'return_policy' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_update_shipping_address',
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
 * Update a seller's ship-from address.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_update_shipping_address( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}

	$has_policy  = array_key_exists( 'return_policy', $input );
	$has_address = false;
	foreach ( array( 'name', 'street1', 'street2', 'city', 'state', 'zip' ) as $field ) {
		$has_address = $has_address || array_key_exists( $field, $input );
	}
	if ( ! $has_address && ! $has_policy ) {
		return new WP_Error( 'invalid_address', 'Name, street, city, state and ZIP are required.', array( 'status' => 400 ) );
	}

	if ( $has_address ) {
		$address = array(
			'name'    => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'street1' => sanitize_text_field( (string) ( $input['street1'] ?? '' ) ),
			'street2' => sanitize_text_field( (string) ( $input['street2'] ?? '' ) ),
			'city'    => sanitize_text_field( (string) ( $input['city'] ?? '' ) ),
			'state'   => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
			'zip'     => sanitize_text_field( (string) ( $input['zip'] ?? '' ) ),
			'country' => 'US',
		);

		foreach ( array( 'name', 'street1', 'city', 'state', 'zip' ) as $field ) {
			if ( '' === $address[ $field ] ) {
				return new WP_Error( 'invalid_address', 'Name, street, city, state and ZIP are required.', array( 'status' => 400 ) );
			}
		}
		if ( $has_policy ) {
			$address['return_policy'] = extrachill_shop_sanitize_return_policy( $input['return_policy'] );
		}
		$result = extrachill_shop_set_seller_shipping_address( $seller, $address );
	} else {
		// Policy only: the ship-from address is left as it is.
		$result = extrachill_shop_set_seller_return_policy( $seller, extrachill_shop_sanitize_return_policy( $input['return_policy'] ) );
	}

	if ( false === $result ) {
		return new WP_Error( 'save_failed', 'Failed to save shipping settings.', array( 'status' => 500 ) );
	}

	$saved = extrachill_shop_get_seller_shipping_address( $seller );
	$saved = is_array( $saved ) ? $saved : array();
	unset( $saved['return_policy'] );

	return array(
		'success'       => true,
		'seller'        => $seller,
		'address'       => $saved,
		'return_policy' => extrachill_shop_get_seller_return_policy( $seller ),
	);
}
