<?php
/**
 * REST route: POST /wp-json/extrachill/v1/artists/{id}/subscribe
 *
 * Public endpoint for subscribing to artist updates.
 * Validates input and fires action hook for subscription handling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_subscribe_route' );

function extrachill_api_register_artist_subscribe_route() {
	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/subscribe', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_artist_subscribe_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'id'    => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'email' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
		),
	) );
}

/**
 * Handles artist subscription requests
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_subscribe_handler( $request ) {
	$artist_id = $request->get_param( 'id' );
	$email     = $request->get_param( 'email' );

	// Validate email format
	if ( ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			__( 'Please enter a valid email address.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	// Validate artist exists and is correct post type
	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			__( 'Invalid artist specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Fires when a subscription request is received.
	 *
	 * Handlers should return WP_Error on failure, or true/null on success.
	 *
	 * @param int    $artist_id The artist profile post ID.
	 * @param string $email     The subscriber's email address.
	 */
	$result = apply_filters( 'extrachill_artist_subscribe', null, $artist_id, $email );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( array(
		'message' => __( 'Thank you for subscribing!', 'extrachill-api' ),
	) );
}
