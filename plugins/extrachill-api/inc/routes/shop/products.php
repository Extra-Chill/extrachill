<?php
/**
 * Shop Products REST API Endpoints
 *
 * CRUD operations for artist products in the WooCommerce marketplace.
 * All operations internally switch to Blog ID 3 (shop.extrachill.com) for WooCommerce access.
 * Products are linked to artist profiles on Blog ID 4 via _artist_profile_id meta.
 *
 * Routes:
 * - GET    /wp-json/extrachill/v1/shop/products           List user's artist products
 * - POST   /wp-json/extrachill/v1/shop/products           Create product
 * - GET    /wp-json/extrachill/v1/shop/products/{id}      Get single product
 * - PUT    /wp-json/extrachill/v1/shop/products/{id}      Update product
 * - DELETE /wp-json/extrachill/v1/shop/products/{id}      Delete product (trash)
 *
 * @package ExtraChillAPI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_products_routes' );

/**
 * Register shop products REST routes.
 */
function extrachill_api_register_shop_products_routes() {
	// Collection routes: list and create
	register_rest_route( 'extrachill/v1', '/shop/products', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_shop_products_list_handler',
			'permission_callback' => 'extrachill_api_shop_products_permission_check',
		),
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_shop_products_create_handler',
			'permission_callback' => 'extrachill_api_shop_products_permission_check',
			'args'                => extrachill_api_shop_products_create_args(),
		),
	) );

	// Item routes: get, update, delete
	register_rest_route( 'extrachill/v1', '/shop/products/(?P<id>\d+)', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_shop_products_get_handler',
			'permission_callback' => 'extrachill_api_shop_products_item_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'extrachill_api_shop_products_update_handler',
			'permission_callback' => 'extrachill_api_shop_products_item_permission_check',
			'args'                => extrachill_api_shop_products_update_args(),
		),
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'extrachill_api_shop_products_delete_handler',
			'permission_callback' => 'extrachill_api_shop_products_item_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
	) );
}

/**
 * Get the shop blog ID.
 *
 * @return int|null Shop blog ID or null if not available.
 */
function extrachill_api_shop_get_blog_id() {
	return function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;
}

/**
 * Check if user has any artist profiles they can manage.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return bool True if user has manageable artists.
 */
function extrachill_api_shop_user_has_artists( $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	// Admins always have access
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	// Check if user has artist profiles
	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return false;
	}

	$artists = ec_get_artists_for_user( $user_id );
	return ! empty( $artists );
}

/**
 * Get user's artist profile IDs.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return array Array of artist profile IDs.
 */
function extrachill_api_shop_get_user_artist_ids( $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}

	return ec_get_artists_for_user( $user_id );
}

/**
 * Check if user can manage a specific artist.
 *
 * @param int $artist_id Artist profile ID.
 * @param int|null $user_id User ID (defaults to current user).
 * @return bool True if user can manage the artist.
 */
function extrachill_api_shop_user_can_manage_artist( $artist_id, $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) ) {
		return user_can( $user_id, 'manage_options' );
	}

	return ec_can_manage_artist( $user_id, $artist_id );
}

/**
 * Permission check for collection routes (list, create).
 */
function extrachill_api_shop_products_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be logged in.',
			array( 'status' => 401 )
		);
	}

	$shop_blog_id = extrachill_api_shop_get_blog_id();
	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Shop site is not configured.',
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_api_shop_user_has_artists() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be an artist to manage products.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Permission check for item routes (get, update, delete).
 */
function extrachill_api_shop_products_item_permission_check( WP_REST_Request $request ) {
	$base_check = extrachill_api_shop_products_permission_check( $request );
	if ( is_wp_error( $base_check ) ) {
		return $base_check;
	}

	$product_id   = $request->get_param( 'id' );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error(
				'dependency_missing',
				'WooCommerce is not active on shop site.',
				array( 'status' => 500 )
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		// Get artist ID from product meta
		$artist_id = get_post_meta( $product_id, '_artist_profile_id', true );
		if ( ! $artist_id ) {
			// Admins can manage orphaned products
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}
			return new WP_Error(
				'rest_forbidden',
				'Product has no associated artist.',
				array( 'status' => 403 )
			);
		}

		if ( ! extrachill_api_shop_user_can_manage_artist( (int) $artist_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You do not have permission to manage this product.',
				array( 'status' => 403 )
			);
		}

		return true;
	} finally {
		restore_current_blog();
	}
}

/**
 * Arguments for product creation.
 */
function extrachill_api_shop_products_create_args() {
	return array(
		'artist_id'         => array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		),
		'name'              => array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'description'       => array(
			'required'          => false,
			'type'              => 'string',
			'default'           => '',
		),
		'short_description' => array(
			'required'          => false,
			'type'              => 'string',
			'default'           => '',
		),
		'price'             => array(
			'required'          => true,
			'type'              => 'number',
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && $value > 0;
			},
		),
		'sale_price'        => array(
			'required'          => false,
			'type'              => 'number',
			'default'           => 0,
		),
		'manage_stock'      => array(
			'required'          => false,
			'type'              => 'boolean',
			'default'           => false,
		),
		'stock_quantity'    => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		),
		'image_id'          => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		),
		'gallery_ids'       => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'default'           => array(),
		),
	);
}

