<?php
/**
 * Shop Products REST API Endpoints
 *
 * CRUD operations for artist products stored on the shop site.
 * Route affinity middleware ensures this runs on the shop site.
 * Products are linked to artist profiles via `_artist_profile_id` post meta.
 *
 * This file is a thin REST wrapper: every handler delegates the product
 * business logic to extrachill-shop abilities (extrachill/shop-*). The
 * abilities own all writes (wp_insert_post, meta, variations, attribute
 * taxonomy, status/Stripe validation). Per the platform rule, no
 * wp_insert_post / update_post_meta / $wpdb product writes live here.
 *
 * The ownership helpers (extrachill_api_shop_user_has_artists /
 * ..._get_user_artist_ids / ..._user_can_manage_artist) remain here
 * because the other shop REST routes (orders, shipping) and several
 * shop abilities still reference them. They are thin pass-throughs to
 * the network ec_* functions.
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
	register_rest_route(
		'extrachill/v1',
		'/shop/products',
		array(
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
		)
	);

	// Item routes: get, update, delete
	register_rest_route(
		'extrachill/v1',
		'/shop/products/(?P<id>\d+)',
		array(
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
		)
	);
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
 * Shared by the shop product, order, and shipping REST routes.
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
 * Shared by the shop product, order, and shipping REST routes.
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
 * Shared by the shop product, order, and shipping REST routes.
 *
 * @param int      $artist_id Artist profile ID.
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
		'artist_id'      => array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && (int) $value > 0;
			},
		),
		'name'           => array(
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'price'          => array(
			'required'          => true,
			'type'              => 'number',
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && (float) $value > 0;
			},
		),
		'sale_price'     => array(
			'required'          => false,
			'type'              => 'number',
			'validate_callback' => function ( $value ) {
				return $value === null || is_numeric( $value );
			},
		),
		'description'    => array(
			'required' => false,
			'type'     => 'string',
		),
		'manage_stock'   => array(
			'required'          => false,
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		),
		'stock_quantity' => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && (int) $value >= 0;
			},
		),
		'image_id'       => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function ( $value ) {
				return is_numeric( $value ) && (int) $value >= 0;
			},
		),
		'gallery_ids'    => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => function ( $value ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map( 'absint', $value );
			},
		),
		'sizes'          => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array(
				'type'       => 'object',
				'properties' => array(
					'name'  => array( 'type' => 'string' ),
					'stock' => array( 'type' => 'integer' ),
				),
			),
			'sanitize_callback' => function ( $value ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map(
					function ( $item ) {
						return array(
							'name'  => sanitize_text_field( $item['name'] ?? '' ),
							'stock' => absint( $item['stock'] ?? 0 ),
						);
					},
					$value
				);
			},
		),
		'ships_free'     => array(
			'required'          => false,
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		),
	);
}

/**
 * REST arg schema for PUT /shop/products/{id}.
 */
function extrachill_api_shop_products_update_args() {
	return array(
		'artist_id'      => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => function ( $value ) {
				return $value === null || ( is_numeric( $value ) && (int) $value >= 0 );
			},
		),
		'status'         => array(
			'required'          => false,
			'type'              => 'string',
			'enum'              => array( 'draft', 'publish' ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		),
		'image_ids'      => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => function ( $value ) {
				if ( $value === null ) {
					return null;
				}
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_values( array_filter( array_map( 'absint', $value ) ) );
			},
		),
		'name'           => array(
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		),
		'price'          => array(
			'required'          => false,
			'type'              => 'number',
			'validate_callback' => function ( $value ) {
				return $value === null || is_numeric( $value );
			},
		),
		'sale_price'     => array(
			'required'          => false,
			'type'              => 'number',
			'validate_callback' => function ( $value ) {
				return $value === null || is_numeric( $value );
			},
		),
		'description'    => array(
			'required' => false,
			'type'     => 'string',
		),
		'manage_stock'   => array(
			'required'          => false,
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
		),
		'stock_quantity' => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => function ( $value ) {
				return is_numeric( $value ) ? (int) $value : null;
			},
			'validate_callback' => function ( $value ) {
				return $value === null || ( is_numeric( $value ) && (int) $value >= 0 );
			},
		),
		'image_id'       => array(
			'required'          => false,
			'type'              => 'integer',
			'sanitize_callback' => function ( $value ) {
				return is_numeric( $value ) ? absint( $value ) : null;
			},
		),
		'gallery_ids'    => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => function ( $value ) {
				if ( $value === null ) {
					return null;
				}
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map( 'absint', $value );
			},
		),
		'sizes'          => array(
			'required'          => false,
			'type'              => 'array',
			'items'             => array(
				'type'       => 'object',
				'properties' => array(
					'name'  => array( 'type' => 'string' ),
					'stock' => array( 'type' => 'integer' ),
				),
			),
			'sanitize_callback' => function ( $value ) {
				if ( $value === null ) {
					return null;
				}
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_map(
					function ( $item ) {
						return array(
							'name'  => sanitize_text_field( $item['name'] ?? '' ),
							'stock' => absint( $item['stock'] ?? 0 ),
						);
					},
					$value
				);
			},
		),
		'ships_free'     => array(
			'required'          => false,
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
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

	$product_id = absint( $request->get_param( 'id' ) );

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
}

