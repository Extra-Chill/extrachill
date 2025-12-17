<?php
/**
 * Shop Products REST API Endpoints
 *
 * CRUD operations for artist products stored on the shop site.
 * All operations switch to the shop site blog context.
 * Products use the WooCommerce "product" post type + standard meta keys, but this route avoids
 * WooCommerce runtime objects so it remains predictable during REST dispatch.
 * Products are linked to artist profiles via `_artist_profile_id` post meta.
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
 * REST arg schema for POST /shop/products.
 */
function extrachill_api_shop_products_create_args() {
	return array(
		'artist_id'          => array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function( $value ) {
				return is_numeric( $value ) && (int) $value > 0;
			},
		),
		'name'               => array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'price'              => array(
			'required'          => true,
			'type'              => 'number',
			'validate_callback' => function( $value ) {
				return is_numeric( $value ) && (float) $value > 0;
			},
		),
		'sale_price'         => array(
			'required'          => false,
			'type'              => 'number',
			'validate_callback' => function( $value ) {
				return $value === null || is_numeric( $value );
			},
		),
		'description'        => array(
			'required' => false,
			'type'     => 'string',
		),
		'manage_stock'       => array(
			'required'          => false,
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		),
		'stock_quantity'     => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function( $value ) {
				return is_numeric( $value ) && (int) $value >= 0;
			},
		),
		'image_id'           => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function( $value ) {
				return is_numeric( $value ) && (int) $value >= 0;
			},
		),
		'gallery_ids'        => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => function( $value ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map( 'absint', $value );
			},
		),
		'sizes'              => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array(
				'type'       => 'object',
				'properties' => array(
					'name'  => array( 'type' => 'string' ),
					'stock' => array( 'type' => 'integer' ),
				),
			),
			'sanitize_callback' => function( $value ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map( function( $item ) {
					return array(
						'name'  => sanitize_text_field( $item['name'] ?? '' ),
						'stock' => absint( $item['stock'] ?? 0 ),
					);
				}, $value );
			},
		),
	);
}

/**
 * REST arg schema for PUT /shop/products/{id}.
 */
function extrachill_api_shop_products_update_args() {
	return array(
		'artist_id'         => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function( $value ) {
				return $value === null || ( is_numeric( $value ) && (int) $value >= 0 );
			},
		),
		'name'              => array(
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'price'             => array(
			'required'          => false,
			'type'              => 'number',
			'validate_callback' => function( $value ) {
				return $value === null || is_numeric( $value );
			},
		),
		'sale_price'        => array(
			'required'          => false,
			'type'              => 'number',
			'validate_callback' => function( $value ) {
				return $value === null || is_numeric( $value );
			},
		),
		'description'       => array(
			'required' => false,
			'type'     => 'string',
		),
		'manage_stock'      => array(
			'required'          => false,
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		),
		'stock_quantity'    => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => function( $value ) {
				return is_numeric( $value ) ? (int) $value : null;
			},
			'validate_callback' => function( $value ) {
				return $value === null || ( is_numeric( $value ) && (int) $value >= 0 );
			},
		),
		'image_id'          => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => function( $value ) {
				return is_numeric( $value ) ? absint( $value ) : null;
			},
		),
		'gallery_ids'       => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => function( $value ) {
				if ( $value === null ) {
					return null;
				}
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map( 'absint', $value );
			},
		),
		'sizes'             => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array(
				'type'       => 'object',
				'properties' => array(
					'name'  => array( 'type' => 'string' ),
					'stock' => array( 'type' => 'integer' ),
				),
			),
			'sanitize_callback' => function( $value ) {
				if ( $value === null ) {
					return null;
				}
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map( function( $item ) {
					return array(
						'name'  => sanitize_text_field( $item['name'] ?? '' ),
						'stock' => absint( $item['stock'] ?? 0 ),
					);
				}, $value );
			},
		),
	);
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

	$product_id   = absint( $request->get_param( 'id' ) );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

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

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$artist_id = absint( get_post_meta( $product_id, '_artist_profile_id', true ) );
		if ( ! $artist_id ) {
			return new WP_Error(
				'rest_forbidden',
				'You do not have permission to manage this product.',
				array( 'status' => 403 )
			);
		}

		if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
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
 * Handle GET /shop/products - List products.
 */