/**
 * Arguments for product update.
 */
function extrachill_api_shop_products_update_args() {
	$args = extrachill_api_shop_products_create_args();

	// All fields optional for update
	foreach ( $args as $key => $config ) {
		$args[ $key ]['required'] = false;
	}

	$args['id'] = array(
		'required'          => true,
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
	);

	return $args;
}

/**
 * Handle GET /shop/products - List user's products.
 */
function extrachill_api_shop_products_list_handler( WP_REST_Request $request ) {
	$shop_blog_id = extrachill_api_shop_get_blog_id();
	$user_id      = get_current_user_id();
	$artist_ids   = extrachill_api_shop_get_user_artist_ids( $user_id );

	if ( empty( $artist_ids ) && ! current_user_can( 'manage_options' ) ) {
		return rest_ensure_response( array() );
	}

	switch_to_blog( $shop_blog_id );
	try {
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'posts_per_page' => -1,
		);

		// Non-admins only see their own products
		if ( ! empty( $artist_ids ) && ! current_user_can( 'manage_options' ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => '_artist_profile_id',
					'value'   => $artist_ids,
					'compare' => 'IN',
					'type'    => 'NUMERIC',
				),
			);
		}

		$query    = new WP_Query( $query_args );
		$products = $query->posts;
		$response = array();

		foreach ( $products as $product_post ) {
			$response[] = extrachill_api_shop_products_build_response( $product_post->ID );
		}

		return rest_ensure_response( $response );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle POST /shop/products - Create product.
 */
