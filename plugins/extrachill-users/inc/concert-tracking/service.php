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
 * Mark an event for a user.
 *
 * Inserts a record if it doesn't exist. No-op if already marked.
 *
 * @param int $user_id User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: current blog).
 * @return bool True if newly marked, false if already existed.
 */
function ec_users_mark_event( int $user_id, int $event_id, int $blog_id = 0 ): bool {
	global $wpdb;

	if ( ! $blog_id ) {
		$blog_id = get_current_blog_id();
	}

	$table = extrachill_users_concert_tracking_table_name();

	// Check if already marked.
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND event_id = %d AND blog_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$user_id,
			$event_id,
			$blog_id
		)
	);

	if ( $exists ) {
		return false;
	}

	$wpdb->insert(
		$table,
		array(
			'user_id'    => $user_id,
			'event_id'   => $event_id,
			'blog_id'    => $blog_id,
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%d', '%s' )
	);

	return (bool) $wpdb->insert_id;
}

/**
 * Unmark an event for a user.
 *
 * @param int $user_id User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: current blog).
 * @return bool True if removed, false if didn't exist.
 */
function ec_users_unmark_event( int $user_id, int $event_id, int $blog_id = 0 ): bool {
	global $wpdb;

	if ( ! $blog_id ) {
		$blog_id = get_current_blog_id();
	}

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

	return false !== $deleted && $deleted > 0;
}

/**
 * Toggle an event mark for a user.
 *
 * Marks if unmarked, unmarks if marked.
 *
 * @param int $user_id User ID.
 * @param int $event_id Event post ID.
 * @param int $blog_id Blog ID (default: current blog).
 * @return array{ marked: bool } New state.
 */
