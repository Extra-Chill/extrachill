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
			'label'               => __( 'Get Shop Product', 'extrachill-shop' ),
			'description'         => __( 'Get a single product by ID with full details.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Product ID.',
					),
				),
				'required'   => array( 'id' ),
			),
			'output_schema'       => array(
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
			'permission_callback' => 'extrachill_shop_authorize_product_input',
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
