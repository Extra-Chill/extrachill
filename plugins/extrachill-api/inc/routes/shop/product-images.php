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
				'permission_callback' => 'extrachill_api_shop_product_images_permission_check',
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
				'permission_callback' => 'extrachill_api_shop_product_images_permission_check',
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
 * Permission: the ability's product-seller check, without the upload payload.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function extrachill_api_shop_product_images_permission_check( WP_REST_Request $request ) {
	$ability = null === $request->get_param( 'attachment_id' ) ? 'extrachill/shop-upload-product-image' : 'extrachill/shop-delete-product-image';
	return extrachill_api_shop_check_ability( $ability, array( 'id' => absint( $request->get_param( 'id' ) ) ) );
}

/**
 * Handle POST /shop/products/{id}/images.
 *
 * Wraps the extrachill/shop-upload-product-image ability from extrachill-shop.
 */
function extrachill_api_shop_product_images_upload_handler( WP_REST_Request $request ) {
	return extrachill_api_shop_run_ability(
		'extrachill/shop-upload-product-image',
		array(
			'id'      => absint( $request->get_param( 'id' ) ),
			'request' => $request,
		)
	);
}

/**
 * Handle DELETE /shop/products/{id}/images/{attachment_id}.
 *
 * Wraps the extrachill/shop-delete-product-image ability from extrachill-shop,
 * which removes the image from the ordered list (re-deriving featured/gallery),
 * permanently deletes the attachment, and returns the rebuilt product response.
 */
function extrachill_api_shop_product_images_delete_handler( WP_REST_Request $request ) {
	return extrachill_api_shop_run_ability(
		'extrachill/shop-delete-product-image',
		array(
			'id'            => absint( $request->get_param( 'id' ) ),
			'attachment_id' => absint( $request->get_param( 'attachment_id' ) ),
		)
	);
}
