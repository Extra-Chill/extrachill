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
 * Executes the calendar ability and transforms the output into the
 * api-client CalendarResponse shape. Route affinity middleware ensures
 * this runs on the events site where the ability and taxonomies exist.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_calendar_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'data-machine-events/get-calendar-page' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_unavailable',
			__( 'Calendar ability is not registered.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$input = array(
		'paged'        => $request->get_param( 'page' ) ?: 1,
		'include_html' => false,
		'include_gaps' => false,
		'past'         => (bool) $request->get_param( 'past' ),
	);

	$scope = $request->get_param( 'scope' );
	if ( $scope ) {
		$input['scope'] = $scope;
	}

	$search = $request->get_param( 'search' );
	if ( $search ) {
		$input['event_search'] = $search;
	}

	// Build taxonomy filter from slug params.
	$tax_filter = extrachill_api_build_calendar_tax_filter( $request );
	if ( ! empty( $tax_filter ) ) {
		$input['tax_filter'] = $tax_filter;
	}

	// Geo params.
	$lat = $request->get_param( 'lat' );
	$lng = $request->get_param( 'lng' );
	if ( null !== $lat && null !== $lng ) {
		$input['geo_lat']    = (float) $lat;
		$input['geo_lng']    = (float) $lng;
		$input['geo_radius'] = $request->get_param( 'radius' ) ?: 25;
	}

	$result = $ability->execute( $input );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'calendar_error',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( extrachill_api_transform_calendar_response( $result ) );
}

/**
 * Build taxonomy filter array from request slug parameters.
 *
 * Resolves taxonomy slugs to term IDs for the ability input.
 *
 * @param WP_REST_Request $request Request object.
 * @return array Taxonomy filter array [taxonomy => [term_ids]].
 */
function extrachill_api_build_calendar_tax_filter( WP_REST_Request $request ): array {
	$tax_filter = array();
	$mappings   = array(
		'venue'    => 'venue',
		'promoter' => 'promoter',
		'location' => 'location',
	);

	foreach ( $mappings as $param => $taxonomy ) {
		$slug = $request->get_param( $param );
		if ( empty( $slug ) ) {
			continue;
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$tax_filter[ $taxonomy ] = array( $term->term_id );
		}
	}

	return $tax_filter;
}

/**
 * Transform ability output into CalendarResponse shape.
 *
 * Ability returns: { paged_date_groups, current_page, max_pages, total_event_count, ... }
 * Client expects: { dates, total, page, has_more }
 *
 * @param array $result Ability output.
 * @return array Transformed response.
 */
function extrachill_api_transform_calendar_response( array $result ): array {
	$dates = array();

	foreach ( $result['paged_date_groups'] as $group ) {
		$date_obj = date_create( $group['date'] );
		$label    = $date_obj ? $date_obj->format( 'l, F j, Y' ) : $group['date'];

		$events = array();
		foreach ( $group['events'] as $event ) {
			$event_data = $event['event_data'] ?? array();
			$post_id    = $event['post_id'];

			// Build datetime from startDate + startTime block attributes.
			$datetime = '';
			if ( ! empty( $event_data['startDate'] ) ) {
				$time     = $event_data['startTime'] ?? '00:00:00';
				$datetime = $event_data['startDate'] . 'T' . $time;
			}

			$end_datetime = null;
			if ( ! empty( $event_data['endDate'] ) ) {
				$end_time     = $event_data['endTime'] ?? '23:59:59';
				$end_datetime = $event_data['endDate'] . 'T' . $end_time;
			}

			// Resolve venue from post terms (event_data.venue is just a string name).
			$venue_data  = null;
			$venue_terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'all' ) );
			if ( ! empty( $venue_terms ) && ! is_wp_error( $venue_terms ) ) {
				$venue_data = array(
					'id'   => $venue_terms[0]->term_id,
					'name' => $venue_terms[0]->name,
					'slug' => $venue_terms[0]->slug,
				);
			}

			// Ticket URL from block attributes or post meta.
			$ticket_url = $event_data['ticketUrl'] ?? null;
			if ( empty( $ticket_url ) ) {
				$ticket_url = get_post_meta( $post_id, '_datamachine_ticket_url', true ) ?: null;
			}

			$events[] = array(
				'id'           => $post_id,
				'title'        => $event['title'],
				'datetime'     => $datetime,
				'end_datetime' => $end_datetime,
				'venue'        => $venue_data,
				'ticket_url'   => $ticket_url,
				'permalink'    => get_permalink( $post_id ),
			);
		}

		$dates[] = array(
			'date'   => $group['date'],
			'label'  => $label,
			'events' => $events,
		);
	}

	return array(
		'dates'    => $dates,
		'total'    => $result['total_event_count'] ?? 0,
		'page'     => $result['current_page'] ?? 1,
		'has_more' => ( $result['current_page'] ?? 1 ) < ( $result['max_pages'] ?? 1 ),
	);
}
