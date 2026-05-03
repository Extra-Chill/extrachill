<?php
/**
 * Calendar Endpoint
 *
 * Wraps the data-machine-events/get-calendar-page ability behind
 * extrachill/v1/events/calendar. Transforms ability output into a
 * simplified shape consumed by @extrachill/api-client.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_calendar_route' );

/**
 * Register the calendar endpoint.
 */
function extrachill_api_register_events_calendar_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/calendar',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_calendar_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'page'     => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
					'description'       => 'Page number',
				),
				'venue'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Filter by venue slug',
				),
				'promoter' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Filter by promoter slug',
				),
				'location' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Filter by location slug',
				),
				'scope'    => array(
					'required'          => false,
					'type'              => 'string',
					'enum'              => array( 'today', 'tonight', 'this-weekend', 'this-week' ),
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Time scope filter',
				),
				'lat'      => array(
					'required'          => false,
					'type'              => 'number',
					'description'       => 'Latitude for geo filtering',
				),
				'lng'      => array(
					'required'          => false,
					'type'              => 'number',
					'description'       => 'Longitude for geo filtering',
				),
				'radius'   => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
					'description'       => 'Radius for geo filtering (miles)',
				),
				'search'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Search query',
				),
				'past'     => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
					'description'       => 'Show past events',
				),
			),
		)
	);
}

/**
 * Handle calendar request.
 *
 * Invokes the extrachill/events-calendar ability (registered in extrachill-events).
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_calendar_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/events-calendar' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$input = array();

	$params = array( 'page', 'venue', 'promoter', 'location', 'scope', 'lat', 'lng', 'radius', 'search', 'past' );
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
