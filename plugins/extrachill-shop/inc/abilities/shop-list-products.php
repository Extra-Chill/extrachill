<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-list-products
 *
 * List products belonging to the current user's artist profiles.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_list_products_ability' );

/**
 * Register the shop-list-products ability.
 */
function extrachill_shop_register_list_products_ability(): void {

	wp_register_ability(
		'extrachill/shop-list-products',
		array(
			'label'               => __( 'List Shop Products', 'extrachill-shop' ),
			'description'         => __( 'List products belonging to a seller (artist, venue or promoter).', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'seller'   => extrachill_shop_seller_input_schema(),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'execute_callback'    => 'extrachill_shop_ability_list_products',
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
 * List products for a seller.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_list_products( array $input ): array|WP_Error {
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	$query_args = array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'pending', 'draft' ),
		'posts_per_page' => min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) ),
		'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
		'orderby'        => 'ID',
		'order'          => 'DESC',
	);
	$query_args['meta_query'] = extrachill_shop_seller_products_meta_query( $seller ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Seller identity is product meta.

	$query    = new WP_Query( $query_args );
	$products = $query->posts;
	$response = array();

	foreach ( $products as $product_post ) {
		$product_response = extrachill_shop_ability_build_product_response( $product_post->ID );
		if ( is_wp_error( $product_response ) ) {
			return $product_response;
		}
		$response[] = $product_response;
	}

	return $response;
}

// ─── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build product response object.
 *
 * @param int $product_id Product ID.
 * @return array|WP_Error
 */
function extrachill_shop_ability_build_product_response( int $product_id ): array|WP_Error {
	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
	}

	$seller = extrachill_shop_get_product_seller( $product_id );

	$image_id  = (int) get_post_thumbnail_id( $product_id );
	$image_url = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '';

	$gallery_raw = (string) get_post_meta( $product_id, '_product_image_gallery', true );
	$gallery_ids = array();
	if ( $gallery_raw ) {
		$gallery_ids = array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) );
	}

	$gallery_urls = array();
	$images       = array();

	if ( $image_id ) {
		$images[] = array(
			'id'  => $image_id,
			'url' => (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ),
		);
	}

	foreach ( $gallery_ids as $gid ) {
		$url            = (string) wp_get_attachment_image_url( $gid, 'thumbnail' );
		$gallery_urls[] = array(
			'id'  => $gid,
			'url' => $url,
		);
		$images[]       = array(
			'id'  => $gid,
			'url' => $url,
		);
	}

	$regular_price = get_post_meta( $product_id, '_regular_price', true );
	$sale_price    = get_post_meta( $product_id, '_sale_price', true );
	$manage_stock  = 'yes' === get_post_meta( $product_id, '_manage_stock', true );
	$stock         = get_post_meta( $product_id, '_stock', true );

	$sizes = extrachill_shop_ability_get_product_sizes( $product_id );

	$stock_quantity = null;
	if ( ! empty( $sizes ) ) {
		$stock_quantity = array_reduce(
			$sizes,
			static function ( int $sum, array $size ): int {
				return $sum + ( is_numeric( $size['stock'] ) ? (int) $size['stock'] : 0 );
			},
			0
		);
		$manage_stock   = true;
	} elseif ( $manage_stock ) {
		$stock_quantity = '' !== $stock ? (int) $stock : 0;
	}

	return array(
		'id'             => $product_id,
		'name'           => $product_post->post_title,
		'description'    => $product_post->post_content,
		'price'          => $regular_price,
		'sale_price'     => $sale_price,
		'manage_stock'   => $manage_stock,
		'stock_quantity' => $stock_quantity,
		'status'         => get_post_status( $product_id ),
		'permalink'      => (string) get_permalink( $product_id ),
		'seller'         => $seller ? $seller : null,
		'image'          => array(
			'id'  => $image_id,
			'url' => $image_url,
		),
		'gallery'        => $gallery_urls,
		'images'         => $images,
		'sizes'          => $sizes,
		'ships_free'     => '1' === get_post_meta( $product_id, '_ships_free', true ),
	);
}

/**
 * Get product size variations with stock.
 *
 * @param int $product_id Product ID.
 * @return array
 */
function extrachill_shop_ability_get_product_sizes( int $product_id ): array {
	$product_type = wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $product_type ) || ! in_array( 'variable', $product_type, true ) ) {
		return array();
	}

	$variations = get_posts( array(
		'post_type'   => 'product_variation',
		'post_parent' => $product_id,
		'post_status' => array( 'publish', 'private' ),
		'numberposts' => -1,
		'orderby'     => 'menu_order',
		'order'       => 'ASC',
	) );

	$sizes = array();
	foreach ( $variations as $variation ) {
		$size_attr = get_post_meta( $variation->ID, 'attribute_pa_size', true );
		if ( ! $size_attr ) {
			continue;
		}

		$term      = get_term_by( 'slug', $size_attr, 'pa_size' );
		$size_name = $term ? $term->name : $size_attr;
		$stock     = get_post_meta( $variation->ID, '_stock', true );

		$sizes[] = array(
			'name'  => $size_name,
			'stock' => '' !== $stock ? (int) $stock : 0,
		);
	}

	return $sizes;
}
