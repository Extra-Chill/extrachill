<?php
/**
 * Shop Product Images REST API Endpoints
 *
 * Multi-image upload and management for WooCommerce products stored
 * on the shop site. Supports up to 5 images per product. First image is
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
 */
function extrachill_api_shop_product_images_upload_handler( WP_REST_Request $request ) {
	$product_id   = absint( $request->get_param( 'id' ) );
	$shop_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;

	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Shop site is not configured.',
			array( 'status' => 500 )
		);
	}

	$files = $request->get_file_params();
	if ( empty( $files['files'] ) && isset( $_FILES['files'] ) ) {
		$files['files'] = $_FILES['files'];
	}

	if ( empty( $files['files'] ) || empty( $files['files']['name'] ) ) {
		return new WP_Error(
			'no_files',
			'No files uploaded.',
			array( 'status' => 400 )
		);
	}

	$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	$max_size      = 5 * 1024 * 1024;

	switch_to_blog( $shop_blog_id );
	try {
		$product_post = get_post( $product_id );
		if ( ! $product_post || 'product' !== $product_post->post_type ) {
			return new WP_Error(
				'product_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		$current_ids = extrachill_api_shop_product_images_get_ordered_ids( $product_id );
		if ( count( $current_ids ) >= 5 ) {
			return new WP_Error(
				'image_limit_reached',
				'you already have five images. please delete one before uploading another',
				array( 'status' => 400 )
			);
		}

		$file_count = is_array( $files['files']['name'] ) ? count( $files['files']['name'] ) : 1;
		$incoming   = array();

		for ( $i = 0; $i < $file_count; $i++ ) {
			if ( count( $incoming ) + count( $current_ids ) >= 5 ) {
				break;
			}

			$uploaded_file = array(
				'name'     => is_array( $files['files']['name'] ) ? $files['files']['name'][ $i ] : $files['files']['name'],
				'type'     => is_array( $files['files']['type'] ) ? $files['files']['type'][ $i ] : $files['files']['type'],
				'tmp_name' => is_array( $files['files']['tmp_name'] ) ? $files['files']['tmp_name'][ $i ] : $files['files']['tmp_name'],
				'error'    => is_array( $files['files']['error'] ) ? $files['files']['error'][ $i ] : $files['files']['error'],
				'size'     => is_array( $files['files']['size'] ) ? $files['files']['size'][ $i ] : $files['files']['size'],
			);

			if ( empty( $uploaded_file['name'] ) ) {
				continue;
			}

			$file_type = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'] );
			if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
				return new WP_Error(
					'invalid_file_type',
					'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.',
					array( 'status' => 400 )
				);
			}

			if ( $uploaded_file['size'] > $max_size ) {
				return new WP_Error(
					'file_too_large',
					'File size exceeds the 5MB limit.',
					array( 'status' => 400 )
				);
			}

			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$upload_result = wp_handle_upload( $uploaded_file, array( 'test_form' => false ) );
			if ( ! $upload_result || isset( $upload_result['error'] ) ) {
				return new WP_Error(
					'upload_failed',
					isset( $upload_result['error'] ) ? $upload_result['error'] : 'Upload failed.',
					array( 'status' => 500 )
				);
			}

			$attachment = array(
				'guid'           => $upload_result['url'],
				'post_mime_type' => $upload_result['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $upload_result['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload_result['file'], $product_id );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload_result['file'] );
			wp_update_attachment_metadata( $attachment_id, $attach_data );

			$incoming[] = (int) $attachment_id;
		}

		if ( empty( $incoming ) ) {
			return new WP_Error(
				'no_files',
				'No files uploaded.',
				array( 'status' => 400 )
			);
		}

		$new_order = array_merge( $current_ids, $incoming );
		$set_order = extrachill_api_shop_product_images_set_ordered_ids( $product_id, $new_order );
		if ( is_wp_error( $set_order ) ) {
			return $set_order;
		}

		$response = function_exists( 'extrachill_api_shop_products_build_response' )
			? extrachill_api_shop_products_build_response( $product_id )
			: array( 'product_id' => $product_id );

		return rest_ensure_response( $response );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle DELETE /shop/products/{id}/images/{attachment_id}.
 */
function extrachill_api_shop_product_images_delete_handler( WP_REST_Request $request ) {
	$product_id    = absint( $request->get_param( 'id' ) );
	$attachment_id = absint( $request->get_param( 'attachment_id' ) );
	$shop_blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;

	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Shop site is not configured.',
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $shop_blog_id );
	try {
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
	} finally {
		restore_current_blog();
	}
}