function extrachill_api_shop_products_list_handler( WP_REST_Request $request ) {
	$shop_blog_id = extrachill_api_shop_get_blog_id();
	$artist_ids   = extrachill_api_shop_get_user_artist_ids();

	switch_to_blog( $shop_blog_id );
	try {
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'posts_per_page' => -1,
		);


		if ( ! current_user_can( 'manage_options' ) ) {
			if ( empty( $artist_ids ) ) {
				return rest_ensure_response( array() );
			}

			$artist_ids = array_map( 'absint', $artist_ids );
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
			$product_response = extrachill_api_shop_products_build_response( $product_post->ID );
			if ( is_wp_error( $product_response ) ) {
				return $product_response;
			}
			$response[] = $product_response;
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
		$description  = $request->get_param( 'description' );
		$sale_price    = $request->get_param( 'sale_price' );
		$manage_stock      = $request->get_param( 'manage_stock' );
		$stock_quantity    = $request->get_param( 'stock_quantity' );
		$image_id          = $request->get_param( 'image_id' );
		$gallery_ids       = $request->get_param( 'gallery_ids' );

		$product_id = wp_insert_post(
			array(
				'post_type'    => 'product',
				'post_status'  => 'draft',
				'post_title'   => $name,
				'post_content' => $description ? wp_kses_post( wp_unslash( $description ) ) : '',
			),
			true
		);

		if ( is_wp_error( $product_id ) ) {
			return new WP_Error(
				'create_failed',
				'Failed to create product.',
				array( 'status' => 500 )
			);
		}

		update_post_meta( $product_id, '_artist_profile_id', $artist_id );
		update_post_meta( $product_id, '_regular_price', (string) $price );
		update_post_meta( $product_id, '_price', (string) $price );

		if ( is_numeric( $sale_price ) && (float) $sale_price > 0 && (float) $sale_price < (float) $price ) {
			update_post_meta( $product_id, '_sale_price', (string) $sale_price );
			update_post_meta( $product_id, '_price', (string) $sale_price );
		} else {
			delete_post_meta( $product_id, '_sale_price' );
		}

		update_post_meta( $product_id, '_manage_stock', $manage_stock ? 'yes' : 'no' );
		update_post_meta( $product_id, '_stock', $manage_stock ? (string) absint( $stock_quantity ) : '' );
		update_post_meta( $product_id, '_stock_status', 'instock' );

		if ( $image_id ) {
			set_post_thumbnail( $product_id, absint( $image_id ) );
		}

		if ( is_array( $gallery_ids ) && ! empty( $gallery_ids ) ) {
			$gallery_ids = array_map( 'absint', $gallery_ids );
			$gallery_ids = array_filter( $gallery_ids );
			$gallery_ids = array_slice( $gallery_ids, 0, 4 );
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		} else {
			delete_post_meta( $product_id, '_product_image_gallery' );
		}

		$sizes = $request->get_param( 'sizes' );
		if ( is_array( $sizes ) && ! empty( $sizes ) ) {
			extrachill_api_shop_setup_product_variations( $product_id, $sizes, $price, $sale_price );
		}

		extrachill_api_shop_sync_artist_taxonomy( $product_id, $artist_id );

		$response = extrachill_api_shop_products_build_response( $product_id );
		return is_wp_error( $response ) ? $response : rest_ensure_response( $response );
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
		$response = extrachill_api_shop_products_build_response( $product_id );
		return is_wp_error( $response ) ? $response : rest_ensure_response( $response );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle PUT /shop/products/{id} - Update product.
 */
function extrachill_api_shop_products_update_handler( WP_REST_Request $request ) {
	$product_id   = absint( $request->get_param( 'id' ) );
	$shop_blog_id = extrachill_api_shop_get_blog_id();

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

		$name = $request->get_param( 'name' );
		if ( $name !== null ) {
			wp_update_post(
				array(
					'ID'         => $product_id,
					'post_title' => $name,
				)
			);
		}

		$description = $request->get_param( 'description' );
		if ( $description !== null ) {
			wp_update_post(
				array(
					'ID'           => $product_id,
					'post_content' => wp_kses_post( wp_unslash( $description ) ),
				)
			);
		}

		$price = $request->get_param( 'price' );
		if ( $price !== null && is_numeric( $price ) && (float) $price > 0 ) {
			update_post_meta( $product_id, '_regular_price', (string) $price );
			update_post_meta( $product_id, '_price', (string) $price );
		}

		$sale_price = $request->get_param( 'sale_price' );
		if ( $sale_price !== null ) {
			$current_regular = (float) get_post_meta( $product_id, '_regular_price', true );
			if ( is_numeric( $sale_price ) && (float) $sale_price > 0 && (float) $sale_price < $current_regular ) {
				update_post_meta( $product_id, '_sale_price', (string) $sale_price );
				update_post_meta( $product_id, '_price', (string) $sale_price );
			} else {
				delete_post_meta( $product_id, '_sale_price' );
				update_post_meta( $product_id, '_price', (string) $current_regular );
			}
		}

		$manage_stock = $request->get_param( 'manage_stock' );
		if ( $manage_stock !== null ) {
			update_post_meta( $product_id, '_manage_stock', $manage_stock ? 'yes' : 'no' );
			if ( $manage_stock ) {
				$stock_quantity = $request->get_param( 'stock_quantity' );
				if ( $stock_quantity !== null ) {
					update_post_meta( $product_id, '_stock', (string) absint( $stock_quantity ) );
				}
			} else {
				delete_post_meta( $product_id, '_stock' );
			}
		}

		$image_id = $request->get_param( 'image_id' );
		if ( $image_id !== null ) {
			if ( $image_id ) {
				set_post_thumbnail( $product_id, absint( $image_id ) );
			} else {
				delete_post_thumbnail( $product_id );
			}
		}

		$gallery_ids = $request->get_param( 'gallery_ids' );
		if ( $gallery_ids !== null ) {
			if ( is_array( $gallery_ids ) && ! empty( $gallery_ids ) ) {
				$gallery_ids = array_map( 'absint', $gallery_ids );
				$gallery_ids = array_filter( $gallery_ids );
				$gallery_ids = array_slice( $gallery_ids, 0, 4 );
				update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
			} else {
				delete_post_meta( $product_id, '_product_image_gallery' );
			}
		}

		$artist_id = $request->get_param( 'artist_id' );
		if ( $artist_id !== null && extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
			update_post_meta( $product_id, '_artist_profile_id', $artist_id );
			extrachill_api_shop_sync_artist_taxonomy( $product_id, $artist_id );
		}

		$sizes = $request->get_param( 'sizes' );
		if ( $sizes !== null ) {
			$current_price      = get_post_meta( $product_id, '_regular_price', true );
			$current_sale_price = get_post_meta( $product_id, '_sale_price', true );
			extrachill_api_shop_setup_product_variations( $product_id, $sizes, $current_price, $current_sale_price );
		}

		$response = extrachill_api_shop_products_build_response( $product_id );
		return is_wp_error( $response ) ? $response : rest_ensure_response( $response );
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
 * @return array|WP_Error Product data.
 */
function extrachill_api_shop_products_build_response( $product_id ) {
	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error(
			'product_not_found',
			'Product not found.',
			array( 'status' => 404 )
		);
	}

	$artist_id = get_post_meta( $product_id, '_artist_profile_id', true );

	$image_id  = get_post_thumbnail_id( $product_id );
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

	$gallery_raw = (string) get_post_meta( $product_id, '_product_image_gallery', true );
	$gallery_ids = array();
	if ( $gallery_raw ) {
		$gallery_ids = array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) );
	}

	$gallery_urls = array();
	foreach ( $gallery_ids as $gid ) {
		$gallery_urls[] = array(
			'id'  => $gid,
			'url' => wp_get_attachment_image_url( $gid, 'thumbnail' ),
		);
	}

	$regular_price = get_post_meta( $product_id, '_regular_price', true );
	$sale_price    = get_post_meta( $product_id, '_sale_price', true );
	$manage_stock  = 'yes' === get_post_meta( $product_id, '_manage_stock', true );
	$stock         = get_post_meta( $product_id, '_stock', true );

	$sizes = extrachill_api_shop_get_product_sizes( $product_id );

	$stock_quantity = null;
	if ( ! empty( $sizes ) ) {
		$stock_quantity = array_reduce( $sizes, function( $sum, $size ) {
			return $sum + ( is_numeric( $size['stock'] ) ? (int) $size['stock'] : 0 );
		}, 0 );
		$manage_stock = true;
	} elseif ( $manage_stock ) {
		$stock_quantity = $stock !== '' ? (int) $stock : 0;
	}

	return array(
		'id'                => $product_id,
		'name'              => $product_post->post_title,
		'description'       => $product_post->post_content,
		'price'             => $regular_price,
		'sale_price'        => $sale_price,
		'manage_stock'      => $manage_stock,
		'stock_quantity'    => $stock_quantity,
		'status'            => get_post_status( $product_id ),
		'permalink'         => get_permalink( $product_id ),
		'artist_id'         => $artist_id ? (int) $artist_id : null,
		'image'             => array(
			'id'  => $image_id,
			'url' => $image_url,
		),
		'gallery'           => $gallery_urls,
		'sizes'             => $sizes,
	);
}

