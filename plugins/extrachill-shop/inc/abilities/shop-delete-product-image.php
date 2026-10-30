<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-delete-product-image
 *
 * Delete a single image from a product.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_delete_product_image_ability' );

/**
 * Register the shop-delete-product-image ability.
 */
function extrachill_shop_register_delete_product_image_ability(): void {

	wp_register_ability(
		'extrachill/shop-delete-product-image',
		array(
			'label'       => __( 'Delete Product Image', 'extrachill-shop' ),
			'description' => __( 'Remove a single image from a product. Products must keep at least one image.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'            => array(
						'type'        => 'integer',
						'description' => 'Product ID.',
					),
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID of the image to delete.',
					),
				),
				'required' => array( 'id', 'attachment_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'images' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_delete_product_image',
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
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Delete a single image from a product.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_delete_product_image( array $input ): array|WP_Error {
	$product_id    = (int) ( $input['id'] ?? 0 );
	$attachment_id = (int) ( $input['attachment_id'] ?? 0 );

	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
	}

	// Get current ordered image IDs.
	$ordered_ids = extrachill_shop_ability_delete_image_get_ordered_ids( $product_id );
	if ( ! in_array( $attachment_id, $ordered_ids, true ) ) {
		return new WP_Error( 'image_not_found', 'Image not found.', array( 'status' => 404 ) );
	}

	if ( count( $ordered_ids ) <= 1 ) {
		return new WP_Error(
			'cannot_delete_last_image',
			'You must keep at least one image on a product.',
			array( 'status' => 400 )
		);
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_parent !== $product_id ) {
		return new WP_Error( 'image_not_found', 'Image not found.', array( 'status' => 404 ) );
	}

	// Remove from ordered list.
	$remaining = array_values( array_filter( $ordered_ids, static function ( int $id ) use ( $attachment_id ): bool {
		return $id !== $attachment_id;
	} ) );

	// Update featured + gallery.
	$featured_id = array_shift( $remaining );
	set_post_thumbnail( $product_id, $featured_id );

	if ( ! empty( $remaining ) ) {
		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $remaining ) );
	} else {
		delete_post_meta( $product_id, '_product_image_gallery' );
	}

	// Permanently delete the attachment.
	$deleted = wp_delete_attachment( $attachment_id, true );
	if ( ! $deleted ) {
		return new WP_Error( 'delete_failed', 'Failed to delete attachment.', array( 'status' => 500 ) );
	}

	if ( function_exists( 'extrachill_shop_ability_build_product_response' ) ) {
		return extrachill_shop_ability_build_product_response( $product_id );
	}

	return array( 'deleted' => true, 'product_id' => $product_id );
}

// ─── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Get ordered product image attachment IDs.
 *
 * @param int $product_id Product ID.
 * @return int[]
 */
function extrachill_shop_ability_delete_image_get_ordered_ids( int $product_id ): array {
	$featured_id = (int) get_post_thumbnail_id( $product_id );
	$ids         = array();

	if ( $featured_id ) {
		$ids[] = $featured_id;
	}

	$gallery_raw = (string) get_post_meta( $product_id, '_product_image_gallery', true );
	if ( $gallery_raw ) {
		$gallery_ids = array_values( array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) );
		$ids         = array_merge( $ids, $gallery_ids );
	}

	return array_values( array_unique( $ids ) );
}
