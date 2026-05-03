<?php
/**
 * Geocode Search Endpoint
 *
 * Wraps the data-machine-events/geocode-search ability behind
 * extrachill/v1/events/geocode. Returns Nominatim results for
 * address autocomplete UIs.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_geocode_route' );

/**
 * Register the geocode search endpoint.
 */
function extrachill_api_register_events_geocode_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/geocode',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_geocode_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'q' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Search query (address, city, or place name)',
				),
			),
		)
	);
}

/**
 * Handle geocode search request.
 *
 * Invokes the extrachill/events-geocode ability (registered in extrachill-events).
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_geocode_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/events-geocode' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array(
		'q' => $request->get_param( 'q' ),
	) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
