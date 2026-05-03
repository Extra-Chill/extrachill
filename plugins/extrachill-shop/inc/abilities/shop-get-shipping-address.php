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
			'label'       => __( 'Get Shipping Address', 'extrachill-shop' ),
			'description' => __( 'Retrieve an artist\'s shipping from-address.', 'extrachill-shop' ),
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
					'artist_id' => array( 'type' => 'integer' ),
					'address'   => array( 'type' => 'object' ),
					'is_set'    => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_get_shipping_address',
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
 * Get artist shipping address.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_get_shipping_address( array $input ): array|WP_Error {
	$artist_id = (int) ( $input['artist_id'] ?? 0 );

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error( 'configuration_error', 'Multisite plugin is not active.', array( 'status' => 500 ) );
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'configuration_error', 'Artist blog is not configured.', array( 'status' => 500 ) );
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

	switch_to_blog( $artist_blog_id );
	try {
		$address = get_post_meta( $artist_id, '_shipping_address', true );
		if ( ! is_array( $address ) ) {
			$address = $default;
		} else {
			$address = wp_parse_args( $address, $default );
		}
	} finally {
		restore_current_blog();
	}

	return array(
		'artist_id' => $artist_id,
		'address'   => $address,
		'is_set'    => ! empty( $address['name'] ) && ! empty( $address['street1'] ),
	);
}