function extrachill_api_shop_products_create_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'artist_id' );
	$name      = $request->get_param( 'name' );
	$price     = $request->get_param( 'price' );

	// Validate artist ownership
	if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
		return new WP_Error(
			'invalid_artist',
			'You do not have permission to create products for this artist.',
			array( 'status' => 403 )
		);
	}

	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error(
				'dependency_missing',
				'WooCommerce is not active on shop site.',
				array( 'status' => 500 )
			);
		}

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );

		// Optional fields
		$description = $request->get_param( 'description' );
		if ( $description ) {
			$product->set_description( wp_kses_post( wp_unslash( $description ) ) );
		}

		$short_description = $request->get_param( 'short_description' );
		if ( $short_description ) {
			$product->set_short_description( wp_kses_post( wp_unslash( $short_description ) ) );
		}

		$sale_price = $request->get_param( 'sale_price' );
		if ( $sale_price > 0 && $sale_price < $price ) {
			$product->set_sale_price( $sale_price );
		}

		$manage_stock = $request->get_param( 'manage_stock' );
		$product->set_manage_stock( $manage_stock );
		if ( $manage_stock ) {
			$product->set_stock_quantity( $request->get_param( 'stock_quantity' ) );
		}

		$image_id = $request->get_param( 'image_id' );
		if ( $image_id ) {
			$product->set_image_id( $image_id );
		}

		$gallery_ids = $request->get_param( 'gallery_ids' );
		if ( ! empty( $gallery_ids ) ) {
			$gallery_ids = array_map( 'absint', $gallery_ids );
			$gallery_ids = array_slice( $gallery_ids, 0, 4 );
			$product->set_gallery_image_ids( $gallery_ids );
		}

		// New products start as pending for review
		$product->set_status( 'pending' );

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error(
				'create_failed',
				'Failed to create product.',
				array( 'status' => 500 )
			);
		}

		// Link to artist
		update_post_meta( $product_id, '_artist_profile_id', $artist_id );

		// Sync artist taxonomy term
		extrachill_api_shop_sync_artist_taxonomy( $product_id, $artist_id );

		return rest_ensure_response( extrachill_api_shop_products_build_response( $product_id ) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle GET /shop/products/{id} - Get single product.
 */
function extrachill_api_shop_products_get_handler( WP_REST_Request $request ) {
	$product_id   = $request->get_param( 'id' );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
		return rest_ensure_response( extrachill_api_shop_products_build_response( $product_id ) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle PUT /shop/products/{id} - Update product.
 */
function extrachill_api_shop_products_update_handler( WP_REST_Request $request ) {
	$product_id   = $request->get_param( 'id' );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
		$product = wc_get_product( $product_id );

		$name = $request->get_param( 'name' );
		if ( $name ) {
			$product->set_name( $name );
		}

		$price = $request->get_param( 'price' );
		if ( $price !== null && $price > 0 ) {
			$product->set_regular_price( $price );
		}

		$description = $request->get_param( 'description' );
		if ( $description !== null ) {
			$product->set_description( wp_kses_post( wp_unslash( $description ) ) );
		}

		$short_description = $request->get_param( 'short_description' );
		if ( $short_description !== null ) {
			$product->set_short_description( wp_kses_post( wp_unslash( $short_description ) ) );
		}

		$sale_price = $request->get_param( 'sale_price' );
		if ( $sale_price !== null ) {
			$current_price = $product->get_regular_price();
			if ( $sale_price > 0 && $sale_price < $current_price ) {
				$product->set_sale_price( $sale_price );
			} else {
				$product->set_sale_price( '' );
			}
		}

		$manage_stock = $request->get_param( 'manage_stock' );
		if ( $manage_stock !== null ) {
			$product->set_manage_stock( $manage_stock );
			if ( $manage_stock ) {
				$stock_quantity = $request->get_param( 'stock_quantity' );
				if ( $stock_quantity !== null ) {
					$product->set_stock_quantity( $stock_quantity );
				}
			}
		}

		$image_id = $request->get_param( 'image_id' );
		if ( $image_id !== null ) {
			$product->set_image_id( $image_id ?: 0 );
		}

		$gallery_ids = $request->get_param( 'gallery_ids' );
		if ( $gallery_ids !== null ) {
			$gallery_ids = array_map( 'absint', $gallery_ids );
			$gallery_ids = array_slice( $gallery_ids, 0, 4 );
			$product->set_gallery_image_ids( $gallery_ids );
		}

		// Handle artist change (only if user can manage target artist)
		$artist_id = $request->get_param( 'artist_id' );
		if ( $artist_id !== null && extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
			update_post_meta( $product_id, '_artist_profile_id', $artist_id );
			extrachill_api_shop_sync_artist_taxonomy( $product_id, $artist_id );
		}

		$product->save();

		return rest_ensure_response( extrachill_api_shop_products_build_response( $product_id ) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle DELETE /shop/products/{id} - Delete product (move to trash).
 */
function extrachill_api_shop_products_delete_handler( WP_REST_Request $request ) {
	$product_id   = $request->get_param( 'id' );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
		$result = wp_trash_post( $product_id );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				'Failed to delete product.',
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( array(
			'deleted'    => true,
			'product_id' => $product_id,
		) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Build product response object.
 *
 * Must be called within shop blog context.
 *
 * @param int $product_id Product ID.
 * @return array Product data.
 */
function extrachill_api_shop_products_build_response( $product_id ) {
	$product   = wc_get_product( $product_id );
	$artist_id = get_post_meta( $product_id, '_artist_profile_id', true );

	$image_id  = $product->get_image_id();
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

	$gallery_ids  = $product->get_gallery_image_ids();
	$gallery_urls = array();
	foreach ( $gallery_ids as $gid ) {
		$gallery_urls[] = array(
			'id'  => $gid,
			'url' => wp_get_attachment_image_url( $gid, 'thumbnail' ),
		);
	}

	return array(
		'id'                => $product_id,
		'name'              => $product->get_name(),
		'description'       => $product->get_description(),
		'short_description' => $product->get_short_description(),
		'price'             => $product->get_regular_price(),
		'sale_price'        => $product->get_sale_price(),
		'manage_stock'      => $product->get_manage_stock(),
		'stock_quantity'    => $product->get_stock_quantity(),
		'status'            => get_post_status( $product_id ),
		'permalink'         => get_permalink( $product_id ),
		'artist_id'         => $artist_id ? (int) $artist_id : null,
		'image'             => array(
			'id'  => $image_id,
			'url' => $image_url,
		),
		'gallery'           => $gallery_urls,
	);
}

/**
 * Sync artist taxonomy term with product.
 *
 * Must be called within shop blog context.
 *
 * @param int $product_id Product ID.
 * @param int $artist_id Artist profile ID.
 */
function extrachill_api_shop_sync_artist_taxonomy( $product_id, $artist_id ) {
	if ( ! taxonomy_exists( 'artist' ) ) {
		return;
	}

	// Get artist slug from artist blog
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return;
	}

	// We're in shop blog context, need to get artist slug
	$current_blog = get_current_blog_id();
	switch_to_blog( $artist_blog_id );
	$artist_post = get_post( $artist_id );
	$artist_slug = $artist_post ? $artist_post->post_name : null;
	restore_current_blog();

	// Switch back to shop context if needed
	if ( get_current_blog_id() !== $current_blog ) {
		switch_to_blog( $current_blog );
	}

	if ( ! $artist_slug ) {
		return;
	}

	// Ensure term exists
	$term = get_term_by( 'slug', $artist_slug, 'artist' );
	if ( ! $term ) {
		$artist_name = $artist_post ? $artist_post->post_title : $artist_slug;
		$term_result = wp_insert_term( $artist_name, 'artist', array( 'slug' => $artist_slug ) );
		if ( is_wp_error( $term_result ) ) {
			return;
		}
		$term_id = $term_result['term_id'];
	} else {
		$term_id = $term->term_id;
	}

	wp_set_object_terms( $product_id, array( $term_id ), 'artist' );
}
