<?php
/**
 * REST route: POST /wp-json/extrachill/v1/artists/{id}/subscribe
 *
 * Public endpoint for subscribing to artist updates.
 *
 * Delegates to ability:
 * - extrachill/artist-subscribe
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
 * Handles artist subscription requests via ability.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_artist_subscribe_handler( $request ) {
	$ability = wp_get_ability( 'extrachill/artist-subscribe' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'id'    => $request->get_param( 'id' ),
			'email' => $request->get_param( 'email' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
