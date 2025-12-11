<?php
/**
 * Unified Media Upload Endpoint
 *
 * POST /wp-json/extrachill/v1/media - Upload and assign image
 * DELETE /wp-json/extrachill/v1/media - Remove assigned image
 *
 * Contexts:
 * - user_avatar: User profile avatar (target_id = user_id)
 * - artist_profile: Artist featured image (target_id = artist_id)
 * - artist_header: Artist header image (target_id = artist_id)
 * - link_page_profile: Link page profile image (target_id = artist_id)
 * - link_page_background: Link page background (target_id = artist_id)
 * - content_embed: Content image embed (target_id = optional post_id)
 * - product_image: WooCommerce product image (target_id = product_id, uploads to shop site)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_media_routes' );

function extrachill_api_register_media_routes() {
	register_rest_route( 'extrachill/v1', '/media', array(
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_media_upload_handler',
			'permission_callback' => 'extrachill_api_media_permission_check',
			'args'                => array(
				'context'   => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array(
						'user_avatar',
						'artist_profile',
						'artist_header',
						'link_page_profile',
						'link_page_background',
						'content_embed',
						'product_image',
					),
					'validate_callback' => 'rest_validate_request_arg',
				),
				'target_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'extrachill_api_media_delete_handler',
			'permission_callback' => 'extrachill_api_media_permission_check',
			'args'                => array(
				'context'   => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array(
						'user_avatar',
						'artist_profile',
						'artist_header',
						'link_page_profile',
						'link_page_background',
						'product_image',
					),
					'validate_callback' => 'rest_validate_request_arg',
				),
				'target_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
	) );
}

/**
 * Permission check for media operations
 */
