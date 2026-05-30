<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-create-product
 *
 * Create a new WooCommerce product for an artist.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_create_product_ability' );

/**
 * Register the shop-create-product ability.
 */
function extrachill_shop_register_create_product_ability(): void {

	wp_register_ability(
		'extrachill/shop-create-product',
		array(
			'label'       => __( 'Create Shop Product', 'extrachill-shop' ),
			'description' => __( 'Create a new WooCommerce product for an artist profile with pricing, stock, sizes, and image associations.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'artist_id'      => array(
						'type'        => 'integer',
						'description' => 'Artist profile ID.',
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
						'description' => 'Sale price (optional, must be less than price).',
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
						'description' => 'Stock quantity (when manage_stock is true).',
					),
					'image_id'       => array(
						'type'        => 'integer',
						'description' => 'Featured image attachment ID.',
					),
					'gallery_ids'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Gallery image attachment IDs (max 4).',
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
						'description' => 'Size variations with stock.',
					),
					'ships_free'     => array(
						'type'        => 'boolean',
						'description' => 'Whether the product ships free.',
					),
				),
				'required' => array( 'artist_id', 'name', 'price' ),
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
			'execute_callback'    => 'extrachill_shop_ability_create_product',
			'permission_callback' => static function ( array $input ): bool|WP_Error {
				if ( ! is_user_logged_in() ) {
					return new WP_Error( 'rest_forbidden', 'You must be logged in.', array( 'status' => 401 ) );
				}
				if ( ! extrachill_shop_user_has_artists() ) {
					return new WP_Error( 'rest_forbidden', 'You must be an artist to manage products.', array( 'status' => 403 ) );
				}
				$artist_id = isset( $input['artist_id'] ) ? (int) $input['artist_id'] : 0;
				if ( ! $artist_id ) {
					return new WP_Error( 'missing_artist_id', 'Artist ID is required.', array( 'status' => 400 ) );
				}
				if ( ! extrachill_shop_user_can_manage_artist( $artist_id ) ) {
					return new WP_Error( 'rest_forbidden', 'You do not have permission to create products for this artist.', array( 'status' => 403 ) );
				}
				return true;
			},
			'meta' => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Create a new product for an artist.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_create_product( array $input ): array|WP_Error {
	$artist_id      = (int) ( $input['artist_id'] ?? 0 );
	$name           = (string) ( $input['name'] ?? '' );
	$price          = (float) ( $input['price'] ?? 0 );
	$sale_price     = isset( $input['sale_price'] ) ? (float) $input['sale_price'] : null;
	$description    = (string) ( $input['description'] ?? '' );
	$manage_stock   = (bool) ( $input['manage_stock'] ?? false );
	$stock_quantity = (int) ( $input['stock_quantity'] ?? 0 );
	$image_id       = (int) ( $input['image_id'] ?? 0 );
	$gallery_ids    = isset( $input['gallery_ids'] ) && is_array( $input['gallery_ids'] ) ? array_map( 'absint', $input['gallery_ids'] ) : array();
	$sizes          = isset( $input['sizes'] ) && is_array( $input['sizes'] ) ? $input['sizes'] : array();
	$ships_free     = (bool) ( $input['ships_free'] ?? false );

	$product_id = wp_insert_post(
		array(
			'post_type'    => 'product',
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field( $name ),
			'post_content' => $description ? wp_kses_post( wp_unslash( $description ) ) : '',
		),
		true
	);

	if ( is_wp_error( $product_id ) ) {
		return new WP_Error( 'create_failed', 'Failed to create product.', array( 'status' => 500 ) );
	}

	update_post_meta( $product_id, '_artist_profile_id', $artist_id );
	update_post_meta( $product_id, '_regular_price', (string) $price );
	update_post_meta( $product_id, '_price', (string) $price );

	if ( is_numeric( $sale_price ) && $sale_price > 0 && $sale_price < $price ) {
		update_post_meta( $product_id, '_sale_price', (string) $sale_price );
		update_post_meta( $product_id, '_price', (string) $sale_price );
	} else {
		delete_post_meta( $product_id, '_sale_price' );
	}

	update_post_meta( $product_id, '_manage_stock', $manage_stock ? 'yes' : 'no' );
	update_post_meta( $product_id, '_stock', $manage_stock ? (string) absint( $stock_quantity ) : '' );
	update_post_meta( $product_id, '_stock_status', 'instock' );

	if ( $image_id ) {
		set_post_thumbnail( $product_id, $image_id );
	}

	if ( ! empty( $gallery_ids ) ) {
		$gallery_ids = array_filter( $gallery_ids );
		$gallery_ids = array_slice( $gallery_ids, 0, 4 );
		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
	} else {
		delete_post_meta( $product_id, '_product_image_gallery' );
	}

	if ( ! empty( $sizes ) ) {
		$variation_result = extrachill_shop_setup_product_variations( $product_id, $sizes, $price, $sale_price );
		if ( is_wp_error( $variation_result ) ) {
			return $variation_result;
		}
	}

	extrachill_shop_sync_product_artist_taxonomy( $product_id, $artist_id );

	update_post_meta( $product_id, '_ships_free', $ships_free ? '1' : '0' );

	return extrachill_shop_ability_build_product_response( $product_id );
}
