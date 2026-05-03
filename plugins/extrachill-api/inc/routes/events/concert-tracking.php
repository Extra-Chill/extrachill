<?php
/**
 * REST routes: Concert tracking (attendance toggle, event info, user shows, user stats).
 *
 * Thin REST wrappers that invoke extrachill-users concert tracking abilities.
 * All business logic lives in extrachill-users via the Abilities API.
 *
 * Endpoints:
 *   POST /extrachill/v1/concert-tracking/toggle
 *   GET  /extrachill/v1/concert-tracking/event/(?P<event_id>\d+)
 *   GET  /extrachill/v1/concert-tracking/user/(?P<user_id>\d+)/shows
 *   GET  /extrachill/v1/concert-tracking/user/(?P<user_id>\d+)/stats
 *
 * @package ExtraChillAPI
 * @since 0.13.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_concert_tracking_routes' );

/**
 * Register concert tracking REST routes.
 */
function extrachill_api_register_concert_tracking_routes() {

	// POST /concert-tracking/toggle — mark/unmark an event.
	register_rest_route(
		'extrachill/v1',
		'/concert-tracking/toggle',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_concert_tracking_toggle',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'event_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => 'Event post ID.',
				),
				'blog_id'  => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
					'description'       => 'Blog ID. Defaults to events blog.',
				),
			),
		)
	);

	// GET /concert-tracking/event/{id} — attendance info for an event.
	register_rest_route(
		'extrachill/v1',
		'/concert-tracking/event/(?P<event_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_handle_concert_tracking_event',
			'permission_callback' => '__return_true',
			'args'                => array(
				'event_id'          => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'include_attendees' => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
					'description'       => 'Include attendee list.',
				),
				'limit'             => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 10,
					'description'       => 'Max attendees to return.',
				),
			),
		)
	);

	// GET /concert-tracking/user/{id}/shows — paginated concert history.
	register_rest_route(
		'extrachill/v1',
		'/concert-tracking/user/(?P<user_id>\d+)/shows',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_handle_concert_tracking_user_shows',
			'permission_callback' => '__return_true',
			'args'                => array(
				'user_id'   => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'period'    => array(
					'required' => false,
					'type'     => 'string',
					'default'  => 'all',
					'enum'     => array( 'upcoming', 'past', 'all' ),
				),
				'year'      => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				),
				'date_from' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
				'date_to'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
				'page'      => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 1,
				),
				'per_page'  => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 20,
				),
			),
		)
	);

	// GET /concert-tracking/user/{id}/stats — aggregate stats.
	register_rest_route(
		'extrachill/v1',
		'/concert-tracking/user/(?P<user_id>\d+)/stats',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_handle_concert_tracking_user_stats',
			'permission_callback' => '__return_true',
			'args'                => array(
				'user_id'   => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'year'      => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				),
				'date_from' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
				'date_to'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
			),
		)
	);
}

// ─── Handler Callbacks ───────────────────────────────────────────────────────

/**
 * Handle POST /concert-tracking/toggle.
 *
 * Invokes the extrachill/toggle-event-mark ability (registered in extrachill-users).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_toggle( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/toggle-event-mark' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array(
		'event_id' => (int) $request->get_param( 'event_id' ),
		'blog_id'  => (int) $request->get_param( 'blog_id' ),
	) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle GET /concert-tracking/event/{id}.
 *
 * Invokes the extrachill/get-event-attendance ability (registered in extrachill-users).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_event( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-event-attendance' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array(
		'event_id'          => (int) $request->get_param( 'event_id' ),
		'include_attendees' => (bool) $request->get_param( 'include_attendees' ),
		'limit'             => (int) $request->get_param( 'limit' ),
	) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle GET /concert-tracking/user/{id}/shows.
 *
 * Invokes the extrachill/get-user-shows ability (registered in extrachill-users).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_user_shows( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-user-shows' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array(
		'user_id'   => (int) $request->get_param( 'user_id' ),
		'period'    => $request->get_param( 'period' ),
		'year'      => (int) $request->get_param( 'year' ),
		'date_from' => $request->get_param( 'date_from' ),
		'date_to'   => $request->get_param( 'date_to' ),
		'page'      => (int) $request->get_param( 'page' ),
		'per_page'  => (int) $request->get_param( 'per_page' ),
	) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Handle GET /concert-tracking/user/{id}/stats.
 *
 * Invokes the extrachill/get-user-concert-stats ability (registered in extrachill-users).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_user_stats( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-user-concert-stats' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-users plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array(
		'user_id'   => (int) $request->get_param( 'user_id' ),
		'year'      => (int) $request->get_param( 'year' ),
		'date_from' => $request->get_param( 'date_from' ),
		'date_to'   => $request->get_param( 'date_to' ),
	) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
