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
 * Check whether the current user may set attendance for the requested user.
 *
 * @param array $input Validated ability input.
 * @return bool
 */
function extrachill_users_can_set_event_mark( array $input ): bool {
	$current_user_id = get_current_user_id();
	if ( ! $current_user_id ) {
		return false;
	}

	$target_user_id = ! empty( $input['user_id'] ) ? (int) $input['user_id'] : $current_user_id;

	return $target_user_id === $current_user_id || current_user_can( 'manage_network_options' );
}

/**
 * Keep untargeted event attendance public while authorizing targeted reads.
 *
 * @param array $input Validated ability input.
 * @return bool
 */
function extrachill_users_can_get_event_attendance( array $input ): bool {
	if ( empty( $input['user_id'] ) ) {
		return true;
	}

	return extrachill_users_can_set_event_mark( $input );
}

/**
 * Register concert tracking abilities.
 */
function extrachill_users_register_concert_tracking_abilities() {

	// ─── Set Event Mark ────────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/set-event-mark',
		array(
			'label'               => __( 'Set Event Mark', 'extrachill-users' ),
			'description'         => __( 'Idempotently mark or unmark an event for the current user, or for another user when authorized.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'  => array(
						'type'        => 'integer',
						'description' => 'User ID. Defaults to current user. Targeting another user requires network administration permission.',
						'default'     => 0,
					),
					'event_id' => array(
						'type'        => 'integer',
						'description' => 'Event post ID.',
					),
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => 'Blog ID. Defaults to events blog.',
						'default'     => 0,
					),
					'marked'   => array(
						'type'        => 'boolean',
						'description' => 'Desired attendance state.',
					),
				),
				'required'   => array( 'event_id', 'marked' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'     => array( 'type' => 'integer' ),
					'marked'      => array( 'type' => 'boolean' ),
					'changed'     => array( 'type' => 'boolean' ),
					'count'       => array( 'type' => 'integer' ),
					'count_label' => array( 'type' => 'string' ),
					'timing'      => array( 'type' => 'string' ),
				),
				'required'   => array( 'user_id', 'marked', 'changed', 'count', 'count_label', 'timing' ),
			),
			'execute_callback'    => 'extrachill_users_ability_set_event_mark',
			'permission_callback' => 'extrachill_users_can_set_event_mark',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

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

	// ─── Search Events for Marking ───────────────────────────────────────────

	wp_register_ability(
		'extrachill/search-events-for-marking',
		array(
			'label'               => __( 'Search Past Events for Marking', 'extrachill-users' ),
			'description'         => __( 'Search past events (start_datetime < NOW) by title, artist, or venue. Returns is_marked per event for the current user. Powers the My Shows "Add Past Shows" tab.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'query'    => array(
						'type'        => 'string',
						'description' => 'Search query. Empty returns no results; the frontend renders a prompt instead.',
						'default'     => '',
					),
					'page'     => array(
						'type'        => 'integer',
						'description' => '1-indexed page number.',
						'default'     => 1,
					),
					'per_page' => array(
						'type'        => 'integer',
						'description' => 'Results per page.',
						'default'     => 20,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'events' => array( 'type' => 'array' ),
					'total'  => array( 'type' => 'integer' ),
					'pages'  => array( 'type' => 'integer' ),
					'page'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_search_events_for_marking',
			'permission_callback' => 'is_user_logged_in',
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
					'user_id'           => array(
						'type'        => 'integer',
						'description' => 'User ID whose mark state to return. Defaults to current user. Targeting another user requires network administration permission.',
						'default'     => 0,
					),
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
				'required'   => array( 'count', 'count_label', 'timing', 'user_marked', 'attendees' ),
			),
			'execute_callback'    => 'extrachill_users_ability_get_event_attendance',
			'permission_callback' => 'extrachill_users_can_get_event_attendance',
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
 * Resolve and authorize the user targeted by a concert tracking ability.
 *
 * @param array $input Ability input.
 * @return int|WP_Error
 */
function extrachill_users_resolve_concert_tracking_user( array $input ) {
	$current_user_id = get_current_user_id();
	$user_id         = ! empty( $input['user_id'] ) ? (int) $input['user_id'] : $current_user_id;

	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'no_user', 'A valid user ID is required.', array( 'status' => 400 ) );
	}

	if ( $user_id !== $current_user_id && ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error( 'forbidden_user_target', 'You are not authorized to manage concert attendance for this user.', array( 'status' => 403 ) );
	}

	return $user_id;
}

/**
 * Set event mark ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_set_event_mark( array $input ) {
	$user_id = extrachill_users_resolve_concert_tracking_user( $input );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$event_id     = (int) $input['event_id'];
	$blog_id      = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : ( function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : get_current_blog_id() );
	$marked       = (bool) $input['marked'];
	$write_result = $marked
		? ec_users_mark_event( $user_id, $event_id, $blog_id )
		: ec_users_unmark_event( $user_id, $event_id, $blog_id );
	if ( is_wp_error( $write_result ) ) {
		return $write_result;
	}

	$count  = ec_users_get_event_mark_count( $event_id, $blog_id );
	$timing = ec_users_get_event_timing( $event_id );

	return array(
		'user_id'     => $user_id,
		'marked'      => $marked,
		'changed'     => (bool) $write_result,
		'count'       => $count,
		'count_label' => ec_users_format_count_label( $count, $timing ),
		'timing'      => $timing,
	);
}

/**
 * Toggle event mark ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_toggle_event_mark( array $input ) {
	$user_id  = get_current_user_id();
	$event_id = (int) $input['event_id'];
	$blog_id  = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in to mark events.', array( 'status' => 401 ) );
	}

	$blog_id = ec_users_validate_event_target( $event_id, $blog_id );
	if ( is_wp_error( $blog_id ) ) {
		return $blog_id;
	}

	$result = ec_users_toggle_event( $user_id, $event_id, $blog_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

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
 * Search past events for marking ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_search_events_for_marking( array $input ) {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'You must be logged in to search events.', array( 'status' => 401 ) );
	}

	return ec_users_search_events_for_marking(
		$user_id,
		array(
			'query'    => isset( $input['query'] ) ? (string) $input['query'] : '',
			'page'     => isset( $input['page'] ) ? (int) $input['page'] : 1,
			'per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 20,
		)
	);
}

/**
 * Get event attendance ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_get_event_attendance( array $input ) {
	$user_id = 0;
	if ( ! empty( $input['user_id'] ) ) {
		$user_id = extrachill_users_resolve_concert_tracking_user( $input );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
	} elseif ( is_user_logged_in() ) {
		$user_id = get_current_user_id();
	}

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

	if ( $user_id ) {
		$result['user_marked'] = ec_users_is_event_marked( $user_id, $event_id, $blog_id );
	}

	if ( ! empty( $input['include_attendees'] ) ) {
		$limit               = ! empty( $input['limit'] ) ? (int) $input['limit'] : 10;
		$result['attendees'] = ec_users_get_event_attendees( $event_id, $blog_id, $limit );
	}

	return $result;
}