/**
 * Get product size variations with stock.
 *
 * Must be called within shop blog context.
 *
 * @param int $product_id Product ID.
 * @return array Array of sizes with stock.
 */
function extrachill_api_shop_get_product_sizes( $product_id ) {
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

		$term = get_term_by( 'slug', $size_attr, 'pa_size' );
		$size_name = $term ? $term->name : $size_attr;

		$stock = get_post_meta( $variation->ID, '_stock', true );
		$sizes[] = array(
			'name'  => $size_name,
			'stock' => $stock !== '' ? (int) $stock : 0,
		);
	}

	return $sizes;
}

/**
 * Set up product variations for sizes.
 *
 * Converts a simple product to variable if needed, creates/updates
 * the pa_size attribute and variations for each size.
 *
 * Must be called within shop blog context.
 *
 * @param int        $product_id Product ID.
 * @param array      $sizes      Array of size data: [ ['name' => 'S', 'stock' => 10], ... ]
 * @param float|null $price      Regular price to apply to variations.
 * @param float|null $sale_price Sale price to apply to variations (optional).
 */
function extrachill_api_shop_setup_product_variations( $product_id, $sizes, $price = null, $sale_price = null ) {
	if ( empty( $sizes ) ) {
		extrachill_api_shop_convert_to_simple_product( $product_id );
		return;
	}

	extrachill_api_shop_ensure_size_attribute();

	wp_set_object_terms( $product_id, 'variable', 'product_type' );

	$size_slugs = array();
	foreach ( $sizes as $size_data ) {
		$size_name = $size_data['name'];
		$term      = get_term_by( 'name', $size_name, 'pa_size' );
		if ( ! $term ) {
			$result = wp_insert_term( $size_name, 'pa_size' );
			if ( ! is_wp_error( $result ) ) {
				$term = get_term( $result['term_id'], 'pa_size' );
			}
		}
		if ( $term ) {
			$size_slugs[] = $term->slug;
		}
	}

	wp_set_object_terms( $product_id, $size_slugs, 'pa_size' );

	$product_attributes = array(
		'pa_size' => array(
			'name'         => 'pa_size',
			'value'        => '',
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 1,
			'is_taxonomy'  => 1,
		),
	);
	update_post_meta( $product_id, '_product_attributes', $product_attributes );

	$existing_variations = get_posts( array(
		'post_type'   => 'product_variation',
		'post_parent' => $product_id,
		'post_status' => array( 'publish', 'private', 'draft' ),
		'numberposts' => -1,
	) );

	$existing_by_size = array();
	foreach ( $existing_variations as $var ) {
		$size_attr = get_post_meta( $var->ID, 'attribute_pa_size', true );
		$existing_by_size[ $size_attr ] = $var->ID;
	}

	$updated_size_slugs = array();
	$menu_order         = 0;

	foreach ( $sizes as $size_data ) {
		$size_name  = $size_data['name'];
		$size_stock = absint( $size_data['stock'] );
		$term       = get_term_by( 'name', $size_name, 'pa_size' );
		if ( ! $term ) {
			continue;
		}

		$size_slug            = $term->slug;
		$updated_size_slugs[] = $size_slug;

		if ( isset( $existing_by_size[ $size_slug ] ) ) {
			$variation_id = $existing_by_size[ $size_slug ];
			wp_update_post( array(
				'ID'         => $variation_id,
				'post_status' => 'publish',
				'menu_order' => $menu_order,
			) );
		} else {
			$variation_id = wp_insert_post( array(
				'post_type'   => 'product_variation',
				'post_parent' => $product_id,
				'post_status' => 'publish',
				'post_title'  => $size_name,
				'menu_order'  => $menu_order,
			) );
			update_post_meta( $variation_id, 'attribute_pa_size', $size_slug );
		}

		if ( $price !== null ) {
			update_post_meta( $variation_id, '_regular_price', (string) $price );
			$effective_price = $price;

			if ( is_numeric( $sale_price ) && (float) $sale_price > 0 && (float) $sale_price < (float) $price ) {
				update_post_meta( $variation_id, '_sale_price', (string) $sale_price );
				$effective_price = $sale_price;
			} else {
				delete_post_meta( $variation_id, '_sale_price' );
			}

			update_post_meta( $variation_id, '_price', (string) $effective_price );
		}

		update_post_meta( $variation_id, '_manage_stock', 'yes' );
		update_post_meta( $variation_id, '_stock', (string) $size_stock );
		update_post_meta( $variation_id, '_stock_status', $size_stock > 0 ? 'instock' : 'outofstock' );

		$menu_order++;
	}

	foreach ( $existing_by_size as $size_slug => $variation_id ) {
		if ( ! in_array( $size_slug, $updated_size_slugs, true ) ) {
			wp_delete_post( $variation_id, true );
		}
	}

	delete_transient( 'wc_product_children_' . $product_id );
	delete_transient( 'wc_var_prices_' . $product_id );
}

