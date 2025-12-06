<?php
/**
 * Shop Products REST API Endpoints
 *
 * CRUD operations for artist products in the WooCommerce marketplace.
 * Products are created on Blog ID 3 (shop.extrachill.com) and linked to
 * artist profiles on Blog ID 4 via _artist_profile_id meta.
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

	if ( ! function_exists( 'extrachill_shop_user_is_artist' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Shop plugin is not active.',
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_shop_user_is_artist() ) {
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

	$product_id = $request->get_param( 'id' );

	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'dependency_missing',
			'WooCommerce is not active.',
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

	if ( ! extrachill_shop_user_can_manage_product( $product_id ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage this product.',
			array( 'status' => 403 )
		);
	}

	return true;
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
	if ( ! function_exists( 'extrachill_shop_get_user_artist_products' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Shop plugin functions not available.',
			array( 'status' => 500 )
		);
	}

	$products = extrachill_shop_get_user_artist_products();
	$response = array();

	foreach ( $products as $product_post ) {
		$response[] = extrachill_api_shop_products_build_response( $product_post->ID );
	}

	return rest_ensure_response( $response );
}

/**
 * Handle POST /shop/products - Create product.
 */
function extrachill_api_shop_products_create_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'artist_id' );
	$name      = $request->get_param( 'name' );
	$price     = $request->get_param( 'price' );

	// Validate artist ownership
	$user_artists = extrachill_shop_get_user_artists();
	$artist_ids   = wp_list_pluck( $user_artists, 'ID' );

	if ( ! in_array( $artist_id, $artist_ids, true ) && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'invalid_artist',
			'You do not have permission to create products for this artist.',
			array( 'status' => 403 )
		);
	}

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_virtual( true );

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

	// Set initial status based on approval settings
	if ( function_exists( 'extrachill_shop_get_new_product_status' ) ) {
		$product->set_status( extrachill_shop_get_new_product_status() );
	} else {
		$product->set_status( 'publish' );
	}

	$product_id = $product->save();

	if ( ! $product_id ) {
		return new WP_Error(
			'create_failed',
			'Failed to create product.',
			array( 'status' => 500 )
		);
	}

	// Link to artist
	extrachill_shop_set_product_artist( $product_id, $artist_id );

	return rest_ensure_response( extrachill_api_shop_products_build_response( $product_id ) );
}

/**
 * Handle GET /shop/products/{id} - Get single product.
 */
function extrachill_api_shop_products_get_handler( WP_REST_Request $request ) {
	$product_id = $request->get_param( 'id' );
	return rest_ensure_response( extrachill_api_shop_products_build_response( $product_id ) );
}

/**
 * Handle PUT /shop/products/{id} - Update product.
 */
function extrachill_api_shop_products_update_handler( WP_REST_Request $request ) {
	$product_id = $request->get_param( 'id' );
	$product    = wc_get_product( $product_id );

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

	// Handle artist change (only if user owns both artists)
	$artist_id = $request->get_param( 'artist_id' );
	if ( $artist_id !== null ) {
		$user_artists = extrachill_shop_get_user_artists();
		$artist_ids   = wp_list_pluck( $user_artists, 'ID' );

		if ( in_array( $artist_id, $artist_ids, true ) || current_user_can( 'manage_options' ) ) {
			extrachill_shop_set_product_artist( $product_id, $artist_id );
		}
	}

	$product->save();

	return rest_ensure_response( extrachill_api_shop_products_build_response( $product_id ) );
}

/**
 * Handle DELETE /shop/products/{id} - Delete product (move to trash).
 */
function extrachill_api_shop_products_delete_handler( WP_REST_Request $request ) {
	$product_id = $request->get_param( 'id' );

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
}

/**
 * Build product response object.
 *
 * @param int $product_id Product ID.
 * @return array Product data.
 */
function extrachill_api_shop_products_build_response( $product_id ) {
	$product   = wc_get_product( $product_id );
	$artist_id = extrachill_shop_get_product_artist_id( $product_id );

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
		'artist_id'         => $artist_id,
		'image'             => array(
			'id'  => $image_id,
			'url' => $image_url,
		),
		'gallery'           => $gallery_urls,
	);
}
