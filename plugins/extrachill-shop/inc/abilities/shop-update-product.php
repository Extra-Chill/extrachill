<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-update-product
 *
 * Update an existing WooCommerce product.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_update_product_ability' );

/**
 * Register the shop-update-product ability.
 */
function extrachill_shop_register_update_product_ability(): void {

	wp_register_ability(
		'extrachill/shop-update-product',
		array(
			'label'       => __( 'Update Shop Product', 'extrachill-shop' ),
			'description' => __( 'Update an existing WooCommerce product — name, pricing, stock, status, images, sizes, and shipping.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array(
						'type'        => 'integer',
						'description' => 'Product ID.',
					),
					'artist_id'      => array(
						'type'        => 'integer',
						'description' => 'Reassign to a different artist profile ID.',
					),
					'name'           => array(
						'type'        => 'string',
						'description' => 'Product name.',
					),
					'price'          => array(
						'type'        => 'number',
						'description' => 'Regular price.',
					),
					'sale_price'     => array(
						'type'        => 'number',
						'description' => 'Sale price (set to 0 or null to clear).',
					),
					'description'    => array(
						'type'        => 'string',
						'description' => 'Product description.',
					),
					'manage_stock'   => array(
						'type'        => 'boolean',
						'description' => 'Whether to manage stock.',
					),
					'stock_quantity' => array(
						'type'        => 'integer',
						'description' => 'Stock quantity.',
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'publish' ),
						'description' => 'Product status.',
					),
					'image_id'       => array(
						'type'        => 'integer',
						'description' => 'Featured image attachment ID (0 to clear).',
					),
					'image_ids'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Reorder images — first becomes featured, rest become gallery.',
					),
					'gallery_ids'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Gallery image attachment IDs.',
					),
					'sizes'          => array(
						'type'        => 'array',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'name'  => array( 'type' => 'string' ),
								'stock' => array( 'type' => 'integer' ),
							),
						),
						'description' => 'Size variations with stock (empty array converts to simple product).',
					),
					'ships_free'     => array(
						'type'        => 'boolean',
						'description' => 'Whether the product ships free.',
					),
				),
				'required' => array( 'id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'name'   => array( 'type' => 'string' ),
					'price'  => array( 'type' => 'string' ),
					'status' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_update_product',
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
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Update a product.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_update_product( array $input ): array|WP_Error {
	$product_id = (int) ( $input['id'] ?? 0 );

	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
	}

	// Name.
	$name = $input['name'] ?? null;
	if ( $name !== null ) {
		wp_update_post( array(
			'ID'         => $product_id,
			'post_title' => sanitize_text_field( (string) $name ),
		) );
	}

	// Description.
	$description = $input['description'] ?? null;
	if ( $description !== null ) {
		wp_update_post( array(
			'ID'           => $product_id,
			'post_content' => wp_kses_post( wp_unslash( (string) $description ) ),
		) );
	}

	// Price.
	$price = $input['price'] ?? null;
	if ( $price !== null && is_numeric( $price ) && (float) $price > 0 ) {
		update_post_meta( $product_id, '_regular_price', (string) $price );
		update_post_meta( $product_id, '_price', (string) $price );
	}

	// Image reorder (image_ids).
	$image_ids = $input['image_ids'] ?? null;
	if ( $image_ids !== null ) {
		$reorder_result = extrachill_shop_product_set_image_order( $product_id, array_map( 'absint', (array) $image_ids ) );
		if ( is_wp_error( $reorder_result ) ) {
			return $reorder_result;
		}
	}

	// Sale price.
	$sale_price = $input['sale_price'] ?? null;
	if ( $sale_price !== null ) {
		$current_regular = (float) get_post_meta( $product_id, '_regular_price', true );
		if ( is_numeric( $sale_price ) && (float) $sale_price > 0 && (float) $sale_price < $current_regular ) {
			update_post_meta( $product_id, '_sale_price', (string) $sale_price );
			update_post_meta( $product_id, '_price', (string) $sale_price );
		} else {
			delete_post_meta( $product_id, '_sale_price' );
			update_post_meta( $product_id, '_price', (string) $current_regular );
		}
	}

	// Stock management.
	$manage_stock = $input['manage_stock'] ?? null;
	if ( $manage_stock !== null ) {
		update_post_meta( $product_id, '_manage_stock', $manage_stock ? 'yes' : 'no' );
		if ( $manage_stock ) {
			$stock_quantity = $input['stock_quantity'] ?? null;
			if ( $stock_quantity !== null ) {
				update_post_meta( $product_id, '_stock', (string) absint( (int) $stock_quantity ) );
			}
		} else {
			delete_post_meta( $product_id, '_stock' );
		}
	}

	// Featured image.
	$image_id = $input['image_id'] ?? null;
	if ( $image_id !== null ) {
		if ( (int) $image_id > 0 ) {
			set_post_thumbnail( $product_id, absint( (int) $image_id ) );
		} else {
			delete_post_thumbnail( $product_id );
		}
	}

	// Gallery.
	$gallery_ids = $input['gallery_ids'] ?? null;
	if ( $gallery_ids !== null ) {
		if ( is_array( $gallery_ids ) && ! empty( $gallery_ids ) ) {
			$gallery_ids = array_map( 'absint', $gallery_ids );
			$gallery_ids = array_filter( $gallery_ids );
			$gallery_ids = array_slice( $gallery_ids, 0, 4 );
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		} else {
			delete_post_meta( $product_id, '_product_image_gallery' );
		}
	}

	// Artist reassignment.
	$artist_id = $input['artist_id'] ?? null;
	if ( $artist_id !== null ) {
		$artist_id = (int) $artist_id;
		if ( extrachill_shop_user_can_manage_artist( $artist_id ) ) {
			update_post_meta( $product_id, '_artist_profile_id', $artist_id );
			extrachill_shop_sync_product_artist_taxonomy( $product_id, $artist_id );
		}
	}

	// Sizes / variations.
	$sizes = $input['sizes'] ?? null;
	if ( $sizes !== null ) {
		$current_price      = get_post_meta( $product_id, '_regular_price', true );
		$current_sale_price = get_post_meta( $product_id, '_sale_price', true );
		$variation_result   = extrachill_shop_setup_product_variations( $product_id, (array) $sizes, $current_price, $current_sale_price );
		if ( is_wp_error( $variation_result ) ) {
			return $variation_result;
		}
	}

	// Status.
	$status = $input['status'] ?? null;
	if ( $status !== null ) {
		$status_result = extrachill_shop_product_set_status( $product_id, (string) $status );
		if ( is_wp_error( $status_result ) ) {
			return $status_result;
		}
	}

	// Ships free.
	$ships_free = $input['ships_free'] ?? null;
	if ( $ships_free !== null ) {
		update_post_meta( $product_id, '_ships_free', $ships_free ? '1' : '0' );
	}

	return extrachill_shop_ability_build_product_response( $product_id );
}
