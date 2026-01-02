<?php
/**
 * Shipping Address REST API Endpoints
 *
 * Endpoints for artists to manage their shipping from-address.
 *
 * Routes:
 * - GET  /wp-json/extrachill/v1/shop/shipping-address?artist_id=X  Get artist shipping address
 * - PUT  /wp-json/extrachill/v1/shop/shipping-address              Update artist shipping address
 *
 * @package ExtraChillAPI
 * @since 0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shipping_address_routes' );

/**
 * Register shipping address REST routes.
 */
function extrachill_api_register_shipping_address_routes() {
	register_rest_route(
		'extrachill/v1',
		'/shop/shipping-address',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_shipping_address_get_handler',
				'permission_callback' => 'extrachill_api_shipping_address_permission_check',
				'args'                => array(
					'artist_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'extrachill_api_shipping_address_update_handler',
				'permission_callback' => 'extrachill_api_shipping_address_permission_check',
				'args'                => array(
					'artist_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'name'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'street1' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'street2' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'city'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'state'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'zip'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'country' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'US',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		)
	);
}

/**
 * Permission check for shipping address routes.
 */
function extrachill_api_shipping_address_permission_check( WP_REST_Request $request ) {
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
 * Handle GET /shop/shipping-address - Get artist shipping address.
 */
function extrachill_api_shipping_address_get_handler( WP_REST_Request $request ) {
	$artist_id = absint( $request->get_param( 'artist_id' ) );

	$address = extrachill_api_get_artist_shipping_address( $artist_id );

	return rest_ensure_response( array(
		'artist_id' => $artist_id,
		'address'   => $address,
		'is_set'    => ! empty( $address['name'] ) && ! empty( $address['street1'] ),
	) );
}

/**
 * Handle PUT /shop/shipping-address - Update artist shipping address.
 */
function extrachill_api_shipping_address_update_handler( WP_REST_Request $request ) {
	$artist_id = absint( $request->get_param( 'artist_id' ) );

	$address = array(
		'name'    => $request->get_param( 'name' ),
		'street1' => $request->get_param( 'street1' ),
		'street2' => $request->get_param( 'street2' ) ?: '',
		'city'    => $request->get_param( 'city' ),
		'state'   => strtoupper( $request->get_param( 'state' ) ),
		'zip'     => $request->get_param( 'zip' ),
		'country' => 'US',
	);

	$saved = extrachill_api_save_artist_shipping_address( $artist_id, $address );

	if ( ! $saved ) {
		return new WP_Error(
			'save_failed',
			'Failed to save shipping address.',
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array(
		'success'   => true,
		'artist_id' => $artist_id,
		'address'   => $address,
	) );
}

/**
 * Get artist shipping address from post meta.
 *
 * @param int $artist_id Artist profile ID.
 * @return array Address data.
 */
function extrachill_api_get_artist_shipping_address( $artist_id ) {
	$artist_blog_id = ec_get_blog_id( 'artist' );

	$default = array(
		'name'    => '',
		'street1' => '',
		'street2' => '',
		'city'    => '',
		'state'   => '',
		'zip'     => '',
		'country' => 'US',
	);

	switch_to_blog( $artist_blog_id );
	try {
		$address = get_post_meta( $artist_id, '_shipping_address', true );
		if ( ! is_array( $address ) ) {
			return $default;
		}
		return wp_parse_args( $address, $default );
	} finally {
		restore_current_blog();
	}
}

/**
 * Save artist shipping address to post meta.
 *
 * @param int   $artist_id Artist profile ID.
 * @param array $address   Address data.
 * @return bool True on success.
 */
function extrachill_api_save_artist_shipping_address( $artist_id, $address ) {
	$artist_blog_id = ec_get_blog_id( 'artist' );

	switch_to_blog( $artist_blog_id );
	try {
		$result = update_post_meta( $artist_id, '_shipping_address', $address );
		return false !== $result;
	} finally {
		restore_current_blog();
	}
}

/**
 * Check if artist has a shipping address configured.
 *
 * @param int $artist_id Artist profile ID.
 * @return bool True if address is configured.
 */
function extrachill_api_artist_has_shipping_address( $artist_id ) {
	$address = extrachill_api_get_artist_shipping_address( $artist_id );
	return ! empty( $address['name'] ) && ! empty( $address['street1'] ) && ! empty( $address['city'] ) && ! empty( $address['state'] ) && ! empty( $address['zip'] );
}
