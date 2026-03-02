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
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_venues_list_handler( WP_REST_Request $request ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id ) {
		return new WP_Error(
			'events_site_unavailable',
			__( 'Events site is not configured.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $events_blog_id );
	try {
		$ability = wp_get_ability( 'data-machine-events/list-venues' );
		if ( ! $ability ) {
			return new WP_Error(
				'ability_unavailable',
				__( 'List venues ability is not registered.', 'extrachill-api' ),
				array( 'status' => 500 )
			);
		}

		$input = array();

		// Geo proximity params.
		$lat = $request->get_param( 'lat' );
		$lng = $request->get_param( 'lng' );
		if ( null !== $lat && null !== $lng ) {
			$input['lat']    = (float) $lat;
			$input['lng']    = (float) $lng;
			$input['radius'] = $request->get_param( 'radius' ) ?: 25;
		}

		// Viewport bounds.
		$sw_lat = $request->get_param( 'sw_lat' );
		$sw_lng = $request->get_param( 'sw_lng' );
		$ne_lat = $request->get_param( 'ne_lat' );
		$ne_lng = $request->get_param( 'ne_lng' );
		if ( null !== $sw_lat && null !== $sw_lng && null !== $ne_lat && null !== $ne_lng ) {
			$input['bounds'] = implode( ',', array(
				(float) $sw_lat,
				(float) $sw_lng,
				(float) $ne_lat,
				(float) $ne_lng,
			) );
		}

		// Location taxonomy filter.
		$location = $request->get_param( 'location' );
		if ( $location ) {
			$term = get_term_by( 'slug', $location, 'location' );
			if ( $term && ! is_wp_error( $term ) ) {
				$input['taxonomy'] = 'location';
				$input['term_id']  = $term->term_id;
			}
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'venues_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$venues = array_map( 'extrachill_api_transform_venue', $result['venues'] ?? array() );
		return rest_ensure_response( $venues );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle single venue request.
 *
 * Reads venue term data directly — bypasses the admin-gated get-venue ability
 * since public venue detail is read-only taxonomy data.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_venue_get_handler( WP_REST_Request $request ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id ) {
		return new WP_Error(
			'events_site_unavailable',
			__( 'Events site is not configured.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $events_blog_id );
	try {
		$term_id = (int) $request->get_param( 'id' );
		$term    = get_term( $term_id, 'venue' );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error(
				'venue_not_found',
				__( 'Venue not found.', 'extrachill-api' ),
				array( 'status' => 404 )
			);
		}

		// Build venue detail from term meta.
		$venue_data = array(
			'term_id' => $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
		);

		// Read venue meta fields if Venue_Taxonomy helper is available.
		if ( class_exists( '\\DataMachineEvents\\Core\\Venue_Taxonomy' ) ) {
			$raw = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( $term->term_id );
			$venue_data['address']     = $raw['address'] ?? '';
			$venue_data['city']        = $raw['city'] ?? '';
			$venue_data['state']       = $raw['state'] ?? '';
			$venue_data['country']     = $raw['country'] ?? '';
			$venue_data['timezone']    = $raw['timezone'] ?? '';
			$venue_data['website']     = $raw['website'] ?? '';
			$venue_data['coordinates'] = get_term_meta( $term->term_id, '_venue_coordinates', true ) ?: '';
		}

		return rest_ensure_response( extrachill_api_transform_venue_detail( $venue_data ) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Handle check duplicate venue request.
 *
 * Reads venue terms directly — bypasses the admin-gated ability
 * since this is a read-only name search.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_venue_check_duplicate_handler( WP_REST_Request $request ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id ) {
		return new WP_Error(
			'events_site_unavailable',
			__( 'Events site is not configured.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $events_blog_id );
	try {
		$name = $request->get_param( 'name' );

		// Search for venues matching the name.
		$terms = get_terms( array(
			'taxonomy'   => 'venue',
			'hide_empty' => false,
			'name__like' => $name,
			'number'     => 10,
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return rest_ensure_response( array() );
		}

		$matches = array();
		foreach ( $terms as $term ) {
			$matches[] = extrachill_api_transform_venue_detail( array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
			) );
		}

		return rest_ensure_response( $matches );
	} finally {
		restore_current_blog();
	}
}

/**
 * Transform a venue from list-venues ability output into the Venue shape.
 *
 * @param array $venue Venue data from list-venues ability.
 * @return array Transformed venue.
 */
function extrachill_api_transform_venue( array $venue ): array {
	return array(
		'id'          => (int) ( $venue['term_id'] ?? 0 ),
		'name'        => $venue['name'] ?? '',
		'slug'        => $venue['slug'] ?? '',
		'address'     => $venue['address'] ?? null,
		'latitude'    => isset( $venue['lat'] ) ? (float) $venue['lat'] : null,
		'longitude'   => isset( $venue['lon'] ) ? (float) $venue['lon'] : null,
		'event_count' => (int) ( $venue['event_count'] ?? 0 ),
	);
}

/**
 * Transform a venue from get-venue ability output into the Venue shape.
 *
 * @param array $venue Venue data from get-venue ability.
 * @return array Transformed venue.
 */
function extrachill_api_transform_venue_detail( array $venue ): array {
	$lat = null;
	$lon = null;

	if ( ! empty( $venue['coordinates'] ) && strpos( $venue['coordinates'], ',' ) !== false ) {
		$parts = explode( ',', $venue['coordinates'] );
		$lat   = (float) trim( $parts[0] );
		$lon   = (float) trim( $parts[1] );
	}

	return array(
		'id'        => (int) ( $venue['term_id'] ?? 0 ),
		'name'      => $venue['name'] ?? '',
		'slug'      => $venue['slug'] ?? '',
		'address'   => $venue['address'] ?? null,
		'city'      => $venue['city'] ?? null,
		'state'     => $venue['state'] ?? null,
		'country'   => $venue['country'] ?? null,
		'latitude'  => $lat,
		'longitude' => $lon,
		'timezone'  => $venue['timezone'] ?? null,
		'website'   => $venue['website'] ?? null,
	);
}
