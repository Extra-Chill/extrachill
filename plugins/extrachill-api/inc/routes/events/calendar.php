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
					'required'    => false,
					'type'        => 'number',
					'description' => 'Latitude for geo filtering',
				),
				'lng'      => array(
					'required'    => false,
					'type'        => 'number',
					'description' => 'Longitude for geo filtering',
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
					'required'    => false,
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Show past events',
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

	return rest_ensure_response( extrachill_api_gate_calendar_ticket_urls( $result ) );
}

/**
 * Null out affiliate ticket URLs in the public calendar payload.
 *
 * Ticketmaster compliance: unauthenticated, bulk-paginated REST access to
 * raw affiliate URLs (and the affiliate ID they carry) is a worse scraper
 * hole than the on-page HTML — see Extra-Chill/extrachill-api#174. This
 * plugin has no affiliate host knowledge of its own; it asks the hidden
 * data-machine-events/resolve-ticket-destination ability per event and
 * only acts on its is_affiliate answer.
 *
 * `ticket_url` is set to null (never removed — the key stays present so a
 * calendar block on an older data-machine-events release does not break)
 * and `ticket_ref` (the event post ID) is added so the frontend can route
 * the click through /extrachill/v1/events/tickets/{id}/go instead.
 * Non-affiliate ticket URLs — e.g. a venue's own box-office link — pass
 * through unchanged; there is no reason to gate those.
 *
 * If the ability is not yet registered (the sibling data-machine-events PR
 * may merge after this one) or a single event's resolution errors, that
 * event's ticket_url is left exactly as the upstream calendar ability
 * returned it rather than failing the whole calendar request.
 *
 * @param mixed $result Raw extrachill/events-calendar ability result.
 * @return mixed
 */
function extrachill_api_gate_calendar_ticket_urls( $result ) {
	if ( ! is_array( $result ) || empty( $result['dates'] ) || ! is_array( $result['dates'] ) ) {
		return $result;
	}

	// wp_get_abilities() (not wp_get_ability()) is deliberate here too — see
	// inc/routes/events/ticket-redirect.php for why.
	$ability = wp_get_abilities()[ EXTRACHILL_API_TICKET_REDIRECT_ABILITY ] ?? null;
	if ( ! $ability instanceof WP_Ability || false !== $ability->get_meta_item( 'show_in_rest' ) ) {
		return $result;
	}

	foreach ( $result['dates'] as $date_index => $date_group ) {
		if ( empty( $date_group['events'] ) || ! is_array( $date_group['events'] ) ) {
			continue;
		}

		foreach ( $date_group['events'] as $event_index => $event ) {
			if ( empty( $event['ticket_url'] ) || empty( $event['id'] ) ) {
				continue;
			}

			$resolution = $ability->execute( array( 'event_id' => (int) $event['id'] ) );
			if ( is_wp_error( $resolution ) || ! is_array( $resolution ) || empty( $resolution['is_affiliate'] ) ) {
				continue;
			}

			$result['dates'][ $date_index ]['events'][ $event_index ]['ticket_url'] = null;
			$result['dates'][ $date_index ]['events'][ $event_index ]['ticket_ref'] = (int) $event['id'];
		}
	}

	return $result;
}