/**
 * Convert variable product back to simple product.
 *
 * Deletes all variations and resets product type.
 *
 * @param int $product_id Product ID.
 */
function extrachill_api_shop_convert_to_simple_product( $product_id ) {
	$variations = get_posts( array(
		'post_type'   => 'product_variation',
		'post_parent' => $product_id,
		'post_status' => array( 'publish', 'private', 'draft' ),
		'numberposts' => -1,
		'fields'      => 'ids',
	) );

	foreach ( $variations as $variation_id ) {
		wp_delete_post( $variation_id, true );
	}

	wp_set_object_terms( $product_id, 'simple', 'product_type' );
	delete_post_meta( $product_id, '_product_attributes' );
	wp_delete_object_term_relationships( $product_id, 'pa_size' );

	delete_transient( 'wc_product_children_' . $product_id );
}

/**
 * Ensure pa_size attribute taxonomy exists.
 */
function extrachill_api_shop_ensure_size_attribute() {
	if ( taxonomy_exists( 'pa_size' ) ) {
		return;
	}

	$attribute_data = array(
		'attribute_name'    => 'size',
		'attribute_label'   => 'Size',
		'attribute_type'    => 'select',
		'attribute_orderby' => 'menu_order',
		'attribute_public'  => 0,
	);

	global $wpdb;

	$wpdb->insert(
		$wpdb->prefix . 'woocommerce_attribute_taxonomies',
		$attribute_data,
		array( '%s', '%s', '%s', '%s', '%d' )
	);

	delete_transient( 'wc_attribute_taxonomies' );

	register_taxonomy(
		'pa_size',
		'product',
		array(
			'label'        => 'Size',
			'public'       => true,
			'hierarchical' => false,
			'show_ui'      => true,
			'query_var'    => true,
			'rewrite'      => array( 'slug' => 'size' ),
		)
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
