<?php
/**
 * REST routes: Concert tracking (attendance toggle, event info, user shows, user stats).
 *
 * Thin wrappers around extrachill-users concert tracking abilities.
 * All business logic lives in extrachill-users/inc/concert-tracking/service.php.
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
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_toggle( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_users_toggle_event' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Concert tracking requires the Extra Chill Users plugin.',
			array( 'status' => 500 )
		);
	}

	$user_id  = get_current_user_id();
	$event_id = (int) $request->get_param( 'event_id' );
	$blog_id  = (int) $request->get_param( 'blog_id' );

	if ( ! $blog_id ) {
		$blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : get_current_blog_id();
	}

	$result = ec_users_toggle_event( $user_id, $event_id, $blog_id );
	$count  = ec_users_get_event_mark_count( $event_id, $blog_id );
	$timing = ec_users_get_event_timing( $event_id );

	return rest_ensure_response(
		array(
			'marked'      => $result['marked'],
			'count'       => $count,
			'count_label' => ec_users_format_count_label( $count, $timing ),
			'timing'      => $timing,
		)
	);
}

/**
 * Handle GET /concert-tracking/event/{id}.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_event( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_users_get_event_mark_count' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Concert tracking requires the Extra Chill Users plugin.',
			array( 'status' => 500 )
		);
	}

	$event_id = (int) $request->get_param( 'event_id' );
	$blog_id  = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : get_current_blog_id();

	$count  = ec_users_get_event_mark_count( $event_id, $blog_id );
	$timing = ec_users_get_event_timing( $event_id );

	$data = array(
		'count'       => $count,
		'count_label' => ec_users_format_count_label( $count, $timing ),
		'timing'      => $timing,
		'user_marked' => false,
		'attendees'   => array(),
	);

	if ( is_user_logged_in() ) {
		$data['user_marked'] = ec_users_is_event_marked( get_current_user_id(), $event_id, $blog_id );
	}

	if ( $request->get_param( 'include_attendees' ) ) {
		$limit             = (int) $request->get_param( 'limit' );
		$data['attendees'] = ec_users_get_event_attendees( $event_id, $blog_id, $limit );
	}

	return rest_ensure_response( $data );
}

/**
 * Handle GET /concert-tracking/user/{id}/shows.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_user_shows( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_users_get_user_events' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Concert tracking requires the Extra Chill Users plugin.',
			array( 'status' => 500 )
		);
	}

	$user_id = (int) $request->get_param( 'user_id' );

	if ( ! get_user_by( 'id', $user_id ) ) {
		return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
	}

	$args = array(
		'period'    => $request->get_param( 'period' ),
		'year'      => (int) $request->get_param( 'year' ),
		'date_from' => $request->get_param( 'date_from' ),
		'date_to'   => $request->get_param( 'date_to' ),
		'page'      => (int) $request->get_param( 'page' ),
		'per_page'  => (int) $request->get_param( 'per_page' ),
	);

	return rest_ensure_response( ec_users_get_user_events( $user_id, $args ) );
}

/**
 * Handle GET /concert-tracking/user/{id}/stats.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_handle_concert_tracking_user_stats( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_users_get_user_concert_stats' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Concert tracking requires the Extra Chill Users plugin.',
			array( 'status' => 500 )
		);
	}

	$user_id = (int) $request->get_param( 'user_id' );

	if ( ! get_user_by( 'id', $user_id ) ) {
		return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
	}

	$args = array(
		'year'      => (int) $request->get_param( 'year' ),
		'date_from' => $request->get_param( 'date_from' ),
		'date_to'   => $request->get_param( 'date_to' ),
	);

	return rest_ensure_response( ec_users_get_user_concert_stats( $user_id, $args ) );
}
