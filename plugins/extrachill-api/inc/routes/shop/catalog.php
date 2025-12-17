<?php
/**
 * Shop Catalog REST API Endpoint
 *
 * Public endpoint for browsing shop products with filtering and sorting.
 * Returns JSON data for both server-side rendering and app consumption.
 *
 * Route: GET /wp-json/extrachill/v1/shop/catalog
 *
 * @package ExtraChillAPI
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_catalog_route' );

/**
 * Register shop catalog REST route.
 */
function extrachill_api_register_shop_catalog_route() {
	register_rest_route(
		'extrachill/v1',
		'/shop/catalog',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_shop_catalog_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'artist'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'sort'     => array(
					'type'              => 'string',
					'default'           => 'recent',
					'enum'              => array( 'recent', 'oldest', 'price-asc', 'price-desc', 'random', 'popular' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'page'     => array(
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'type'              => 'integer',
					'default'           => 12,
					'minimum'           => 1,
					'maximum'           => 100,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handle GET /shop/catalog request.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_shop_catalog_handler( WP_REST_Request $request ) {
	$shop_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;

	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Shop site is not configured.',
			array( 'status' => 500 )
		);
	}

	$artist   = $request->get_param( 'artist' );
	$sort     = $request->get_param( 'sort' );
	$page     = $request->get_param( 'page' );
	$per_page = $request->get_param( 'per_page' );

	switch_to_blog( $shop_blog_id );
	try {
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);

		if ( $artist ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'artist',
					'field'    => 'slug',
					'terms'    => $artist,
				),
			);
		}

		switch ( $sort ) {
			case 'oldest':
				$query_args['orderby'] = 'date';
				$query_args['order']   = 'ASC';
				break;
			case 'price-asc':
				$query_args['meta_key'] = '_price';
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'ASC';
				break;
			case 'price-desc':
				$query_args['meta_key'] = '_price';
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'DESC';
				break;
			case 'random':
				$query_args['orderby'] = 'rand';
				break;
			case 'popular':
				$query_args['meta_key'] = 'ec_post_views';
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'DESC';
				break;
			case 'recent':
			default:
				$query_args['orderby'] = 'date';
				$query_args['order']   = 'DESC';
				break;
		}

		$query    = new WP_Query( $query_args );
		$products = array();

		foreach ( $query->posts as $product_post ) {
			$products[] = extrachill_api_shop_catalog_build_product( $product_post->ID );
		}

		$artists = extrachill_api_shop_catalog_get_artists();

		$response = array(
			'products'     => $products,
			'total'        => (int) $query->found_posts,
			'total_pages'  => (int) $query->max_num_pages,
			'current_page' => $page,
			'artists'      => $artists,
		);

		return rest_ensure_response( $response );
	} finally {
		restore_current_blog();
	}
}

/**
 * Build product data for catalog response.
 *
 * Must be called within shop blog context.
 *
 * @param int $product_id Product post ID.
 * @return array Product data.
 */
function extrachill_api_shop_catalog_build_product( $product_id ) {
	$product_post  = get_post( $product_id );
	$regular_price = get_post_meta( $product_id, '_regular_price', true );
	$sale_price    = get_post_meta( $product_id, '_sale_price', true );
	$price         = get_post_meta( $product_id, '_price', true );
	$price_html    = '';
	if ( $sale_price && (float) $sale_price < (float) $regular_price ) {
		$price_html = sprintf(
			'<del>$%s</del> <ins>$%s</ins>',
			number_format( (float) $regular_price, 2 ),
			number_format( (float) $sale_price, 2 )
		);
	} elseif ( $price ) {
		$price_html = sprintf( '$%s', number_format( (float) $price, 2 ) );
	}

	$image_id     = get_post_thumbnail_id( $product_id );
	$image_url    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
	$image_alt    = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';
	$artist_data  = null;
	$artist_terms = get_the_terms( $product_id, 'artist' );
	if ( $artist_terms && ! is_wp_error( $artist_terms ) ) {
		$artist_term = $artist_terms[0];
		$artist_data = array(
			'slug' => $artist_term->slug,
			'name' => $artist_term->name,
			'url'  => get_term_link( $artist_term ),
		);
	}

	$rating_html = '';
	if ( function_exists( 'wc_get_product' ) ) {
		$wc_product = wc_get_product( $product_id );
		if ( $wc_product && $wc_product->get_average_rating() > 0 ) {
			$rating_html = wc_get_rating_html( $wc_product->get_average_rating(), $wc_product->get_rating_count() );
		}
	}

	return array(
		'id'          => $product_id,
		'name'        => $product_post->post_title,
		'permalink'   => get_permalink( $product_id ),
		'price'       => $price,
		'price_html'  => $price_html,
		'image'       => array(
			'url' => $image_url,
			'alt' => $image_alt ? $image_alt : $product_post->post_title,
		),
		'artist'      => $artist_data,
		'rating_html' => $rating_html,
	);
}

/**
 * Get all artists that have published products.
 *
 * Must be called within shop blog context.
 *
 * @return array Array of artist data.
 */
function extrachill_api_shop_catalog_get_artists() {
	if ( ! taxonomy_exists( 'artist' ) ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'artist',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	$artists = array();
	foreach ( $terms as $term ) {
		$artists[] = array(
			'slug'  => $term->slug,
			'name'  => $term->name,
			'count' => $term->count,
		);
	}

	return $artists;
}
