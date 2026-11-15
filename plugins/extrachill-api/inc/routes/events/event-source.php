<?php
/**
 * Event-source intake: thin REST adapters for the extrachill-events
 * event-source abilities.
 *
 * POST /extrachill/v1/event-source/preview
 * POST /extrachill/v1/event-source/submit
 * POST /extrachill/v1/event-source/{id}/approve
 * POST /extrachill/v1/event-source/{id}/reject
 *
 * The event-submission block posts to the preview/submit routes. Each
 * callback maps request params onto an extrachill-events ability and returns
 * its result; qualification, persistence and approval live in the ability.
 * The abilities are registered on the events site only, so the family has
 * events-site route affinity.
 *
 * Direct-navigation guard: preview and submit refuse requests that do not
 * look like the site's own JSON client and answer with a 404, so a visitor
 * pasting the URL into the address bar does not see raw JSON.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_API_EVENT_SOURCE_PREVIEW_ABILITY = 'extrachill-events/preview-event-source';
const EXTRACHILL_API_EVENT_SOURCE_SUBMIT_ABILITY  = 'extrachill-events/submit-event-source';
const EXTRACHILL_API_EVENT_SOURCE_APPROVE_ABILITY = 'extrachill-events/approve-event-source-submission';
const EXTRACHILL_API_EVENT_SOURCE_REJECT_ABILITY  = 'extrachill-events/reject-event-source-submission';

add_filter( 'ec_route_site_affinity_map', 'extrachill_api_add_event_source_route_affinity' );
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_event_source_routes' );

/**
 * Give the event-source routes events-site affinity.
 *
 * @param array $affinity_map Route prefix to site key map.
 * @return array
 */
function extrachill_api_add_event_source_route_affinity( $affinity_map ) {
	$affinity_map['/extrachill/v1/event-source/'] = 'events';
	return $affinity_map;
}

