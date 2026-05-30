<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-delete-product
 *
 * Delete (trash) a WooCommerce product.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_delete_product_ability' );

/**
 * Register the shop-delete-product ability.
 */
function extrachill_shop_register_delete_product_ability(): void {

	wp_register_ability(
		'extrachill/shop-delete-product',
		array(
			'label'       => __( 'Delete Shop Product', 'extrachill-shop' ),
			'description' => __( 'Move a WooCommerce product to the trash.', 'extrachill-shop' ),
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
					'deleted'    => array( 'type' => 'boolean' ),
					'product_id' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_delete_product',
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
				if ( ! extrachill_shop_user_can_manage_artist( $artist_id ) ) {
					return new WP_Error( 'rest_forbidden', 'You do not have permission to manage this product.', array( 'status' => 403 ) );
				}
				return true;
			},
			'meta' => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Delete (trash) a product.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_delete_product( array $input ): array|WP_Error {
	$product_id = (int) ( $input['id'] ?? 0 );

	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
	}

	$result = wp_trash_post( $product_id );

	if ( ! $result ) {
		return new WP_Error( 'delete_failed', 'Failed to delete product.', array( 'status' => 500 ) );
	}

	return array(
		'deleted'    => true,
		'product_id' => $product_id,
	);
}
