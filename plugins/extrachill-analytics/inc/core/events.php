<?php
/**
 * Analytics Event Tracking Functions
 *
 * Core functions for tracking and querying analytics events.
 *
 * @package ExtraChill\Analytics
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Track an analytics event.
 *
 * @param string $event_type Event type identifier (e.g., 'newsletter_signup', 'user_registration').
 * @param array  $event_data Flexible payload data stored as JSON.
 * @param string $source_url URL of the page where the event occurred.
 * @param string $visitor_id Optional anonymous first-party visitor UUID (v4). Stored only
 *                           when it is a well-formed UUID v4 and the visitor has not opted
 *                           out via GPC/DNT (callers pass '' in that case). Never PII.
 *                           When omitted/empty, this falls back to the existing `ec_vid`
 *                           cookie (read-only, no minting) so server-side, non-pageview
 *                           events (search, 404, registration, email) stitch to the same
 *                           visitor as pageviews do.
 * @return int|false Event ID on success, false on failure.
 */
function extrachill_track_analytics_event( $event_type, $event_data = array(), $source_url = '', $visitor_id = '' ) {
	global $wpdb;

	if ( empty( $event_type ) ) {
		return false;
	}

	$table_name = extrachill_analytics_events_table();

	// Server-side stitching: when the caller didn't supply a valid visitor id
	// (the pageview JS beacon is the only caller that does), fall back to the
	// existing `ec_vid` cookie via the READ-ONLY resolver. This attaches a
	// visitor_id to non-pageview events (search, 404, registration, email)
	// without minting a new cookie here — minting stays the pageview path's
	// job on the early template_redirect hook. GPC/DNT opt-out is honored by
	// the resolver (returns '' → stored as NULL). Never PII.
	if (
		( ! function_exists( 'extrachill_analytics_is_valid_visitor_id' )
			|| ! extrachill_analytics_is_valid_visitor_id( $visitor_id ) )
		&& function_exists( 'extrachill_analytics_read_visitor_id' )
	) {
		$visitor_id = extrachill_analytics_read_visitor_id();
	}

	// Only persist a valid UUID v4; anything else (including empty / opted-out) is NULL.
	$stored_visitor_id = ( function_exists( 'extrachill_analytics_is_valid_visitor_id' )
		&& extrachill_analytics_is_valid_visitor_id( $visitor_id ) )
		? $visitor_id
		: null;

	// Stamp the canonical human/bot verdict on EVERY event at write time so all
	// downstream readers filter on one trustworthy, consistent flag instead of
	// each re-litigating the question (issue #57). This is the single source of
	// truth — the verdict already factors UA, visitor cookie, and request origin
	// (cli/cron/rest), which is what kills the #51 programmatic-search
	// contamination at the source. The flag is only stamped when the caller
	// hasn't already supplied one, so an explicit upstream classification (none
	// today) would still win. We pass the resolved cookie state so the verdict
	// uses the same visitor_id the row is stored under.
	if ( is_array( $event_data )
		&& ! array_key_exists( 'is_bot', $event_data )
		&& function_exists( 'extrachill_analytics_classify_request' )
	) {
		$verdict              = extrachill_analytics_classify_request(
			array( 'has_visitor_cookie' => null !== $stored_visitor_id )
		);
		$event_data['is_bot'] = (bool) $verdict['is_bot'];
	}

	// Stamp the originating search SURFACE on every `search` event at write
	// time so downstream readers can tell a nav/header search from an
	// archive/results-page refinement from the bbPress in-forum search (issue
	// #86). Like the is_bot stamp above, this is derived server-side from the
	// request context the search write already has — the source_url on the
	// payload plus the live request URI/query vars — so EVERY new search row
	// carries a `source` without any cross-plugin capture change. Only stamped
	// for `search` events, only when the caller hasn't already supplied an
	// explicit source (a future upstream caller threading its own surface
	// still wins), and only when the classifier is loaded.
	if ( 'search' === $event_type
		&& is_array( $event_data )
		&& ! array_key_exists( 'source', $event_data )
		&& function_exists( 'extrachill_analytics_classify_search_source' )
	) {
		$event_data['source'] = extrachill_analytics_classify_search_source( $source_url );
	}

	$result = $wpdb->insert(
		$table_name,
		array(
			'event_type' => sanitize_key( $event_type ),
			'event_data' => wp_json_encode( $event_data ),
			'source_url' => esc_url_raw( $source_url ),
			'blog_id'    => get_current_blog_id(),
			'user_id'    => get_current_user_id() ?: null,
			'visitor_id' => $stored_visitor_id,
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
	);

	if ( false === $result ) {
		error_log( sprintf( 'extrachill_track_analytics_event failed: %s', $wpdb->last_error ) );
		return false;
	}

	return $wpdb->insert_id;
}

/**
 * Query analytics events.
 *
 * @param array $args Query arguments.
 *                    - event_type (string|array): Filter by event type(s).
 *                    - blog_id (int): Filter by blog ID.
 *                    - user_id (int): Filter by user ID.
 *                    - date_from (string): Start date (Y-m-d format).
 *                    - date_to (string): End date (Y-m-d format).
 *                    - search (string): Search within event_data JSON.
 *                    - limit (int): Number of results (default 100).
 *                    - offset (int): Offset for pagination.
 *                    - orderby (string): Column to order by (default 'created_at').
 *                    - order (string): ASC or DESC (default 'DESC').
 * @return array Array of event objects.
 */
function extrachill_get_analytics_events( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'event_type' => '',
		'blog_id'    => 0,
		'user_id'    => 0,
		'date_from'  => '',
		'date_to'    => '',
		'search'     => '',
		'limit'      => 100,
		'offset'     => 0,
		'orderby'    => 'created_at',
		'order'      => 'DESC',
	);

	$args       = wp_parse_args( $args, $defaults );
	$table_name = extrachill_analytics_events_table();
	$where      = array( '1=1' );
	$values     = array();

	if ( ! empty( $args['event_type'] ) ) {
		if ( is_array( $args['event_type'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['event_type'] ), '%s' ) );
			$where[]      = "event_type IN ({$placeholders})";
			$values       = array_merge( $values, array_map( 'sanitize_key', $args['event_type'] ) );
		} else {
			$where[]  = 'event_type = %s';
			$values[] = sanitize_key( $args['event_type'] );
		}
	}

	if ( ! empty( $args['blog_id'] ) ) {
		$where[]  = 'blog_id = %d';
		$values[] = absint( $args['blog_id'] );
	}

	if ( ! empty( $args['user_id'] ) ) {
		$where[]  = 'user_id = %d';
		$values[] = absint( $args['user_id'] );
	}

	if ( ! empty( $args['date_from'] ) ) {
		$where[]  = 'created_at >= %s';
		$values[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
	}

	if ( ! empty( $args['date_to'] ) ) {
		$where[]  = 'created_at <= %s';
		$values[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
	}

	if ( ! empty( $args['search'] ) ) {
		$where[]  = 'event_data LIKE %s';
		$values[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
	}

	$where_clause = implode( ' AND ', $where );

	$allowed_orderby = array( 'id', 'event_type', 'blog_id', 'user_id', 'created_at' );
	$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
	$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

	$limit  = absint( $args['limit'] );
	$offset = absint( $args['offset'] );

	$sql = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

	$values[] = $limit;
	$values[] = $offset;

	if ( count( $values ) > 2 ) {
		$query = $wpdb->prepare( $sql, $values );
	} else {
		$query = $wpdb->prepare( $sql, $limit, $offset );
	}

	$results = $wpdb->get_results( $query );

	foreach ( $results as &$row ) {
		$row->event_data = json_decode( $row->event_data, true );
	}

	return $results;
}

/**
 * Count analytics events matching criteria.
 *
 * @param array $args Query arguments (same as extrachill_get_analytics_events, excluding limit/offset).
 * @return int Total count of matching events.
 */
function extrachill_count_analytics_events( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'event_type' => '',
		'blog_id'    => 0,
		'user_id'    => 0,
		'date_from'  => '',
		'date_to'    => '',
		'search'     => '',
	);

	$args       = wp_parse_args( $args, $defaults );
	$table_name = extrachill_analytics_events_table();
	$where      = array( '1=1' );
	$values     = array();

	if ( ! empty( $args['event_type'] ) ) {
		if ( is_array( $args['event_type'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['event_type'] ), '%s' ) );
			$where[]      = "event_type IN ({$placeholders})";
			$values       = array_merge( $values, array_map( 'sanitize_key', $args['event_type'] ) );
		} else {
			$where[]  = 'event_type = %s';
			$values[] = sanitize_key( $args['event_type'] );
		}
	}

	if ( ! empty( $args['blog_id'] ) ) {
		$where[]  = 'blog_id = %d';
		$values[] = absint( $args['blog_id'] );
	}

	if ( ! empty( $args['user_id'] ) ) {
		$where[]  = 'user_id = %d';
		$values[] = absint( $args['user_id'] );
	}

	if ( ! empty( $args['date_from'] ) ) {
		$where[]  = 'created_at >= %s';
		$values[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
	}

	if ( ! empty( $args['date_to'] ) ) {
		$where[]  = 'created_at <= %s';
		$values[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
	}

	if ( ! empty( $args['search'] ) ) {
		$where[]  = 'event_data LIKE %s';
		$values[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
	}

	$where_clause = implode( ' AND ', $where );

	$sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";

	if ( ! empty( $values ) ) {
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	} else {
		$count = $wpdb->get_var( $sql );
	}

	return (int) $count;
}

/**
 * Get aggregated analytics event statistics.
 *
 * @param string $event_type Event type to aggregate.
 * @param int    $days       Number of days to look back (0 for all time).
 * @param int    $blog_id    Optional blog ID filter (0 for all blogs).
 * @return array Statistics array with total, by_date, by_source, by_context.
 */
function extrachill_get_analytics_event_stats( $event_type, $days = 30, $blog_id = 0 ) {
	global $wpdb;

	$table_name = extrachill_analytics_events_table();
	$where      = array( 'event_type = %s' );
	$values     = array( sanitize_key( $event_type ) );

	if ( $days > 0 ) {
		$where[]  = 'created_at >= %s';
		$values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = absint( $blog_id );
	}

	$where_clause = implode( ' AND ', $where );

	// Total count.
	$total = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}",
			$values
		)
	);

	// By date (last N days).
	$by_date = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE(created_at) as date, COUNT(*) as count 
			FROM {$table_name} 
			WHERE {$where_clause} 
			GROUP BY DATE(created_at) 
			ORDER BY date DESC",
			$values
		)
	);

	// By source URL (top 20).
	$by_source = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT source_url, COUNT(*) as count 
			FROM {$table_name} 
			WHERE {$where_clause} AND source_url != '' 
			GROUP BY source_url 
			ORDER BY count DESC 
			LIMIT 20",
			$values
		)
	);

	// By context (from event_data JSON - MySQL 5.7+ JSON functions).
	$by_context = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.context')) as context, COUNT(*) as count 
			FROM {$table_name} 
			WHERE {$where_clause} AND JSON_EXTRACT(event_data, '$.context') IS NOT NULL 
			GROUP BY context 
			ORDER BY count DESC",
			$values
		)
	);

	return array(
		'total'      => (int) $total,
		'by_date'    => $by_date,
		'by_source'  => $by_source,
		'by_context' => $by_context,
	);
}

/**
 * Get the distinct event types present in the events table.
 *
 * @return string[] Event type identifiers ordered ascending.
 */
function extrachill_get_analytics_event_types() {
	global $wpdb;

	$table_name = extrachill_analytics_events_table();

	$event_types = $wpdb->get_col(
		"SELECT DISTINCT event_type FROM {$table_name} ORDER BY event_type ASC"
	);

	return array_map( 'strval', (array) $event_types );
}

/**
 * Get the distinct blog IDs that have recorded events.
 *
 * @return int[] Blog IDs ordered ascending.
 */
function extrachill_get_analytics_blog_ids() {
	global $wpdb;

	$table_name = extrachill_analytics_events_table();

	$blog_ids = $wpdb->get_col(
		"SELECT DISTINCT blog_id FROM {$table_name} ORDER BY blog_id ASC"
	);

	return array_map( 'absint', (array) $blog_ids );
}
