<?php
declare(strict_types=1);
/**
 * Ability: extrachill/shop-upload-product-image
 *
 * Upload one or more images to a product.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_upload_product_image_ability' );

/**
 * Register the shop-upload-product-image ability.
 */
function extrachill_shop_register_upload_product_image_ability(): void {

	wp_register_ability(
		'extrachill/shop-upload-product-image',
		array(
			'label'       => __( 'Upload Product Image', 'extrachill-shop' ),
			'description' => __( 'Upload one or more images to a product (max 5 total). First image becomes the featured image; the rest go to the gallery.', 'extrachill-shop' ),
			'category'    => 'extrachill-shop',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => 'Product ID.',
					),
					'files' => array(
						'type'        => 'array',
						'description' => 'File upload data (handled by transport layer).',
						'items'       => array( 'type' => 'object' ),
					),
				),
				'required' => array( 'id' ),
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
			'execute_callback'    => 'extrachill_shop_ability_upload_product_image',
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
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Upload images to a product.
 *
 * This ability handles the file-upload workflow: it reads from $_FILES,
 * validates types and sizes, creates WordPress attachments, and updates
 * the product's featured image and gallery.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_upload_product_image( array $input ): array|WP_Error {
	$product_id = (int) ( $input['id'] ?? 0 );

	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by ability layer.
	$files = $_FILES;
	if ( empty( $files['files'] ) || empty( $files['files']['name'] ) ) {
		return new WP_Error( 'no_files', 'No files uploaded.', array( 'status' => 400 ) );
	}

	$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	$max_size      = 5 * 1024 * 1024;

	// Get current image order.
	$current_ids = extrachill_shop_ability_get_ordered_image_ids( $product_id );
	if ( count( $current_ids ) >= 5 ) {
		return new WP_Error(
			'image_limit_reached',
			'You already have five images. Please delete one before uploading another.',
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
			return new WP_Error( 'invalid_file_type', 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.', array( 'status' => 400 ) );
		}

		if ( $uploaded_file['size'] > $max_size ) {
			return new WP_Error( 'file_too_large', 'File size exceeds the 5MB limit.', array( 'status' => 400 ) );
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
		return new WP_Error( 'no_files', 'No files uploaded.', array( 'status' => 400 ) );
	}

	// Update image order: featured + gallery.
	$new_order = array_merge( $current_ids, $incoming );
	$new_order = array_values( array_unique( array_slice( $new_order, 0, 5 ) ) );

	$featured_id = array_shift( $new_order );
	set_post_thumbnail( $product_id, $featured_id );

	if ( ! empty( $new_order ) ) {
		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $new_order ) );
	} else {
		delete_post_meta( $product_id, '_product_image_gallery' );
	}

	if ( function_exists( 'extrachill_shop_ability_build_product_response' ) ) {
		return extrachill_shop_ability_build_product_response( $product_id );
	}

	return array( 'product_id' => $product_id );
}

// ─── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Get ordered product image attachment IDs.
 *
 * @param int $product_id Product ID.
 * @return int[]
 */
function extrachill_shop_ability_get_ordered_image_ids( int $product_id ): array {
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
