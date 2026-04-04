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
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_geocode_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'data-machine-events/geocode-search' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_unavailable',
			__( 'Geocode search ability is not registered.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( array(
		'query'        => $request->get_param( 'q' ),
		'countrycodes' => 'us',
	) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Transform Nominatim results to GeoSearchResult shape.
	$results = array();
	foreach ( $result['results'] ?? array() as $item ) {
		$results[] = array(
			'lat'          => (float) ( $item['lat'] ?? 0 ),
			'lon'          => (float) ( $item['lon'] ?? 0 ),
			'display_name' => $item['display_name'] ?? '',
		);
	}

	return rest_ensure_response( $results );
}