/**
 * Resolve a shop product ability, returning a 500 error if extrachill-shop is unavailable.
 *
 * @param string $ability_name Fully-qualified ability name.
 * @return WP_Ability|WP_Error
 */
function extrachill_api_shop_products_get_ability( $ability_name ) {
	$ability = wp_get_ability( $ability_name );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}
	return $ability;
}

/**
 * Handle GET /shop/products - List products.
 *
 * Wraps the extrachill/shop-list-products ability from extrachill-shop.
 */
function extrachill_api_shop_products_list_handler( WP_REST_Request $request ) {
	$ability = extrachill_api_shop_products_get_ability( 'extrachill/shop-list-products' );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle POST /shop/products - Create product.
 *
 * Wraps the extrachill/shop-create-product ability from extrachill-shop.
 */
function extrachill_api_shop_products_create_handler( WP_REST_Request $request ) {
	$ability = extrachill_api_shop_products_get_ability( 'extrachill/shop-create-product' );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}

	$result = $ability->execute(
		array(
			'artist_id'      => $request->get_param( 'artist_id' ),
			'name'           => $request->get_param( 'name' ),
			'price'          => $request->get_param( 'price' ),
			'sale_price'     => $request->get_param( 'sale_price' ),
			'description'    => $request->get_param( 'description' ),
			'manage_stock'   => $request->get_param( 'manage_stock' ),
			'stock_quantity' => $request->get_param( 'stock_quantity' ),
			'image_id'       => $request->get_param( 'image_id' ),
			'gallery_ids'    => $request->get_param( 'gallery_ids' ),
			'sizes'          => $request->get_param( 'sizes' ),
			'ships_free'     => $request->get_param( 'ships_free' ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle GET /shop/products/{id} - Get single product.
 *
 * Wraps the extrachill/shop-get-product ability from extrachill-shop.
 */
function extrachill_api_shop_products_get_handler( WP_REST_Request $request ) {
	$ability = extrachill_api_shop_products_get_ability( 'extrachill/shop-get-product' );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}

	$result = $ability->execute( array( 'id' => absint( $request->get_param( 'id' ) ) ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle PUT /shop/products/{id} - Update product.
 *
 * Wraps the extrachill/shop-update-product ability from extrachill-shop.
 * Only forwards params that were actually supplied so the ability applies
 * partial updates (a missing param leaves that field untouched).
 */
function extrachill_api_shop_products_update_handler( WP_REST_Request $request ) {
	$ability = extrachill_api_shop_products_get_ability( 'extrachill/shop-update-product' );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}

	$input  = array( 'id' => absint( $request->get_param( 'id' ) ) );
	$params = array(
		'artist_id',
		'status',
		'image_ids',
		'name',
		'price',
		'sale_price',
		'description',
		'manage_stock',
		'stock_quantity',
		'image_id',
		'gallery_ids',
		'sizes',
		'ships_free',
	);
	foreach ( $params as $param ) {
		$value = $request->get_param( $param );
		if ( $value !== null ) {
			$input[ $param ] = $value;
		}
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle DELETE /shop/products/{id} - Delete product (move to trash).
 *
 * Wraps the extrachill/shop-delete-product ability from extrachill-shop.
 */
function extrachill_api_shop_products_delete_handler( WP_REST_Request $request ) {
	$ability = extrachill_api_shop_products_get_ability( 'extrachill/shop-delete-product' );
	if ( is_wp_error( $ability ) ) {
		return $ability;
	}

	$result = $ability->execute( array( 'id' => absint( $request->get_param( 'id' ) ) ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