function ec_users_toggle_event( int $user_id, int $event_id, int $blog_id = 0 ): array {
	if ( ! $blog_id ) {
		$blog_id = get_current_blog_id();
	}

	if ( ec_users_is_event_marked( $user_id, $event_id, $blog_id ) ) {
		ec_users_unmark_event( $user_id, $event_id, $blog_id );
		return array( 'marked' => false );
	}

	ec_users_mark_event( $user_id, $event_id, $blog_id );
	return array( 'marked' => true );
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

// ─── Event Timing ────────────────────────────────────────────────────────────

/**
 * Determine the timing state of an event.
 *
 * @param int $event_id Event post ID.
 * @return string 'upcoming' | 'ongoing' | 'past'
 */
function ec_users_get_event_timing( int $event_id ): string {
	$event_date  = get_post_meta( $event_id, '_event_date', true );
	$event_start = get_post_meta( $event_id, '_event_start_time', true );
	$event_end   = get_post_meta( $event_id, '_event_end_time', true );

	if ( ! $event_date ) {
		return 'past';
	}

	$now            = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- needed for timezone-aware comparison.
	$start_string   = $event_date . ' ' . ( $event_start ?: '00:00:00' );
	$event_start_ts = strtotime( $start_string );

	if ( ! $event_start_ts ) {
		return 'past';
	}

	// Default 4-hour show window if no end time specified.
	$event_end_ts = $event_end
		? strtotime( $event_date . ' ' . $event_end )
		: $event_start_ts + ( 4 * HOUR_IN_SECONDS );

	// Handle overnight shows (end time is earlier than start time = next day).
	if ( $event_end_ts && $event_end_ts < $event_start_ts ) {
		$event_end_ts += DAY_IN_SECONDS;
	}

	if ( $now < $event_start_ts ) {
		return 'upcoming';
	}

	if ( $now >= $event_start_ts && $now <= $event_end_ts ) {
		return 'ongoing';
	}

	return 'past';
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

	$table      = extrachill_users_concert_tracking_table_name();
	$blog_id    = $args['blog_id'] ?: ( function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7 );
	$events_prefix = $wpdb->get_blog_prefix( $blog_id );

	// Build WHERE clauses.
	$where   = array( 'ct.user_id = %d', 'ct.blog_id = %d' );
	$prepare = array( $user_id, $blog_id );

	// Date filtering via event post meta.
	$now_date = current_time( 'Y-m-d' );

	if ( 'upcoming' === $args['period'] ) {
		$where[]   = 'pm_date.meta_value >= %s';
		$prepare[] = $now_date;
	} elseif ( 'past' === $args['period'] ) {
		$where[]   = 'pm_date.meta_value < %s';
		$prepare[] = $now_date;
	}

	if ( $args['year'] ) {
		$where[]   = 'YEAR(pm_date.meta_value) = %d';
		$prepare[] = (int) $args['year'];
	}

	if ( $args['date_from'] ) {
		$where[]   = 'pm_date.meta_value >= %s';
		$prepare[] = sanitize_text_field( $args['date_from'] );
	}

	if ( $args['date_to'] ) {
		$where[]   = 'pm_date.meta_value <= %s';
		$prepare[] = sanitize_text_field( $args['date_to'] );
	}

	$where_sql = implode( ' AND ', $where );
	$order_sql = $args['order'];

	// Count total matching records.
	$count_sql = $wpdb->prepare(
		"SELECT COUNT(*)
		FROM {$table} ct
		INNER JOIN {$events_prefix}postmeta pm_date ON ct.event_id = pm_date.post_id AND pm_date.meta_key = '_event_date'
		WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted helpers, where_sql built from %d/%s placeholders.
		...$prepare
	);

	$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	$pages = $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 1;
	$page  = max( 1, min( (int) $args['page'], $pages ?: 1 ) );

	$offset = ( $page - 1 ) * $args['per_page'];

	// Fetch event IDs with date ordering.
	$query = $wpdb->prepare(
		"SELECT ct.event_id, ct.created_at AS marked_at, pm_date.meta_value AS event_date
		FROM {$table} ct
		INNER JOIN {$events_prefix}postmeta pm_date ON ct.event_id = pm_date.post_id AND pm_date.meta_key = '_event_date'
		WHERE {$where_sql}
		ORDER BY pm_date.meta_value {$order_sql}
		LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from trusted helpers, where_sql built from prepare placeholders, order_sql validated against whitelist.
		...array_merge( $prepare, array( $args['per_page'], $offset ) )
	);

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
	$shows          = array();
	$switched       = false;
	$current_blog   = get_current_blog_id();

	if ( $current_blog !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	try {
		foreach ( $rows as $row ) {
			$event_id = (int) $row['event_id'];
			$post     = get_post( $event_id );

			if ( ! $post ) {
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

	// Venue (first term).
	$venue      = null;
	$venue_terms = wp_get_post_terms( $event_id, 'venue', array( 'number' => 1 ) );
	if ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) {
		$venue = array(
			'name' => $venue_terms[0]->name,
			'slug' => $venue_terms[0]->slug,
		);
	}

	// Location (city — deepest term).
	$city           = null;
	$location_terms = wp_get_post_terms( $event_id, 'location' );
	if ( ! is_wp_error( $location_terms ) && ! empty( $location_terms ) ) {
		// Use the deepest (most specific) location term.
		$deepest = null;
		$max_depth = -1;
		foreach ( $location_terms as $term ) {
			$depth = 0;
			$parent = $term->parent;
			while ( $parent ) {
				++$depth;
				$parent_term = get_term( $parent, 'location' );
				$parent = $parent_term && ! is_wp_error( $parent_term ) ? $parent_term->parent : 0;
			}
			if ( $depth > $max_depth ) {
				$max_depth = $depth;
				$deepest   = $term;
			}
		}
		if ( $deepest ) {
			$city = array(
				'name' => $deepest->name,
				'slug' => $deepest->slug,
			);
		}
	}

	// Artists.
	$artists      = array();
	$artist_terms = wp_get_post_terms( $event_id, 'artist' );
	if ( ! is_wp_error( $artist_terms ) ) {
		foreach ( $artist_terms as $term ) {
			$artists[] = array(
				'name' => $term->name,
				'slug' => $term->slug,
			);
		}
	}

	return array(
		'event_id'   => $event_id,
		'title'      => $post->post_title,
		'event_date' => $row['event_date'] ?? get_post_meta( $event_id, '_event_date', true ),
		'event_time' => get_post_meta( $event_id, '_event_start_time', true ),
		'venue'      => $venue,
		'city'       => $city,
		'artists'    => $artists,
		'timing'     => ec_users_get_event_timing( $event_id ),
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

	$blog_id       = ! empty( $args['blog_id'] ) ? (int) $args['blog_id'] : ( function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : 7 );
	$table         = extrachill_users_concert_tracking_table_name();
	$events_prefix = $wpdb->get_blog_prefix( $blog_id );

	// Base WHERE for this user + blog.
	$where   = array( 'ct.user_id = %d', 'ct.blog_id = %d' );
	$prepare = array( $user_id, $blog_id );

	if ( ! empty( $args['year'] ) ) {
		$where[]   = 'YEAR(pm_date.meta_value) = %d';
		$prepare[] = (int) $args['year'];
	}
	if ( ! empty( $args['date_from'] ) ) {
		$where[]   = 'pm_date.meta_value >= %s';
		$prepare[] = sanitize_text_field( $args['date_from'] );
	}
	if ( ! empty( $args['date_to'] ) ) {
		$where[]   = 'pm_date.meta_value <= %s';
		$prepare[] = sanitize_text_field( $args['date_to'] );
	}

	$where_sql = implode( ' AND ', $where );

	// Total shows.
	$total_shows = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table} ct
			INNER JOIN {$events_prefix}postmeta pm_date ON ct.event_id = pm_date.post_id AND pm_date.meta_key = '_event_date'
			WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$prepare
		)
	);

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
	$event_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ct.event_id
			FROM {$table} ct
			INNER JOIN {$events_prefix}postmeta pm_date ON ct.event_id = pm_date.post_id AND pm_date.meta_key = '_event_date'
			WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$prepare
		)
	);

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

		// Unique venues.
		$unique_venues = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'venue'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- event IDs are integers cast above, table names from trusted prefix.
		);

		// Unique artists.
		$unique_artists = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'artist'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);

		// Unique cities (location taxonomy).
		$unique_cities = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT tt.term_id)
			FROM {$term_relationships} tr
			INNER JOIN {$term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tr.object_id IN ({$event_ids_csv}) AND tt.taxonomy = 'location' AND tt.parent != 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- only count children (cities), not countries/states.
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
			LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
			LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
			LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	// Shows by year.
	$shows_by_year_raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT YEAR(pm_date.meta_value) AS yr, COUNT(*) AS count
			FROM {$table} ct
			INNER JOIN {$events_prefix}postmeta pm_date ON ct.event_id = pm_date.post_id AND pm_date.meta_key = '_event_date'
			WHERE {$where_sql}
			GROUP BY yr
			ORDER BY yr DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$prepare
		),
		ARRAY_A
	);

	$shows_by_year = array();
	foreach ( $shows_by_year_raw as $row ) {
		$shows_by_year[ $row['yr'] ] = (int) $row['count'];
	}

	// First and latest show.
	$first_show_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT ct.event_id, pm_date.meta_value AS event_date
			FROM {$table} ct
			INNER JOIN {$events_prefix}postmeta pm_date ON ct.event_id = pm_date.post_id AND pm_date.meta_key = '_event_date'
			WHERE {$where_sql}
			ORDER BY pm_date.meta_value ASC
			LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$prepare
		),
		ARRAY_A
	);

	$latest_show_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT ct.event_id, pm_date.meta_value AS event_date
			FROM {$table} ct
			INNER JOIN {$events_prefix}postmeta pm_date ON ct.event_id = pm_date.post_id AND pm_date.meta_key = '_event_date'
			WHERE {$where_sql}
			ORDER BY pm_date.meta_value DESC
			LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$prepare
		),
		ARRAY_A
	);

	$first_show  = null;
	$latest_show = null;

	if ( $first_show_row ) {
		$post = get_post( (int) $first_show_row['event_id'] );
		if ( $post ) {
			$first_show = array(
				'event_id' => $post->ID,
				'title'    => $post->post_title,
				'date'     => $first_show_row['event_date'],
			);
		}
	}

	if ( $latest_show_row ) {
		$post = get_post( (int) $latest_show_row['event_id'] );
		if ( $post ) {
			$latest_show = array(
				'event_id' => $post->ID,
				'title'    => $post->post_title,
				'date'     => $latest_show_row['event_date'],
			);
		}
	}

	// Cast counts to int in top arrays.
	$cast_counts = function( $items ) {
		return array_map(
			function( $item ) {
				$item['count'] = (int) $item['count'];
				return $item;
			},
			$items ?: array()
		);
	};

	return array(
		'total_shows'    => $total_shows,
		'unique_venues'  => $unique_venues,
		'unique_artists' => $unique_artists,
		'unique_cities'  => $unique_cities,
		'first_show'     => $first_show,
		'latest_show'    => $latest_show,
		'top_artists'    => $cast_counts( $top_artists ),
		'top_venues'     => $cast_counts( $top_venues ),
		'top_cities'     => $cast_counts( $top_cities ),
		'shows_by_year'  => $shows_by_year,
	);
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

		$attendees[] = array(
			'user_id'      => (int) $user->ID,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
		);
	}

	return $attendees;
}