function extrachill_api_media_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be logged in to upload media.',
			array( 'status' => 401 )
		);
	}

	$context   = $request->get_param( 'context' );
	$target_id = $request->get_param( 'target_id' );
	$user_id   = get_current_user_id();

	// content_embed only requires login
	if ( $context === 'content_embed' ) {
		return true;
	}

	// All other contexts require target_id
	if ( ! $target_id ) {
		return new WP_Error(
			'missing_target_id',
			'target_id is required for this context.',
			array( 'status' => 400 )
		);
	}

	// User avatar: must be own profile
	if ( $context === 'user_avatar' ) {
		if ( $target_id !== $user_id ) {
			return new WP_Error(
				'rest_forbidden',
				'You can only manage your own avatar.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// Artist contexts: check ec_can_manage_artist
	$artist_contexts = array( 'artist_profile', 'artist_header', 'link_page_profile', 'link_page_background' );
	if ( in_array( $context, $artist_contexts, true ) ) {
		if ( ! function_exists( 'ec_can_manage_artist' ) ) {
			return new WP_Error(
				'dependency_missing',
				'Artist platform plugin is not active.',
				array( 'status' => 500 )
			);
		}

		if ( ! ec_can_manage_artist( $user_id, $target_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You do not have permission to manage this artist.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// Product image: verify user owns product on shop site
	if ( $context === 'product_image' ) {
		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			return new WP_Error(
				'dependency_missing',
				'Multisite plugin is not active.',
				array( 'status' => 500 )
			);
		}

		$shop_blog_id = ec_get_blog_id( 'shop' );
		$can_manage   = false;

		try {
			switch_to_blog( $shop_blog_id );

			$product = wc_get_product( $target_id );
			if ( ! $product ) {
				restore_current_blog();
				return new WP_Error(
					'product_not_found',
					'Product not found.',
					array( 'status' => 404 )
				);
			}

			// Check if product belongs to user's artist
			$product_artist_id = $product->get_meta( '_artist_id' );
			if ( $product_artist_id && function_exists( 'ec_can_manage_artist' ) ) {
				$can_manage = ec_can_manage_artist( $user_id, $product_artist_id );
			}
		} finally {
			restore_current_blog();
		}

		if ( ! $can_manage ) {
			return new WP_Error(
				'rest_forbidden',
				'You do not have permission to manage this product.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	return false;
}

/**
 * Handle media upload (POST)
 */
function extrachill_api_media_upload_handler( WP_REST_Request $request ) {
	$context   = $request->get_param( 'context' );
	$target_id = $request->get_param( 'target_id' );

	// Get uploaded file
	$files = $request->get_file_params();
	if ( empty( $files['file'] ) && isset( $_FILES['file'] ) ) {
		$files['file'] = $_FILES['file'];
	}

	if ( empty( $files['file']['name'] ) ) {
		return new WP_Error(
			'no_file',
			'No file uploaded.',
			array( 'status' => 400 )
		);
	}

	$uploaded_file = $files['file'];

	// Validate file type
	$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	$file_type     = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'] );

	if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
		return new WP_Error(
			'invalid_file_type',
			'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.',
			array( 'status' => 400 )
		);
	}

	// Validate file size (5MB max)
	$max_size = 5 * 1024 * 1024;
	if ( $uploaded_file['size'] > $max_size ) {
		return new WP_Error(
			'file_too_large',
			'File size exceeds the 5MB limit.',
			array( 'status' => 400 )
		);
	}

	// Product images upload to shop site media library
	if ( $context === 'product_image' ) {
		return extrachill_api_media_upload_product_image( $uploaded_file, $target_id );
	}

	// Handle upload for non-product contexts
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

	// Create attachment
	$attachment = array(
		'guid'           => $upload_result['url'],
		'post_mime_type' => $upload_result['type'],
		'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $upload_result['file'] ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	// Associate with post for content_embed if target_id provided
	$parent_post_id = ( $context === 'content_embed' && $target_id ) ? $target_id : 0;
	$attachment_id  = wp_insert_attachment( $attachment, $upload_result['file'], $parent_post_id );

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	// Generate attachment metadata
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload_result['file'] );
	wp_update_attachment_metadata( $attachment_id, $attach_data );

	// Assign to target based on context
	$old_attachment_id = extrachill_api_media_assign( $context, $target_id, $attachment_id );

	// Delete old attachment if replaced
	if ( $old_attachment_id && $old_attachment_id !== $attachment_id ) {
		wp_delete_attachment( $old_attachment_id, true );
	}

	return rest_ensure_response( array(
		'attachment_id' => $attachment_id,
		'url'           => wp_get_attachment_url( $attachment_id ),
		'context'       => $context,
		'target_id'     => $target_id,
	) );
}

/**
 * Handle product image upload to shop site media library
 */
function extrachill_api_media_upload_product_image( $uploaded_file, $product_id ) {
	$shop_blog_id  = ec_get_blog_id( 'shop' );
	$attachment_id = null;
	$url           = null;

	try {
		switch_to_blog( $shop_blog_id );

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

		// Set as product thumbnail (featured image)
		$old_thumbnail_id = get_post_thumbnail_id( $product_id );
		set_post_thumbnail( $product_id, $attachment_id );

		// Delete old thumbnail if replaced
		if ( $old_thumbnail_id && $old_thumbnail_id !== $attachment_id ) {
			wp_delete_attachment( $old_thumbnail_id, true );
		}

		$url = wp_get_attachment_url( $attachment_id );
	} finally {
		restore_current_blog();
	}

	return rest_ensure_response( array(
		'attachment_id' => $attachment_id,
		'url'           => $url,
		'context'       => 'product_image',
		'target_id'     => $product_id,
	) );
}

/**
 * Assign uploaded attachment to target based on context
 *
 * @return int|null Old attachment ID if replaced, null otherwise
 */
function extrachill_api_media_assign( $context, $target_id, $attachment_id ) {
	$old_attachment_id = null;

	switch ( $context ) {
		case 'user_avatar':
			$old_attachment_id = get_user_option( 'custom_avatar_id', $target_id );
			update_user_option( $target_id, 'custom_avatar_id', $attachment_id, true );
			break;

		case 'artist_profile':
			$old_attachment_id = get_post_thumbnail_id( $target_id );
			set_post_thumbnail( $target_id, $attachment_id );
			break;

		case 'artist_header':
			$old_attachment_id = get_post_meta( $target_id, '_artist_profile_header_image_id', true );
			update_post_meta( $target_id, '_artist_profile_header_image_id', $attachment_id );
			break;

		case 'link_page_profile':
			// Profile image is stored on artist (thumbnail) and link page (meta)
			$old_attachment_id = get_post_thumbnail_id( $target_id );
			set_post_thumbnail( $target_id, $attachment_id );

			// Also update link page meta
			if ( function_exists( 'ec_get_link_page_for_artist' ) ) {
				$link_page_id = ec_get_link_page_for_artist( $target_id );
				if ( $link_page_id ) {
					update_post_meta( $link_page_id, '_link_page_profile_image_id', $attachment_id );
				}
			}
			break;

		case 'link_page_background':
			if ( function_exists( 'ec_get_link_page_for_artist' ) ) {
				$link_page_id = ec_get_link_page_for_artist( $target_id );
				if ( $link_page_id ) {
					$old_attachment_id = get_post_meta( $link_page_id, '_link_page_background_image_id', true );
					update_post_meta( $link_page_id, '_link_page_background_image_id', $attachment_id );
				}
			}
			break;

		case 'content_embed':
			// No assignment needed, attachment is already created
			break;
	}

	return $old_attachment_id ? (int) $old_attachment_id : null;
}

/**
 * Handle media deletion (DELETE)
 */
function extrachill_api_media_delete_handler( WP_REST_Request $request ) {
	$context   = $request->get_param( 'context' );
	$target_id = $request->get_param( 'target_id' );

	// Product images delete from shop site
	if ( $context === 'product_image' ) {
		return extrachill_api_media_delete_product_image( $target_id );
	}

	// Get current attachment ID and clear assignment
	$attachment_id = extrachill_api_media_unassign( $context, $target_id );

	if ( ! $attachment_id ) {
		return new WP_Error(
			'no_image',
			'No image is assigned for this context.',
			array( 'status' => 404 )
		);
	}

	// Delete the attachment
	$deleted = wp_delete_attachment( $attachment_id, true );

	if ( ! $deleted ) {
		return new WP_Error(
			'delete_failed',
			'Failed to delete attachment.',
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array(
		'deleted'   => true,
		'context'   => $context,
		'target_id' => $target_id,
	) );
}

/**
 * Handle product image deletion from shop site
 */
function extrachill_api_media_delete_product_image( $product_id ) {
	$shop_blog_id = ec_get_blog_id( 'shop' );
	$deleted      = false;

	try {
		switch_to_blog( $shop_blog_id );

		$attachment_id = get_post_thumbnail_id( $product_id );

		if ( ! $attachment_id ) {
			return new WP_Error(
				'no_image',
				'No image is assigned for this product.',
				array( 'status' => 404 )
			);
		}

		delete_post_thumbnail( $product_id );
		$deleted = wp_delete_attachment( $attachment_id, true );

		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				'Failed to delete attachment.',
				array( 'status' => 500 )
			);
		}
	} finally {
		restore_current_blog();
	}

	return rest_ensure_response( array(
		'deleted'   => true,
		'context'   => 'product_image',
		'target_id' => $product_id,
	) );
}

/**
 * Unassign image from target based on context
 *
 * @return int|null Attachment ID that was unassigned, null if none
 */
function extrachill_api_media_unassign( $context, $target_id ) {
	$attachment_id = null;

	switch ( $context ) {
		case 'user_avatar':
			$attachment_id = get_user_option( 'custom_avatar_id', $target_id );
			delete_user_option( $target_id, 'custom_avatar_id', true );
			break;

		case 'artist_profile':
			$attachment_id = get_post_thumbnail_id( $target_id );
			delete_post_thumbnail( $target_id );
			break;

		case 'artist_header':
			$attachment_id = get_post_meta( $target_id, '_artist_profile_header_image_id', true );
			delete_post_meta( $target_id, '_artist_profile_header_image_id' );
			break;

		case 'link_page_profile':
			$attachment_id = get_post_thumbnail_id( $target_id );
			delete_post_thumbnail( $target_id );

			if ( function_exists( 'ec_get_link_page_for_artist' ) ) {
				$link_page_id = ec_get_link_page_for_artist( $target_id );
				if ( $link_page_id ) {
					delete_post_meta( $link_page_id, '_link_page_profile_image_id' );
				}
			}
			break;

		case 'link_page_background':
			if ( function_exists( 'ec_get_link_page_for_artist' ) ) {
				$link_page_id = ec_get_link_page_for_artist( $target_id );
				if ( $link_page_id ) {
					$attachment_id = get_post_meta( $link_page_id, '_link_page_background_image_id', true );
					delete_post_meta( $link_page_id, '_link_page_background_image_id' );
				}
			}
			break;
	}

	return $attachment_id ? (int) $attachment_id : null;
}