/** Register the event-source intake and review adapters. */
function extrachill_api_register_event_source_routes() {
	register_rest_route(
		'extrachill/v1',
		'/event-source/preview',
		array(
			'methods'               => WP_REST_Server::CREATABLE,
			'callback'              => 'extrachill_api_event_source_preview',
			'_extrachill_abilities' => array( EXTRACHILL_API_EVENT_SOURCE_PREVIEW_ABILITY ),
			'permission_callback'   => 'extrachill_api_event_source_logged_in_permission',
			'args'                  => array(
				'url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/event-source/submit',
		array(
			'methods'               => WP_REST_Server::CREATABLE,
			'callback'              => 'extrachill_api_event_source_submit',
			'_extrachill_abilities' => array( EXTRACHILL_API_EVENT_SOURCE_SUBMIT_ABILITY ),
			'permission_callback'   => 'extrachill_api_event_source_logged_in_permission',
			'args'                  => array(
				'url'           => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'contact_email' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'contact_name'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/event-source/(?P<id>\d+)/approve',
		array(
			'methods'               => WP_REST_Server::CREATABLE,
			'callback'              => 'extrachill_api_event_source_approve',
			'_extrachill_abilities' => array( EXTRACHILL_API_EVENT_SOURCE_APPROVE_ABILITY ),
			'permission_callback'   => 'extrachill_api_event_source_admin_permission',
			'args'                  => array(
				'id'                => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'source_kind'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'entity_term_id'    => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'entity_name'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'venue_term_id'     => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'venue_name'        => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'artist_term_id'    => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_name'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'pipeline_id'       => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'schedule_interval' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/event-source/(?P<id>\d+)/reject',
		array(
			'methods'               => WP_REST_Server::CREATABLE,
			'callback'              => 'extrachill_api_event_source_reject',
			'_extrachill_abilities' => array( EXTRACHILL_API_EVENT_SOURCE_REJECT_ABILITY ),
			'permission_callback'   => 'extrachill_api_event_source_admin_permission',
			'args'                  => array(
				'id'     => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'reason' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		)
	);
}

/**
 * Preview and submit require a signed-in user.
 *
 * @return bool
 */
function extrachill_api_event_source_logged_in_permission() {
	return is_user_logged_in();
}

/**
 * Approve and reject require a network administrator of the events site.
 *
 * @return bool
 */
function extrachill_api_event_source_admin_permission() {
	return current_user_can( 'manage_options' );
}

/**
 * Whether the request looks like the site's own XHR/JSON client.
 *
 * An `X-Requested-With: XMLHttpRequest` header, or an Accept or Content-Type
 * header naming `application/json`, is enough to pass.
 *
 * @param WP_REST_Request $request REST request.
 * @return bool
 */
function extrachill_api_event_source_looks_like_xhr( WP_REST_Request $request ) {
	$requested_with = (string) $request->get_header( 'x_requested_with' );
	if ( '' !== $requested_with && false !== stripos( $requested_with, 'xmlhttprequest' ) ) {
		return true;
	}

	foreach ( array( 'accept', 'content_type' ) as $header ) {
		$value = (string) $request->get_header( $header );
		if ( '' !== $value && false !== stripos( $value, 'application/json' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The 404 returned to direct browser navigations.
 *
 * @return WP_Error
 */
function extrachill_api_event_source_direct_nav_404() {
	return new WP_Error(
		'rest_no_route',
		__( 'No route was found matching the URL and request method.', 'extrachill-api' ),
		array( 'status' => 404 )
	);
}

/**
 * Execute one event-source ability and wrap its result.
 *
 * WP_Errors pass through unchanged, including their ability-layer status.
 *
 * @param string $ability_name Ability name.
 * @param array  $input        Ability input.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_event_source_run_ability( $ability_name, array $input ) {
	$ability = wp_get_ability( $ability_name );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_missing',
			sprintf(
				/* translators: %s: ability name */
				__( 'Ability %s is not registered.', 'extrachill-api' ),
				$ability_name
			),
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response( $result, 200 );
}

/**
 * POST /event-source/preview.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_event_source_preview( WP_REST_Request $request ) {
	if ( ! extrachill_api_event_source_looks_like_xhr( $request ) ) {
		return extrachill_api_event_source_direct_nav_404();
	}

	return extrachill_api_event_source_run_ability(
		EXTRACHILL_API_EVENT_SOURCE_PREVIEW_ABILITY,
		array( 'url' => (string) $request->get_param( 'url' ) )
	);
}

/**
 * POST /event-source/submit.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_event_source_submit( WP_REST_Request $request ) {
	if ( ! extrachill_api_event_source_looks_like_xhr( $request ) ) {
		return extrachill_api_event_source_direct_nav_404();
	}

	return extrachill_api_event_source_run_ability(
		EXTRACHILL_API_EVENT_SOURCE_SUBMIT_ABILITY,
		array(
			'url'           => (string) $request->get_param( 'url' ),
			'contact_email' => (string) $request->get_param( 'contact_email' ),
			'contact_name'  => (string) $request->get_param( 'contact_name' ),
		)
	);
}

/**
 * POST /event-source/{id}/approve. Only supplied overrides are forwarded.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_event_source_approve( WP_REST_Request $request ) {
	$input = array( 'submission_id' => (int) $request->get_param( 'id' ) );
	foreach ( array( 'source_kind', 'entity_term_id', 'entity_name', 'venue_term_id', 'venue_name', 'artist_term_id', 'artist_name', 'pipeline_id', 'schedule_interval' ) as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value ) {
			$input[ $key ] = $value;
		}
	}

	return extrachill_api_event_source_run_ability( EXTRACHILL_API_EVENT_SOURCE_APPROVE_ABILITY, $input );
}

/**
 * POST /event-source/{id}/reject.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_event_source_reject( WP_REST_Request $request ) {
	return extrachill_api_event_source_run_ability(
		EXTRACHILL_API_EVENT_SOURCE_REJECT_ABILITY,
		array(
			'submission_id' => (int) $request->get_param( 'id' ),
			'reason'        => (string) $request->get_param( 'reason' ),
		)
	);
}
