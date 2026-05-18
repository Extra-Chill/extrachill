<?php
/**
 * Shipping Labels REST API Endpoints
 *
 * Thin REST wrappers over the extrachill-shop shipping-label abilities.
 * All side-effects (Shippo call, order writes, status transition, email
 * dispatch) live in the abilities — this layer only routes HTTP to the
 * ability and back. Route affinity middleware ensures these run on the
 * shop site.
 *
 * Routes:
 * - POST /wp-json/extrachill/v1/shop/shipping-labels             Purchase shipping label
 * - GET  /wp-json/extrachill/v1/shop/shipping-labels/{order_id}  Retrieve existing label
 *
 * @package ExtraChillAPI
 * @since 0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shipping_labels_routes' );

/**
 * Register shipping labels REST routes.
 */
function extrachill_api_register_shipping_labels_routes() {
	register_rest_route(
		'extrachill/v1',
		'/shop/shipping-labels',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_shipping_labels_create_handler',
			'permission_callback' => 'extrachill_api_shipping_labels_permission_check',
			'args'                => array(
				'order_id'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/shop/shipping-labels/(?P<order_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_shipping_labels_get_handler',
			'permission_callback' => 'extrachill_api_shipping_labels_permission_check',
			'args'                => array(
				'order_id'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Permission check for shipping labels routes.
 */
function extrachill_api_shipping_labels_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'You must be logged in.',
			array( 'status' => 401 )
		);
	}

	$artist_id = absint( $request->get_param( 'artist_id' ) );
	if ( ! $artist_id ) {
		return new WP_Error(
			'missing_artist_id',
			'Artist ID is required.',
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'extrachill_api_shop_user_can_manage_artist' ) ) {
		return new WP_Error(
			'configuration_error',
			'Shop API not available.',
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_api_shop_user_can_manage_artist( $artist_id ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Handle GET /shop/shipping-labels/{order_id} - Get existing label for order.
 *
 * Wraps the extrachill/shop-get-shipping-label ability from extrachill-shop.
 */
function extrachill_api_shipping_labels_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/shop-get-shipping-label' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'order_id'  => absint( $request->get_param( 'order_id' ) ),
			'artist_id' => absint( $request->get_param( 'artist_id' ) ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle POST /shop/shipping-labels - Purchase shipping label.
 *
 * Wraps the extrachill/shop-create-shipping-label ability from extrachill-shop.
 * The ability owns the Shippo call, order meta writes, status transition,
 * and email dispatch (routed through ec_send_email / extrachill/branded).
 */
function extrachill_api_shipping_labels_create_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/shop-create-shipping-label' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'order_id'  => absint( $request->get_param( 'order_id' ) ),
			'artist_id' => absint( $request->get_param( 'artist_id' ) ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
