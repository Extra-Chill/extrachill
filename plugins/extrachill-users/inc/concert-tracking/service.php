<?php
/**
 * Concert tracking service functions.
 *
 * CRUD operations and stat queries for the concert tracking table.
 * All functions use $wpdb->prepare() for safe queries.
 *
 * Design: one record per user+event = "marked". The label (Going / Check In /
 * I Was There) is derived at render time from event timing, not stored.
 *
 * @package ExtraChill\Users
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/db.php';

// ─── Core CRUD ───────────────────────────────────────────────────────────────

/**
 * Validate an attendance target against the canonical Events site.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id Requested blog ID, or zero to use the canonical site.
 * @return int|WP_Error Canonical Events blog ID, or a validation error.
 */
function ec_users_validate_event_target( int $event_id, int $blog_id = 0 ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	$events_blog_id = (int) apply_filters( 'extrachill_users_events_blog_id', $events_blog_id );

	if ( $events_blog_id <= 0 || ( is_multisite() && ! get_site( $events_blog_id ) ) ) {
		return new WP_Error( 'events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	if ( $blog_id > 0 && is_multisite() && ! get_site( $blog_id ) ) {
		return new WP_Error( 'event_site_not_found', __( 'The requested event site does not exist.', 'extrachill-users' ), array( 'status' => 404 ) );
	}

	if ( $blog_id > 0 && $blog_id !== $events_blog_id ) {
		return new WP_Error( 'noncanonical_event_site', __( 'Attendance can only be recorded for events on the canonical Events site.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	$switched = get_current_blog_id() !== $events_blog_id;
	if ( $switched && ! switch_to_blog( $events_blog_id ) ) {
		return new WP_Error( 'events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	try {
		$post = get_post( $event_id );
		if ( ! $post ) {
			return new WP_Error( 'event_not_found', __( 'The requested event does not exist.', 'extrachill-users' ), array( 'status' => 404 ) );
		}

		if ( 'data_machine_events' !== $post->post_type ) {
			return new WP_Error( 'invalid_event_post_type', __( 'The requested post is not an event.', 'extrachill-users' ), array( 'status' => 400 ) );
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'event_not_published', __( 'Attendance can only be recorded for published events.', 'extrachill-users' ), array( 'status' => 400 ) );
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $events_blog_id;
}

/**
 * Mark an event for a user.
 *
 * Inserts a record if it doesn't exist. No-op if already marked.
 *
 * @param int $user_id User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: canonical Events site).
 * @return bool|WP_Error True if newly marked, false if already existed, or a validation error.
 */
function ec_users_mark_event( int $user_id, int $event_id, int $blog_id = 0 ) {
	global $wpdb;

	$validated_blog_id = ec_users_validate_event_target( $event_id, $blog_id );
	if ( is_wp_error( $validated_blog_id ) ) {
		return $validated_blog_id;
	}
	$blog_id = $validated_blog_id;

	$table    = extrachill_users_concert_tracking_table_name();
	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (user_id, event_id, blog_id, created_at) VALUES (%d, %d, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper; the unique key is the idempotence primitive.
			$user_id,
			$event_id,
			$blog_id,
			current_time( 'mysql', true )
		)
	);

	if ( false === $inserted ) {
		return new WP_Error( 'event_mark_database_error', __( 'Attendance could not be marked.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	if ( 1 === $inserted ) {
		/**
		 * Fires when a user newly marks an event (not on no-op re-marks).
		 *
		 * Consumed by the concert engagement layer to schedule show reminders
		 * and fire milestone notifications. Idempotent: only fires on the
		 * transition from unmarked → marked.
		 *
		 * @param int $user_id  User ID.
		 * @param int $event_id Event post ID.
		 * @param int $blog_id  Blog ID the event lives on.
		 */
		do_action( 'ec_users_event_marked', $user_id, $event_id, $blog_id );
	}

	return 1 === $inserted;
}

/**
 * Unmark an event for a user.
 *
 * @param int $user_id User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: canonical Events site).
 * @return bool|WP_Error True if removed, false if it did not exist, or a validation error.
 */
function ec_users_unmark_event( int $user_id, int $event_id, int $blog_id = 0 ) {
	global $wpdb;

	$validated_blog_id = ec_users_validate_event_target( $event_id, $blog_id );
	if ( is_wp_error( $validated_blog_id ) ) {
		return $validated_blog_id;
	}
	$blog_id = $validated_blog_id;

	$table   = extrachill_users_concert_tracking_table_name();
	$deleted = $wpdb->delete(
		$table,
		array(
			'user_id'  => $user_id,
			'event_id' => $event_id,
			'blog_id'  => $blog_id,
		),
		array( '%d', '%d', '%d' )
	);
	if ( false === $deleted ) {
		return new WP_Error( 'event_unmark_database_error', __( 'Attendance could not be unmarked.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	$removed = $deleted > 0;

	if ( $removed ) {
		/**
		 * Fires when a user unmarks an event they had previously marked.
		 *
		 * Consumed by the concert engagement layer to cancel any pending
		 * show-reminder scheduled action for this user+event.
		 *
		 * @param int $user_id  User ID.
		 * @param int $event_id Event post ID.
		 * @param int $blog_id  Blog ID the event lives on.
		 */
		do_action( 'ec_users_event_unmarked', $user_id, $event_id, $blog_id );
	}

	return $removed;
}

/**
 * Toggle an event mark for a user.
 *
 * Marks if unmarked, unmarks if marked.
 *
 * @param int $user_id User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: canonical Events site).
 * @return array{ marked: bool }|WP_Error New state or a validation error.
 */
function ec_users_toggle_event( int $user_id, int $event_id, int $blog_id = 0 ) {
	global $wpdb;

	$validated_blog_id = ec_users_validate_event_target( $event_id, $blog_id );
	if ( is_wp_error( $validated_blog_id ) ) {
		return $validated_blog_id;
	}
	$blog_id = $validated_blog_id;

	$lock_name = 'ec_attendance_' . md5( $user_id . ':' . $event_id . ':' . $blog_id );
	$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
	if ( null === $acquired && '' !== $wpdb->last_error ) {
		return new WP_Error( 'event_toggle_lock_database_error', __( 'Attendance could not be updated.', 'extrachill-users' ), array( 'status' => 500 ) );
	}
	if ( 1 !== (int) $acquired ) {
		return new WP_Error( 'event_toggle_lock_timeout', __( 'Attendance is busy. Please try again.', 'extrachill-users' ), array( 'status' => 409 ) );
	}

	try {
		if ( ec_users_is_event_marked( $user_id, $event_id, $blog_id ) ) {
			$result = ec_users_unmark_event( $user_id, $event_id, $blog_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $result || ! ec_users_is_event_marked( $user_id, $event_id, $blog_id ) ) {
				return array( 'marked' => false );
			}

			return new WP_Error( 'event_toggle_state_error', __( 'Attendance could not be updated.', 'extrachill-users' ), array( 'status' => 500 ) );
		}

		$result = ec_users_mark_event( $user_id, $event_id, $blog_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result || ec_users_is_event_marked( $user_id, $event_id, $blog_id ) ) {
			return array( 'marked' => true );
		}

		return new WP_Error( 'event_toggle_state_error', __( 'Attendance could not be updated.', 'extrachill-users' ), array( 'status' => 500 ) );
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}
}

/**
 * Check if a user has marked an event.
 *
 * @param int $user_id User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: current blog).
 * @return bool
 */
function ec_users_is_event_marked( int $user_id, int $event_id, int $blog_id = 0 ): bool {
	global $wpdb;

	if ( ! $blog_id ) {
		$blog_id = get_current_blog_id();
	}

	$table = extrachill_users_concert_tracking_table_name();

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE user_id = %d AND event_id = %d AND blog_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$user_id,
			$event_id,
			$blog_id
		)
	);
}

// ─── Counting ────────────────────────────────────────────────────────────────

/**
 * Count how many users have marked an event.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: current blog).
 * @return int
 */
function ec_users_get_event_mark_count( int $event_id, int $blog_id = 0 ): int {
	global $wpdb;

	if ( ! $blog_id ) {
		$blog_id = get_current_blog_id();
	}

	$table = extrachill_users_concert_tracking_table_name();

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND blog_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$event_id,
			$blog_id
		)
	);
}

/**
 * Count total events a user has marked.
 *
 * @param int $user_id User ID.
 * @param int $blog_id Blog ID (default: 0 for all sites).
 * @return int
 */
function ec_users_get_user_event_count( int $user_id, int $blog_id = 0 ): int {
	global $wpdb;

	$table = extrachill_users_concert_tracking_table_name();

	if ( $blog_id ) {
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND blog_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id,
				$blog_id
			)
		);
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$user_id
		)
	);
}

// ─── Dated Contributions ─────────────────────────────────────────────────────

/**
 * Get a user's concert check-in timestamps (raw UTC).
 *
 * Supplies the DATED concert-attendance data for the contribution-events seam
 * (ec_contribution_events). Returns the raw `created_at` column (UTC); day
 * computation + timezone normalization is handled centrally by
 * ec_bucket_utc_events_by_local_day() in the rank-system seam, so this getter
 * stays a thin data fetch with no timezone logic.
 *
 * Sibling to ec_users_get_user_event_count() (the scalar total).
 *
 * @param int $user_id User ID.
 * @return string[] MySQL 'Y-m-d H:i:s' strings in UTC.
 */
function ec_users_get_user_dated_event_checks( int $user_id ): array {
	global $wpdb;

	$table = extrachill_users_concert_tracking_table_name();

	$raw = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT created_at FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$user_id
		)
	);

	return is_array( $raw ) ? $raw : array();
}

// ─── Event Timing ────────────────────────────────────────────────────────────

/**
 * Resolve the Events site blog ID.
 *
 * @return int Events site blog ID.
 */
function ec_users_get_events_blog_id(): int {
	$blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7;

	return (int) apply_filters( 'extrachill_users_events_blog_id', $blog_id );
}

/**
 * Capture the current time in the Events site's local timezone.
 *
 * @param int $blog_id Events site blog ID.
 * @return string MySQL datetime in the Events site's timezone.
 */
function ec_users_get_events_now( int $blog_id ): string {
	$switched = false;
	if ( get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		$now = current_time( 'mysql' );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Filters the captured Events-site time used for timing comparisons.
	 *
	 * @param string $now     MySQL datetime in the Events site's timezone.
	 * @param int    $blog_id Events site blog ID.
	 */
	return (string) apply_filters( 'extrachill_users_event_timing_now', $now, $blog_id );
}

/**
 * Build a canonical event timing SQL condition.
 *
 * The two history tabs map states explicitly: Past contains only `past`, while
 * Upcoming uses `active` and therefore contains both `upcoming` and `ongoing`.
 * Past leads with the indexed start range and uses COALESCE only for the
 * residual end check, avoiding an OR that prevents index condition pushdown.
 *
 * @param string $timing `upcoming`, `ongoing`, `past`, or `active`.
 * @param string $now    MySQL datetime in the Events site's timezone.
 * @param string $alias  Trusted event dates table alias.
 * @return array{where: string, prepare: array}
 */
function ec_users_build_event_timing_condition( string $timing, string $now, string $alias = 'ed' ): array {
	$start = $alias . '.start_datetime';
	$end   = $alias . '.end_datetime';

	switch ( $timing ) {
		case 'upcoming':
			return array(
				'where'   => "{$start} >= %s",
				'prepare' => array( $now ),
			);
		case 'ongoing':
			return array(
				'where'   => "({$start} < %s AND {$end} >= %s)",
				'prepare' => array( $now, $now ),
			);
		case 'past':
			return array(
				'where'   => "({$start} < %s AND COALESCE({$end}, {$start}) < %s)",
				'prepare' => array( $now, $now ),
			);
		case 'active':
		default:
			return array(
				'where'   => "({$start} >= %s OR {$end} >= %s)",
				'prepare' => array( $now, $now ),
			);
	}
}

/**
 * Build a CASE expression that returns canonical event timing.
 *
 * @param string $now   MySQL datetime in the Events site's timezone.
 * @param string $alias Trusted event dates table alias.
 * @return array{sql: string, prepare: array}
 */
function ec_users_build_event_timing_case( string $now, string $alias = 'ed' ): array {
	$upcoming = ec_users_build_event_timing_condition( 'upcoming', $now, $alias );
	$ongoing  = ec_users_build_event_timing_condition( 'ongoing', $now, $alias );

	return array(
		'sql'     => "CASE WHEN {$upcoming['where']} THEN 'upcoming' WHEN {$ongoing['where']} THEN 'ongoing' ELSE 'past' END",
		'prepare' => array_merge( $upcoming['prepare'], $ongoing['prepare'] ),
	);
}

/**
 * Determine the timing state of an event.
 *
 * Extra Chill Users is network-activated and runs on every site, but the
 * data-machine-events plugin that owns datamachine_get_event_timing() is
 * Network: false and only active on the events site. Delegating to that
 * function therefore fails (or silently returns 'past') on every other site.
 *
 * Instead we read the per-site datamachine_event_dates table directly using
 * the events-blog prefix — mirroring ec_users_get_user_events() — so timing is
 * computed correctly from any site in the network. The timing rules match the
 * datamachine_get_event_timing() primitive:
 *   upcoming = start >= now
 *   ongoing  = start < now AND end >= now
 *   past     = start < now AND (end < now OR end IS NULL), or no start date.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id  Events site blog ID. Defaults to the canonical Events site.
 * @return string 'upcoming' | 'ongoing' | 'past'
 */
function ec_users_get_event_timing( int $event_id, int $blog_id = 0 ): string {
	global $wpdb;

	$blog_id       = $blog_id ? $blog_id : ec_users_get_events_blog_id();
	$events_prefix = $wpdb->get_blog_prefix( $blog_id );
	$dates_table   = $events_prefix . 'datamachine_event_dates';
	$timing_case   = ec_users_build_event_timing_case( ec_users_get_events_now( $blog_id ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Trusted table name and shared prepared CASE fragment.
	$timing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT {$timing_case['sql']} FROM {$dates_table} ed WHERE ed.post_id = %d",
			...array_merge( $timing_case['prepare'], array( $event_id ) )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	return in_array( $timing, array( 'upcoming', 'ongoing', 'past' ), true ) ? $timing : 'past';
}

/**
 * Format a count label based on event timing.
 *
 * @param int    $count Number of users.
 * @param string $timing Event timing state.
 * @return string Human-readable label.
 */
function ec_users_format_count_label( int $count, string $timing ): string {
	switch ( $timing ) {
		case 'upcoming':
			/* translators: %d: number of users going */
			return sprintf( _n( '%d going', '%d going', $count, 'extrachill-users' ), $count );
		case 'ongoing':
			/* translators: %d: number of users checked in */
			return sprintf( _n( '%d checked in', '%d checked in', $count, 'extrachill-users' ), $count );
		case 'past':
		default:
			/* translators: %d: number of users who attended */
			return sprintf( _n( '%d was there', '%d were there', $count, 'extrachill-users' ), $count );
	}
}

// ─── User Event Queries ──────────────────────────────────────────────────────

/**
 * Get a user's marked events with full event details.
 *
 * Switches to the events blog to query post meta and taxonomy data.
 *
 * @param int   $user_id User ID.
 * @param array $args {
 *     Optional query arguments.
 *     @type string $period    'upcoming' | 'past' | 'all'. Default 'all'.
 *     @type int    $year      Filter by year.
 *     @type string $date_from Start date (Y-m-d).
 *     @type string $date_to   End date (Y-m-d).
 *     @type int    $page      Page number. Default 1.
 *     @type int    $per_page  Results per page. Default 20.
 *     @type string $order     'ASC' | 'DESC'. Default depends on period.
 * }
 * @return array{ shows: array, total: int, pages: int, page: int }
 */
function ec_users_get_user_events( int $user_id, array $args = array() ): array {
	global $wpdb;

	$defaults = array(
		'period'    => 'all',
		'year'      => 0,
		'date_from' => '',
		'date_to'   => '',
		'page'      => 1,
		'per_page'  => 20,
		'order'     => '',
		'blog_id'   => 0,
	);

	$args = wp_parse_args( $args, $defaults );

	// Default sort: upcoming ASC (soonest first), past DESC (most recent first).
	if ( ! $args['order'] ) {
		$args['order'] = ( 'upcoming' === $args['period'] ) ? 'ASC' : 'DESC';
	}

	$args['order'] = strtoupper( $args['order'] );
	if ( ! in_array( $args['order'], array( 'ASC', 'DESC' ), true ) ) {
		$args['order'] = 'DESC';
	}

	$table         = extrachill_users_concert_tracking_table_name();
	$blog_id       = $args['blog_id'] ? (int) $args['blog_id'] : ec_users_get_events_blog_id();
	$events_prefix = $wpdb->get_blog_prefix( $blog_id );
	$posts_table   = $events_prefix . 'posts';

	// Build WHERE clauses.
	$where   = array( 'ct.user_id = %d', 'ct.blog_id = %d', 'p.post_type = %s', 'p.post_status = %s' );
	$prepare = array( $user_id, $blog_id, 'data_machine_events', 'publish' );

	$dates_table = $events_prefix . 'datamachine_event_dates';

	// Timing uses one Events-site snapshot for filtering, totals, and row labels.
	$now         = ec_users_get_events_now( $blog_id );
	$timing_case = ec_users_build_event_timing_case( $now );

	if ( 'upcoming' === $args['period'] ) {
		$timing  = ec_users_build_event_timing_condition( 'active', $now );
		$where[] = $timing['where'];
		$prepare = array_merge( $prepare, $timing['prepare'] );
	} elseif ( 'past' === $args['period'] ) {
		$timing  = ec_users_build_event_timing_condition( 'past', $now );
		$where[] = $timing['where'];
		$prepare = array_merge( $prepare, $timing['prepare'] );
	}

	if ( $args['year'] ) {
		$where[]   = 'YEAR(ed.start_datetime) = %d';
		$prepare[] = (int) $args['year'];
	}

	if ( $args['date_from'] ) {
		$where[]   = 'DATE(ed.start_datetime) >= %s';
		$prepare[] = sanitize_text_field( $args['date_from'] );
	}

	if ( $args['date_to'] ) {
		$where[]   = 'DATE(ed.start_datetime) <= %s';
		$prepare[] = sanitize_text_field( $args['date_to'] );
	}

	$where_sql = implode( ' AND ', $where );
	$order_sql = $args['order'];

	// Count total matching records.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from trusted helpers, placeholders live inside $where_sql fragments which are interpolated.
	$count_sql = $wpdb->prepare(
		"SELECT COUNT(*)
		FROM {$table} ct
		INNER JOIN {$dates_table} ed ON ct.event_id = ed.post_id
		INNER JOIN {$posts_table} p ON ct.event_id = p.ID
		WHERE {$where_sql}",
		...$prepare
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	$pages = $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 1;
	$page  = max( 1, min( (int) $args['page'], $pages ? $pages : 1 ) );

	$offset = ( $page - 1 ) * $args['per_page'];

	// Fetch event IDs with date ordering.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names from trusted helpers, $where_sql carries additional placeholders matched by $prepare; $order_sql validated to ASC/DESC above.
	$query = $wpdb->prepare(
		"SELECT ct.event_id, ct.created_at AS marked_at, DATE(ed.start_datetime) AS event_date, {$timing_case['sql']} AS timing
		FROM {$table} ct
		INNER JOIN {$dates_table} ed ON ct.event_id = ed.post_id
		INNER JOIN {$posts_table} p ON ct.event_id = p.ID
		WHERE {$where_sql}
		ORDER BY ed.start_datetime {$order_sql}
		LIMIT %d OFFSET %d",
		...array_merge( $timing_case['prepare'], $prepare, array( $args['per_page'], $offset ) )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

	if ( empty( $rows ) ) {
		return array(
			'shows' => array(),
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		);
	}

	// Enrich with event details (switch to events blog for taxonomy queries).
	$shows        = array();
	$switched     = false;
	$current_blog = get_current_blog_id();

	if ( $current_blog !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		_prime_post_caches( array_map( 'intval', wp_list_pluck( $rows, 'event_id' ) ), true, true );

		foreach ( $rows as $row ) {
			$event_id = (int) $row['event_id'];
			$post     = get_post( $event_id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$shows[] = ec_users_build_show_data( $post, $row );
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return array(
		'shows' => $shows,
		'total' => $total,
		'pages' => $pages,
		'page'  => $page,
	);
}

/**
 * Build enriched show data from a post and tracking row.
 *
 * Must be called within the events blog context (switch_to_blog).
 *
 * @param WP_Post $post Event post.
 * @param array   $row  Tracking table row with marked_at, event_date.
 * @return array Enriched show data.
 */
function ec_users_build_show_data( WP_Post $post, array $row ): array {
	$event_id = $post->ID;
	$blog_id  = get_current_blog_id();

	// Venue (first term).
	$venue       = null;
	$venue_terms = wp_get_post_terms( $event_id, 'venue', array( 'number' => 1 ) );
	if ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) {
		$venue = array(
			'name' => $venue_terms[0]->name,
			'slug' => $venue_terms[0]->slug,
			'url'  => ec_users_events_term_archive_url( $venue_terms[0]->slug, 'venue', $blog_id ),
		);
	}

	// Location (city — deepest term).
	$city           = null;
	$location_terms = wp_get_post_terms( $event_id, 'location' );
	if ( ! is_wp_error( $location_terms ) && ! empty( $location_terms ) ) {
		// Use the deepest (most specific) location term.
		$deepest   = null;
		$max_depth = -1;
		foreach ( $location_terms as $term ) {
			$depth  = 0;
			$parent = $term->parent;
			while ( $parent ) {
				++$depth;
				$parent_term = get_term( $parent, 'location' );
				$parent      = $parent_term && ! is_wp_error( $parent_term ) ? $parent_term->parent : 0;
			}
			if ( $depth > $max_depth ) {
				$max_depth = $depth;
				$deepest   = $term;
			}
		}
		// @phpstan-ignore-next-line -- Defensive: $deepest is null when location_terms is empty, always set otherwise.
		if ( null !== $deepest ) {
			$city = array(
				'name' => $deepest->name,
				'slug' => $deepest->slug,
				'url'  => ec_users_events_term_archive_url( $deepest->slug, 'location', $blog_id ),
			);
		}
	}

	// Artists. Enrich each with a cross-site `url` (canonical artist profile
	// on the artist site, falling back to the events artist archive) via the
	// same linker primitive that powers the leaderboard, so a show card
	// becomes multiple on-ramps into the network rather than a single link.
	$artists      = array();
	$artist_terms = wp_get_post_terms( $event_id, 'artist' );
	if ( ! is_wp_error( $artist_terms ) ) {
		foreach ( $artist_terms as $term ) {
			$artists[] = array(
				'name' => $term->name,
				'slug' => $term->slug,
			);
		}
		$artists = ec_users_link_top_terms( $artists, 'artist', $blog_id );
	}

	return array(
		'event_id'   => $event_id,
		'title'      => $post->post_title,
		'event_date' => $row['event_date'] ?? '',
		'event_time' => $row['event_time'] ?? '',
		'venue'      => $venue,
		'city'       => $city,
		'artists'    => $artists,
		'timing'     => isset( $row['timing'] ) ? (string) $row['timing'] : ec_users_get_event_timing( $event_id, $blog_id ),
		'marked_at'  => $row['marked_at'] ?? '',
		'permalink'  => get_permalink( $event_id ),
		'thumbnail'  => get_the_post_thumbnail_url( $event_id, 'medium' ),
	);
}

// ─── Stats Queries ───────────────────────────────────────────────────────────

/**
 * Get aggregate concert stats for a user.
 *
 * @param int   $user_id User ID.
 * @param array $args {
 *     Optional filter arguments.
 *     @type int    $year      Filter by year.
 *     @type string $date_from Start date (Y-m-d).
 *     @type string $date_to   End date (Y-m-d).
 *     @type int    $blog_id   Blog ID (default: events blog).
 * }
 * @return array Stats data.
 */
function ec_users_get_user_concert_stats( int $user_id, array $args = array() ): array {
	global $wpdb;

	$blog_id       = ! empty( $args['blog_id'] ) ? (int) $args['blog_id'] : ec_users_get_events_blog_id();
	$table         = extrachill_users_concert_tracking_table_name();
	$events_prefix = $wpdb->get_blog_prefix( $blog_id );
	$posts_table   = $events_prefix . 'posts';

	$dates_table = $events_prefix . 'datamachine_event_dates';

	// Base WHERE for this user + blog.
	$where   = array( 'ct.user_id = %d', 'ct.blog_id = %d', 'p.post_type = %s', 'p.post_status = %s' );
	$prepare = array( $user_id, $blog_id, 'data_machine_events', 'publish' );

	if ( ! empty( $args['year'] ) ) {
		$where[]   = 'YEAR(ed.start_datetime) = %d';
		$prepare[] = (int) $args['year'];
	}
	if ( ! empty( $args['date_from'] ) ) {
		$where[]   = 'DATE(ed.start_datetime) >= %s';
		$prepare[] = sanitize_text_field( $args['date_from'] );
	}
	if ( ! empty( $args['date_to'] ) ) {
		$past      = ec_users_build_event_timing_condition( 'past', ec_users_get_events_now( $blog_id ) );
		$where[]   = $past['where'];
		$prepare   = array_merge( $prepare, $past['prepare'] );
		$where[]   = 'DATE(ed.start_datetime) <= %s';
		$prepare[] = sanitize_text_field( $args['date_to'] );
	}

	$where_sql = implode( ' AND ', $where );

	// Total shows.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from trusted helpers, placeholders live inside $where_sql fragments which are interpolated.
	$total_shows = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table} ct
			INNER JOIN {$dates_table} ed ON ct.event_id = ed.post_id
			INNER JOIN {$posts_table} p ON ct.event_id = p.ID
			WHERE {$where_sql}",
			...$prepare
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	if ( 0 === $total_shows ) {
		return array(
			'total_shows'    => 0,
			'unique_venues'  => 0,
			'unique_artists' => 0,
			'unique_cities'  => 0,
			'first_show'     => null,
			'latest_show'    => null,
			'top_artists'    => array(),
			'top_venues'     => array(),
			'top_cities'     => array(),
			'shows_by_year'  => array(),
		);
	}

	// Get all matching event IDs for taxonomy queries.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from trusted helpers, placeholders live inside $where_sql fragments which are interpolated.
	$event_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ct.event_id
			FROM {$table} ct
			INNER JOIN {$dates_table} ed ON ct.event_id = ed.post_id
			INNER JOIN {$posts_table} p ON ct.event_id = p.ID
			WHERE {$where_sql}",
			...$prepare
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$event_ids     = array_map( 'intval', $event_ids );
	$event_ids_csv = implode( ',', $event_ids );

	// Switch to events blog for taxonomy queries.
	$switched     = false;
	$current_blog = get_current_blog_id();
	if ( $current_blog !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		$term_relationships = $events_prefix . 'term_relationships';
		$term_taxonomy      = $events_prefix . 'term_taxonomy';
		$terms_table        = $events_prefix . 'terms';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from trusted prefix, event_ids_csv is integer-cast list.

		// Unique venues.
		$unique_venues = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'venue'"
		);

		// Unique artists.
		$unique_artists = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'artist'"
		);

		// Unique cities (location taxonomy).
		$unique_cities = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'location' AND tt.parent != 0"
		);

		// Top artists (top 10).
		$top_artists = $wpdb->get_results(
			"SELECT t.name, t.slug, COUNT(DISTINCT tr.object_id) AS count
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$terms_table} t ON tt.term_id = t.term_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'artist'
			GROUP BY tt.term_id
			ORDER BY count DESC
			LIMIT 10",
			ARRAY_A
		);

		// Top venues (top 10).
		$top_venues = $wpdb->get_results(
			"SELECT t.name, t.slug, COUNT(DISTINCT tr.object_id) AS count
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$terms_table} t ON tt.term_id = t.term_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'venue'
			GROUP BY tt.term_id
			ORDER BY count DESC
			LIMIT 10",
			ARRAY_A
		);

		// Top cities (top 10, only leaf-level location terms).
		$top_cities = $wpdb->get_results(
			"SELECT t.name, t.slug, COUNT(DISTINCT tr.object_id) AS count
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$terms_table} t ON tt.term_id = t.term_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'location' AND tt.parent != 0
			GROUP BY tt.term_id
			ORDER BY count DESC
			LIMIT 10",
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	// Shows by year.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from trusted helpers, placeholders live inside $where_sql fragments which are interpolated.
	$shows_by_year_raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT YEAR(ed.start_datetime) AS yr, COUNT(*) AS count
			FROM {$table} ct
			INNER JOIN {$dates_table} ed ON ct.event_id = ed.post_id
			INNER JOIN {$posts_table} p ON ct.event_id = p.ID
			WHERE {$where_sql}
			GROUP BY yr
			ORDER BY yr DESC",
			...$prepare
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$shows_by_year = array();
	foreach ( $shows_by_year_raw as $row ) {
		$shows_by_year[ $row['yr'] ] = (int) $row['count'];
	}

	// First and latest show.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from trusted helpers, placeholders live inside $where_sql fragments which are interpolated.
	$first_show_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT ct.event_id, DATE(ed.start_datetime) AS event_date
			FROM {$table} ct
			INNER JOIN {$dates_table} ed ON ct.event_id = ed.post_id
			INNER JOIN {$posts_table} p ON ct.event_id = p.ID
			WHERE {$where_sql}
			ORDER BY ed.start_datetime ASC
			LIMIT 1",
			...$prepare
		),
		ARRAY_A
	);

	$latest_show_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT ct.event_id, DATE(ed.start_datetime) AS event_date
			FROM {$table} ct
			INNER JOIN {$dates_table} ed ON ct.event_id = ed.post_id
			INNER JOIN {$posts_table} p ON ct.event_id = p.ID
			WHERE {$where_sql}
			ORDER BY ed.start_datetime DESC
			LIMIT 1",
			...$prepare
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$first_show  = null;
	$latest_show = null;

	$switched = false;
	if ( get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		$summary_ids = array_filter(
			array(
				$first_show_row ? (int) $first_show_row['event_id'] : 0,
				$latest_show_row ? (int) $latest_show_row['event_id'] : 0,
			)
		);
		_prime_post_caches( array_values( array_unique( $summary_ids ) ), true, true );

		if ( $first_show_row ) {
			$post = get_post( (int) $first_show_row['event_id'] );
			if ( $post instanceof WP_Post ) {
				$first_show = array(
					'event_id' => $post->ID,
					'title'    => $post->post_title,
					'date'     => $first_show_row['event_date'],
				);
			}
		}

		if ( $latest_show_row ) {
			$post = get_post( (int) $latest_show_row['event_id'] );
			if ( $post instanceof WP_Post ) {
				$latest_show = array(
					'event_id' => $post->ID,
					'title'    => $post->post_title,
					'date'     => $latest_show_row['event_date'],
				);
			}
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	// Cast counts to int in top arrays.
	$cast_counts = function ( $items ) {
		return array_map(
			function ( $item ) {
				$item['count'] = (int) $item['count'];
				return $item;
			},
			$items ? $items : array()
		);
	};

	return array(
		'total_shows'    => $total_shows,
		'unique_venues'  => $unique_venues,
		'unique_artists' => $unique_artists,
		'unique_cities'  => $unique_cities,
		'first_show'     => $first_show,
		'latest_show'    => $latest_show,
		'top_artists'    => ec_users_link_top_terms( $cast_counts( $top_artists ), 'artist', $blog_id ),
		'top_venues'     => ec_users_link_top_terms( $cast_counts( $top_venues ), 'venue', $blog_id ),
		'top_cities'     => ec_users_link_top_terms( $cast_counts( $top_cities ), 'location', $blog_id ),
		'shows_by_year'  => $shows_by_year,
	);
}

/**
 * Enrich top-term stat items with a `url` for cross-site navigation.
 *
 * Turns the My Shows leaderboards (top artists / venues / cities) from a
 * dead-end list into a launchpad into the network — the platform's
 * network-density / interconnectedness goal. Each item gains a `url`:
 *
 * - artist → the artist's profile on the artist site (canonical entity link
 *   via extrachill_get_artist_profile_by_slug), falling back to the events
 *   artist archive.
 * - venue  → the events-site venue archive.
 * - location (city) → the events-site location archive.
 *
 * Uses the network's canonical linker primitives (extrachill-network) rather
 * than hand-building URLs, so caching + content-awareness are inherited. Items
 * with no resolvable target keep `url => ''` (the UI renders plain text).
 *
 * @param array  $items    Top-term items, each { name, slug, count }.
 * @param string $taxonomy 'artist' | 'venue' | 'location'.
 * @param int    $blog_id  Events blog ID (for venue/location archive URLs).
 * @return array Items with an added `url` key.
 */
function ec_users_link_top_terms( array $items, string $taxonomy, int $blog_id ): array {
	if ( empty( $items ) ) {
		return $items;
	}

	foreach ( $items as &$item ) {
		$slug        = isset( $item['slug'] ) ? (string) $item['slug'] : '';
		$item['url'] = '';

		if ( '' === $slug ) {
			continue;
		}

		if ( 'artist' === $taxonomy && function_exists( 'extrachill_get_artist_profile_by_slug' ) ) {
			$profile = extrachill_get_artist_profile_by_slug( $slug );
			if ( is_array( $profile ) && ! empty( $profile['permalink'] ) ) {
				$item['url'] = (string) $profile['permalink'];
				continue;
			}
		}

		// Venue / location (and artist fallback): the events-site term archive.
		$item['url'] = ec_users_events_term_archive_url( $slug, $taxonomy, $blog_id );
	}
	unset( $item );

	return $items;
}

/**
 * Build the events-site archive URL for a taxonomy term slug.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @param int    $blog_id  Events blog ID.
 * @return string Archive URL, or '' when unresolvable.
 */
function ec_users_events_term_archive_url( string $slug, string $taxonomy, int $blog_id ): string {
	if ( '' === $slug || ! $blog_id ) {
		return '';
	}

	$switched     = false;
	$current_blog = get_current_blog_id();
	if ( $current_blog !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return '';
		}
		$link = get_term_link( $term, $taxonomy );
		return is_wp_error( $link ) ? '' : (string) $link;
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

// ─── Event Attendees ─────────────────────────────────────────────────────────

/**
 * Get users who marked an event.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: current blog).
 * @param int $limit Max users to return. Default 10.
 * @return array Array of user data.
 */
function ec_users_get_event_attendees( int $event_id, int $blog_id = 0, int $limit = 10 ): array {
	global $wpdb;

	if ( ! $blog_id ) {
		$blog_id = get_current_blog_id();
	}

	$table = extrachill_users_concert_tracking_table_name();

	$user_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT user_id FROM {$table} WHERE event_id = %d AND blog_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$event_id,
			$blog_id,
			$limit
		)
	);

	$attendees = array();
	foreach ( $user_ids as $uid ) {
		$user = get_user_by( 'id', (int) $uid );
		if ( ! $user ) {
			continue;
		}

		$profile_url = function_exists( 'extrachill_get_user_community_profile_url' )
			? (string) extrachill_get_user_community_profile_url( (int) $user->ID )
			: '';

		$attendees[] = array(
			'user_id'      => (int) $user->ID,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
			'profile_url'  => $profile_url,
		);
	}

	return $attendees;
}

// ─── Search Past Events for Marking ──────────────────────────────────────────

/**
 * Search past events for the "Add Past Shows" My Shows feature.
 *
 * Returns canonically past events matching the query against:
 *   - event post title
 *   - artist taxonomy term names
 *   - venue taxonomy term names
 *
 * Empty query returns no results. The frontend should render a prompt
 * encouraging the user to type a query. See extrachill-events#130 — the
 * previous default of "most recent past events" pretended to be relevant
 * suggestions but was just the global event firehose with zero relation
 * to the user.
 *
 * Each returned event includes `is_marked` for the given user so the UI can
 * render a "+ Mark Attended" / "✓ Tracked" state immediately.
 *
 * @param int   $user_id User ID (for is_marked computation).
 * @param array $args {
 *     Search arguments.
 *     @type string $query     Search query. Empty returns no results.
 *     @type int    $page      1-indexed page number. Default 1.
 *     @type int    $per_page  Results per page. Default 20.
 *     @type int    $blog_id   Blog ID. Defaults to events blog.
 * }
 * @return array{ events: array, total: int, pages: int, page: int }
 */
function ec_users_search_events_for_marking( int $user_id, array $args = array() ): array {
	global $wpdb;

	$defaults = array(
		'query'    => '',
		'page'     => 1,
		'per_page' => 20,
		'blog_id'  => 0,
	);

	$args = wp_parse_args( $args, $defaults );

	$query = trim( (string) $args['query'] );
	$page  = max( 1, (int) $args['page'] );

	// When the query is empty, return no results. The 'most recent past events'
	// the old default returned had zero relation to the user — it pretended to be
	// 'suggestions' but was just the global event firehose. The frontend renders
	// an InlineStatus prompt instead. See extrachill-events#130.
	if ( '' === $query ) {
		return array(
			'events' => array(),
			'total'  => 0,
			'pages'  => 0,
			'page'   => $page,
		);
	}

	$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
	$blog_id  = $args['blog_id'] ? (int) $args['blog_id'] : ec_users_get_events_blog_id();

	$events_prefix = $wpdb->get_blog_prefix( $blog_id );
	$posts_table   = $events_prefix . 'posts';
	$dates_table   = $events_prefix . 'datamachine_event_dates';
	$tr_table      = $events_prefix . 'term_relationships';
	$tt_table      = $events_prefix . 'term_taxonomy';
	$terms_table   = $events_prefix . 'terms';

	$now_mysql   = ec_users_get_events_now( $blog_id );
	$past        = ec_users_build_event_timing_condition( 'past', $now_mysql );
	$timing_case = ec_users_build_event_timing_case( $now_mysql );

	$where   = array(
		"p.post_type = 'data_machine_events'",
		"p.post_status = 'publish'",
		$past['where'],
	);
	$prepare = $past['prepare'];

	// Build search clause if query provided.
	if ( '' !== $query ) {
		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// Subquery: term-ids whose names match the query, restricted to artist/venue.
		// We use IN (...) against tr.term_taxonomy_id via a join in OR clause.
		$where[]   = '(p.post_title LIKE %s OR EXISTS (
			SELECT 1
			FROM ' . $tr_table . ' tr2
			INNER JOIN ' . $tt_table . ' tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
			INNER JOIN ' . $terms_table . ' t2 ON tt2.term_id = t2.term_id
			WHERE tr2.object_id = p.ID
			  AND tt2.taxonomy IN (\'artist\', \'venue\')
			  AND t2.name LIKE %s
		))';
		$prepare[] = $like;
		$prepare[] = $like;
	}

	$where_sql = implode( ' AND ', $where );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names from trusted prefix; placeholders inside $where_sql matched in $prepare.
	$count_sql = $wpdb->prepare(
		"SELECT COUNT(DISTINCT p.ID)
		FROM {$posts_table} p
		INNER JOIN {$dates_table} ed ON p.ID = ed.post_id
		WHERE {$where_sql}",
		...$prepare
	);
	$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	// phpcs:enable

	$pages  = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
	$page   = $pages > 0 ? min( $page, $pages ) : 1;
	$offset = ( $page - 1 ) * $per_page;

	if ( 0 === $total ) {
		return array(
			'events' => array(),
			'total'  => 0,
			'pages'  => 0,
			'page'   => $page,
		);
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names from trusted prefix; placeholders inside $where_sql matched in $prepare.
	$rows_sql = $wpdb->prepare(
		"SELECT p.ID AS post_id, p.post_title, DATE(ed.start_datetime) AS event_date, {$timing_case['sql']} AS timing
		FROM {$posts_table} p
		INNER JOIN {$dates_table} ed ON p.ID = ed.post_id
		WHERE {$where_sql}
		GROUP BY p.ID
		ORDER BY ed.start_datetime DESC
		LIMIT %d OFFSET %d",
		...array_merge( $timing_case['prepare'], $prepare, array( $per_page, $offset ) )
	);
	$rows     = $wpdb->get_results( $rows_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	// phpcs:enable

	if ( empty( $rows ) ) {
		return array(
			'events' => array(),
			'total'  => $total,
			'pages'  => $pages,
			'page'   => $page,
		);
	}

	// Switch to events blog for taxonomy queries and is_marked checks (blog_id scoped).
	$switched     = false;
	$current_blog = get_current_blog_id();
	if ( $current_blog !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$events = array();
	try {
		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$enriched              = ec_users_build_show_data( $post, $row );
			$enriched['post_id']   = $post_id;
			$enriched['is_marked'] = $user_id > 0
				? ec_users_is_event_marked( $user_id, $post_id, $blog_id )
				: false;

			$events[] = $enriched;
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return array(
		'events' => $events,
		'total'  => $total,
		'pages'  => $pages,
		'page'   => $page,
	);
}
