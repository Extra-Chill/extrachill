<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-get-product
 *
 * Get a single product by ID.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_get_product_ability' );

/**
 * Register the shop-get-product ability.
 */
function extrachill_shop_register_get_product_ability(): void {

	wp_register_ability(
		'extrachill/shop-get-product',
		array(
			'label'       => __( 'Get Shop Product', 'extrachill-shop' ),
			'description' => __( 'Get a single product by ID with full details.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Product ID.',
					),
				),
				'required' => array( 'id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'price'       => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_get_product',
			'permission_callback' => static function ( array $input ): bool|WP_Error {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
				}
				if ( current_user_can( 'manage_options' ) ) {
					return true;
				}
				$product_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$product_post = get_post( $product_id );
				if ( ! $product_post || 'product' !== $product_post->post_type ) {
					return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
				}
				$artist_id = (int) get_post_meta( $product_id, '_artist_profile_id', true );
				if ( ! $artist_id ) {
					return new WP_Error( 'rest_forbidden', 'You do not have permission to manage this product.', array( 'status' => 403 ) );
				}
				if ( function_exists( 'extrachill_api_shop_user_can_manage_artist' ) ) {
					if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
						return new WP_Error( 'rest_forbidden', 'You do not have permission to manage this product.', array( 'status' => 403 ) );
					}
					return true;
				}
				if ( function_exists( 'ec_can_manage_artist' ) ) {
					return ec_can_manage_artist( get_current_user_id(), $artist_id );
				}
				return false;
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
 * Get a single product.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_get_product( array $input ): array|WP_Error {
	$product_id = (int) ( $input['id'] ?? 0 );

	if ( ! function_exists( 'extrachill_shop_ability_build_product_response' ) ) {
		return new WP_Error( 'dependency_missing', 'Product helper is not loaded.', array( 'status' => 500 ) );
	}

	return extrachill_shop_ability_build_product_response( $product_id );
}
