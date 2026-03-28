<?php
/**
 * Concert tracking abilities.
 *
 * Registers abilities for marking events and querying concert history/stats.
 * Business logic lives in inc/concert-tracking/service.php.
 *
 * @package ExtraChill\Users
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_concert_tracking_abilities' );

/**
 * Register concert tracking abilities.
 */
function extrachill_users_register_concert_tracking_abilities() {

	// ─── Mark / Unmark Event ─────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/toggle-event-mark',
		array(
			'label'               => __( 'Toggle Event Mark', 'extrachill-users' ),
			'description'         => __( 'Mark or unmark an event for the current user. Returns new state and attendee count.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'event_id' => array(
						'type'        => 'integer',
						'description' => 'Event post ID.',
					),
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => 'Blog ID. Defaults to events blog.',
						'default'     => 0,
					),
				),
				'required'   => array( 'event_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'marked'      => array( 'type' => 'boolean' ),
					'count'       => array( 'type' => 'integer' ),
					'count_label' => array( 'type' => 'string' ),
					'timing'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_toggle_event_mark',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Get User Shows ──────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/get-user-shows',
		array(
			'label'               => __( 'Get User Shows', 'extrachill-users' ),
			'description'         => __( 'Get paginated concert history for a user with enriched event details.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'   => array(
						'type'        => 'integer',
						'description' => 'User ID. Defaults to current user.',
						'default'     => 0,
					),
					'period'    => array(
						'type'        => 'string',
						'description' => 'Filter: upcoming, past, or all.',
						'default'     => 'all',
						'enum'        => array( 'upcoming', 'past', 'all' ),
					),
					'year'      => array(
						'type'        => 'integer',
						'description' => 'Filter by year.',
						'default'     => 0,
					),
					'date_from' => array(
						'type'        => 'string',
						'description' => 'Start date (Y-m-d).',
						'default'     => '',
					),
					'date_to'   => array(
						'type'        => 'string',
						'description' => 'End date (Y-m-d).',
						'default'     => '',
					),
					'page'      => array(
						'type'        => 'integer',
						'description' => 'Page number.',
						'default'     => 1,
					),
					'per_page'  => array(
						'type'        => 'integer',
						'description' => 'Results per page.',
						'default'     => 20,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'shows' => array( 'type' => 'array' ),
					'total' => array( 'type' => 'integer' ),
					'pages' => array( 'type' => 'integer' ),
					'page'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_user_shows',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Get User Stats ──────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/get-user-concert-stats',
		array(
			'label'               => __( 'Get User Concert Stats', 'extrachill-users' ),
			'description'         => __( 'Get aggregate concert stats: total shows, unique venues/artists/cities, top lists, shows by year.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'   => array(
						'type'        => 'integer',
						'description' => 'User ID. Defaults to current user.',
						'default'     => 0,
					),
					'year'      => array(
						'type'        => 'integer',
						'description' => 'Filter by year.',
						'default'     => 0,
					),
					'date_from' => array(
						'type'        => 'string',
						'description' => 'Start date (Y-m-d).',
						'default'     => '',
					),
					'date_to'   => array(
						'type'        => 'string',
						'description' => 'End date (Y-m-d).',
						'default'     => '',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'total_shows'    => array( 'type' => 'integer' ),
					'unique_venues'  => array( 'type' => 'integer' ),
					'unique_artists' => array( 'type' => 'integer' ),
					'unique_cities'  => array( 'type' => 'integer' ),
					'top_artists'    => array( 'type' => 'array' ),
					'top_venues'     => array( 'type' => 'array' ),
					'top_cities'     => array( 'type' => 'array' ),
					'shows_by_year'  => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_user_concert_stats',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Get Event Attendance ────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/get-event-attendance',
		array(
			'label'               => __( 'Get Event Attendance', 'extrachill-users' ),
			'description'         => __( 'Get attendance count and attendee list for an event.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'event_id'          => array(
						'type'        => 'integer',
						'description' => 'Event post ID.',
					),
					'blog_id'           => array(
						'type'        => 'integer',
						'description' => 'Blog ID. Defaults to current blog.',
						'default'     => 0,
					),
					'include_attendees' => array(
						'type'        => 'boolean',
						'description' => 'Include attendee list.',
						'default'     => false,
					),
					'limit'             => array(
						'type'        => 'integer',
						'description' => 'Max attendees to return.',
						'default'     => 10,
					),
				),
				'required'   => array( 'event_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'count'       => array( 'type' => 'integer' ),
					'count_label' => array( 'type' => 'string' ),
					'timing'      => array( 'type' => 'string' ),
					'user_marked' => array( 'type' => 'boolean' ),
					'attendees'   => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_event_attendance',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute Callbacks ───────────────────────────────────────────────────────

/**
 * Toggle event mark ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_toggle_event_mark( array $input ) {
	$user_id  = get_current_user_id();
	$event_id = (int) $input['event_id'];
	$blog_id  = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : ( function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : get_current_blog_id() );

	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in to mark events.', array( 'status' => 401 ) );
	}

	$result = ec_users_toggle_event( $user_id, $event_id, $blog_id );
	$count  = ec_users_get_event_mark_count( $event_id, $blog_id );
	$timing = ec_users_get_event_timing( $event_id );

	return array(
		'marked'      => $result['marked'],
		'count'       => $count,
		'count_label' => ec_users_format_count_label( $count, $timing ),
		'timing'      => $timing,
	);
}

/**
 * Get user shows ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_get_user_shows( array $input ) {
	$user_id = ! empty( $input['user_id'] ) ? (int) $input['user_id'] : get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error( 'no_user', 'User ID required.', array( 'status' => 400 ) );
	}

	return ec_users_get_user_events( $user_id, $input );
}

/**
 * Get user concert stats ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_get_user_concert_stats( array $input ) {
	$user_id = ! empty( $input['user_id'] ) ? (int) $input['user_id'] : get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error( 'no_user', 'User ID required.', array( 'status' => 400 ) );
	}

	return ec_users_get_user_concert_stats( $user_id, $input );
}

/**
 * Get event attendance ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_get_event_attendance( array $input ) {
	$event_id = (int) $input['event_id'];
	$blog_id  = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : ( function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : get_current_blog_id() );

	$count  = ec_users_get_event_mark_count( $event_id, $blog_id );
	$timing = ec_users_get_event_timing( $event_id );

	$result = array(
		'count'       => $count,
		'count_label' => ec_users_format_count_label( $count, $timing ),
		'timing'      => $timing,
		'user_marked' => false,
		'attendees'   => array(),
	);

	if ( is_user_logged_in() ) {
		$result['user_marked'] = ec_users_is_event_marked( get_current_user_id(), $event_id, $blog_id );
	}

	if ( ! empty( $input['include_attendees'] ) ) {
		$limit              = ! empty( $input['limit'] ) ? (int) $input['limit'] : 10;
		$result['attendees'] = ec_users_get_event_attendees( $event_id, $blog_id, $limit );
	}

	return $result;
}
