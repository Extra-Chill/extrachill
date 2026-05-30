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
 *
 * Wraps the extrachill/shop-delete-product-image ability from extrachill-shop,
 * which removes the image from the ordered list (re-deriving featured/gallery),
 * permanently deletes the attachment, and returns the rebuilt product response.
 */
function extrachill_api_shop_product_images_delete_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/shop-delete-product-image' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'id'            => absint( $request->get_param( 'id' ) ),
			'attachment_id' => absint( $request->get_param( 'attachment_id' ) ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
