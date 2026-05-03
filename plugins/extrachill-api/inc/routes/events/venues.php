<?php
/**
 * Venues Endpoints
 *
 * Wraps data-machine-events venue abilities behind extrachill/v1/events/venues.
 * - GET /events/venues — list-venues ability (public, map data)
 * - GET /events/venues/<id> — get-venue ability (single venue detail)
 * - GET /events/venues/check-duplicate — check-duplicate-venue ability
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_venues_routes' );

/**
 * Register venue endpoints.
 */
function extrachill_api_register_events_venues_routes() {
	// List venues (public — powers the map).
	register_rest_route(
		'extrachill/v1',
		'/events/venues',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_venues_list_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'location' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Filter by location taxonomy slug',
				),
				'sw_lat'   => array(
					'required'    => false,
					'type'        => 'number',
					'description' => 'Southwest latitude bound',
				),
				'sw_lng'   => array(
					'required'    => false,
					'type'        => 'number',
					'description' => 'Southwest longitude bound',
				),
				'ne_lat'   => array(
					'required'    => false,
					'type'        => 'number',
					'description' => 'Northeast latitude bound',
				),
				'ne_lng'   => array(
					'required'    => false,
					'type'        => 'number',
					'description' => 'Northeast longitude bound',
				),
				'lat'      => array(
					'required'    => false,
					'type'        => 'number',
					'description' => 'Center latitude for proximity search',
				),
				'lng'      => array(
					'required'    => false,
					'type'        => 'number',
					'description' => 'Center longitude for proximity search',
				),
				'radius'   => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
					'description'       => 'Search radius in miles (default: 25)',
				),
			),
		)
	);

	// Single venue (public — reads term data directly, no ability permission gate).
	register_rest_route(
		'extrachill/v1',
		'/events/venues/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_venue_get_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => 'Venue term ID',
				),
			),
		)
	);

	// Check duplicate venue.
	register_rest_route(
		'extrachill/v1',
		'/events/venues/check-duplicate',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_venue_check_duplicate_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Venue name to check',
				),
				'city' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'City for more accurate matching',
				),
			),
		)
	);
}

/**
 * Handle venue list request.
 *
 * Invokes the extrachill/events-list-venues ability (registered in extrachill-events).
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_venues_list_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/events-list-venues' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$input = array();

	$params = array( 'location', 'sw_lat', 'sw_lng', 'ne_lat', 'ne_lng', 'lat', 'lng', 'radius' );
	foreach ( $params as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value ) {
			$input[ $key ] = $value;
		}
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle single venue request.
 *
 * Invokes the extrachill/events-get-venue ability (registered in extrachill-events).
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_venue_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/events-get-venue' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array(
		'id' => (int) $request->get_param( 'id' ),
	) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle check duplicate venue request.
 *
 * Invokes the extrachill/events-check-venue-duplicate ability (registered in extrachill-events).
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_venue_check_duplicate_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/events-check-venue-duplicate' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$input = array(
		'name' => $request->get_param( 'name' ),
	);

	$city = $request->get_param( 'city' );
	if ( ! empty( $city ) ) {
		$input['city'] = $city;
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
