<?php
/**
 * Shop Product Images REST API Endpoints
 *
 * Multi-image upload and management for WooCommerce products.
 * Route affinity middleware ensures this runs on the shop site.
 * Supports up to 5 images per product. First image is
 * featured image; remaining images are gallery.
 *
 * Routes:
 * - POST   /wp-json/extrachill/v1/shop/products/{id}/images
 * - DELETE /wp-json/extrachill/v1/shop/products/{id}/images/{attachment_id}
 *
 * @package ExtraChillAPI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_product_images_routes' );

/**
 * Register shop product images REST routes.
 */
function extrachill_api_register_shop_product_images_routes() {
	register_rest_route(
		'extrachill/v1',
		'/shop/products/(?P<id>\\d+)/images',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_shop_product_images_upload_handler',
				'permission_callback' => 'extrachill_api_shop_products_item_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/shop/products/(?P<id>\\d+)/images/(?P<attachment_id>\\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'extrachill_api_shop_product_images_delete_handler',
				'permission_callback' => 'extrachill_api_shop_products_item_permission_check',
				'args'                => array(
					'id'            => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);
}

/**
 * Get ordered product image attachment IDs.
 *
 * Must be called within shop blog context.
 *
 * @param int $product_id Product ID.
 * @return int[]
 */
function extrachill_api_shop_product_images_get_ordered_ids( $product_id ) {
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

/**
 * Persist product image order from a list of attachment IDs.
 *
 * Must be called within shop blog context.
 *
 * @param int   $product_id Product ID.
 * @param int[] $ordered_ids Ordered attachment IDs.
 * @return true|WP_Error
 */
function extrachill_api_shop_product_images_set_ordered_ids( $product_id, $ordered_ids ) {
	if ( ! function_exists( 'extrachill_api_shop_products_set_image_order' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Products route is not loaded.',
			array( 'status' => 500 )
		);
	}

	return extrachill_api_shop_products_set_image_order( $product_id, $ordered_ids );
}

/**
 * Handle POST /shop/products/{id}/images.
 *
 * Wraps the extrachill/shop-upload-product-image ability from extrachill-shop.
 */
function extrachill_api_shop_product_images_upload_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/shop-upload-product-image' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'id'      => absint( $request->get_param( 'id' ) ),
			'request' => $request,
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle DELETE /shop/products/{id}/images/{attachment_id}.
 */
function extrachill_api_shop_product_images_delete_handler( WP_REST_Request $request ) {
	$product_id    = absint( $request->get_param( 'id' ) );
	$attachment_id = absint( $request->get_param( 'attachment_id' ) );

	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error(
			'product_not_found',
			'Product not found.',
			array( 'status' => 404 )
		);
	}

	$ordered_ids = extrachill_api_shop_product_images_get_ordered_ids( $product_id );
	if ( ! in_array( $attachment_id, $ordered_ids, true ) ) {
		return new WP_Error(
			'image_not_found',
			'Image not found.',
			array( 'status' => 404 )
		);
	}

	if ( count( $ordered_ids ) <= 1 ) {
		return new WP_Error(
			'cannot_delete_last_image',
			'You must keep at least one image on a product.',
			array( 'status' => 400 )
		);
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_parent !== (int) $product_id ) {
		return new WP_Error(
			'image_not_found',
			'Image not found.',
			array( 'status' => 404 )
		);
	}

	$remaining = array_values( array_filter( $ordered_ids, function( $id ) use ( $attachment_id ) {
		return (int) $id !== (int) $attachment_id;
	} ) );

	$set_order = extrachill_api_shop_product_images_set_ordered_ids( $product_id, $remaining );
	if ( is_wp_error( $set_order ) ) {
		return $set_order;
	}

	$deleted = wp_delete_attachment( $attachment_id, true );
	if ( ! $deleted ) {
		return new WP_Error(
			'delete_failed',
			'Failed to delete attachment.',
			array( 'status' => 500 )
		);
	}

	$response = function_exists( 'extrachill_api_shop_products_build_response' )
		? extrachill_api_shop_products_build_response( $product_id )
		: array( 'deleted' => true );

	return rest_ensure_response( $response );
}
