<?php
/**
 * Event Filters Endpoint
 *
 * Wraps the data-machine-events/get-filter-options ability behind
 * extrachill/v1/events/filters. Returns available taxonomy terms
 * for venue and promoter filters.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_filters_route' );

/**
 * Register the filters endpoint.
 */
function extrachill_api_register_events_filters_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/filters',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_filters_handler',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Handle filters request.
 *
 * Invokes the extrachill/events-filters ability (registered in extrachill-events).
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_filters_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/events-filters' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
