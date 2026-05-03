<?php
/**
 * Upcoming Event Counts Endpoint
 *
 * Returns counts of upcoming events (date >= today) for taxonomy terms.
 * Route affinity middleware ensures this runs on the events site.
 *
 * The heavy SQL query result is cached in a transient (6hr TTL) and
 * pre-warmed by the cron warmer in extrachill-multisite.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_upcoming_counts_route' );

/**
 * Register the upcoming counts endpoint.
 */
function extrachill_api_register_events_upcoming_counts_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/upcoming-counts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_upcoming_counts_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'taxonomy' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'venue', 'location', 'artist', 'festival' ),
					'description'       => 'Taxonomy to query: venue, location, artist, or festival',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'slug'     => array(
					'required'          => false,
					'type'              => 'string',
					'description'       => 'Specific term slug. If provided, returns single term data.',
					'sanitize_callback' => 'sanitize_title',
				),
				'limit'    => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'description'       => 'Max terms to return for bulk queries. 0 = unlimited (default).',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handle upcoming counts request.
 *
 * Invokes the extrachill/events-upcoming-counts ability (registered in extrachill-events).
 * Route affinity middleware ensures this runs on the events site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_upcoming_counts_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/events-upcoming-counts' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
	}

	$input = array(
		'taxonomy' => $request->get_param( 'taxonomy' ),
	);

	$slug = $request->get_param( 'slug' );
	if ( ! empty( $slug ) ) {
		$input['slug'] = $slug;
	}

	$limit = (int) $request->get_param( 'limit' );
	if ( $limit > 0 ) {
		$input['limit'] = $limit;
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
