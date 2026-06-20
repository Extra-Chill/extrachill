<?php
/**
 * Get Search Gaps Ability
 *
 * Read-side ability that surfaces search-quality signal from the first-party
 * analytics events table: the searches users typed that returned zero (or very
 * few) results. A zero-result search is the highest-signal "what content do
 * users want that we don't have" datapoint on the platform — the user literally
 * typed their demand into a box.
 *
 * THE DATA ALREADY EXISTS. `search` events store `result_count` inside
 * `event_data` (e.g. {"search_term":"Molchat Doma","result_count":0}). This
 * ability only READS and aggregates it; nothing new is captured.
 *
 * BOT AWARENESS (required): the raw `search` total includes scanner / injection
 * spam. Two layers of defense keep junk out of the report:
 *   1. Insert-time partitioning already routes recognized payloads to a
 *      separate event_type='search_attack' (see security-classifier.php). This
 *      ability queries event_type='search' only, so anything already classified
 *      is excluded for free.
 *   2. The canonical visitor classifier stamps an `is_bot` flag on every event
 *      at write time (issue #57); this ability excludes rows flagged bot.
 *   3. An OPTIONAL visitor_id gate (filter
 *      `extrachill_analytics_search_gaps_require_visitor_id`, default OFF)
 *      excludes legacy programmatic searches that the OLD, now-removed search
 *      rule wrongly stamped human (the #51 root cause). It defaults off because
 *      on this install search events rarely carry a stitched visitor_id even
 *      for humans; see the inline note for the measured tradeoff.
 *   4. Read-time filtering catches residual junk that slipped through before
 *      the classifier existed (or that the classifier doesn't fire on):
 *        - terms longer than the human-plausible ceiling (default 60 chars),
 *        - terms that the shared security classifier flags as a payload
 *          (SQLi / XSS / path-traversal / scanner markers), reusing the exact
 *          same catalog the insert path uses so the two stay in lockstep.
 * Only plausibly-human terms survive into the returned report.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_search_gaps_ability' );

/**
 * Maximum plausibly-human search term length. Anything longer is treated as
 * scanner/payload junk and excluded from the report.
 */
if ( ! defined( 'EXTRACHILL_ANALYTICS_SEARCH_GAP_MAX_TERM_LENGTH' ) ) {
	define( 'EXTRACHILL_ANALYTICS_SEARCH_GAP_MAX_TERM_LENGTH', 60 );
}

/**
 * Register the get-search-gaps ability.
 */
function extrachill_analytics_register_search_gaps_ability() {
	wp_register_ability(
		'extrachill/get-search-gaps',
		array(
			'label'               => __( 'Get Search Gaps', 'extrachill-analytics' ),
			'description'         => __( 'Returns bot-filtered zero-result and low-result on-site search terms over a window — a content-demand report computed from existing search analytics.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'        => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'limit'       => array(
						'type'        => 'integer',
						'description' => __( 'Maximum terms to return per bucket. 0 for unlimited.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'max_results' => array(
						'type'        => 'integer',
						'description' => __( 'Result-count ceiling that defines a "gap" term. 0 returns only zero-result searches; higher values include low-result near-misses (e.g. 3 = result_count between 1 and 3).', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'blog_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Report with zero_result and low_result term arrays, volume/health totals, and period metadata.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_search_gaps',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for get-search-gaps ability.
 *
 * @param array $input Input parameters.
 * @return array Report data with shape:
 *   [
 *     'zero_result'      => [ ['term' => ..., 'count' => N], ... ],
 *     'low_result'       => [ ['term' => ..., 'count' => N], ... ],  // only when max_results > 0
 *     'total_searches'   => int,   // all human search events in window
 *     'zero_result_total'=> int,   // distinct-agnostic count of zero-result human searches
 *     'zero_result_rate' => float, // percentage 0..100
 *     'excluded_bot'     => int,   // search events filtered out as bot/payload
 *     'max_results'      => int,
 *     'days'             => int,
 *     'period'           => string,
 *     'since'            => string,  // UTC inclusive lower bound (empty for all-time)
 *     'as_of'            => string,  // UTC instant the report ran
 *   ]
 */
function extrachill_analytics_ability_get_search_gaps( $input ) {
	global $wpdb;

	$days        = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 28;
	$limit       = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 25;
	$max_results = isset( $input['max_results'] ) ? max( 0, (int) $input['max_results'] ) : 0;
	$blog_id     = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	$table  = extrachill_analytics_events_table();
	$where  = array( "event_type = 'search'" );
	$values = array();

	// Reproducible UTC window — created_at is stored in UTC, so bound in UTC too.
	$now_utc = gmdate( 'Y-m-d H:i:s' );
	$since   = '';

	if ( $days > 0 ) {
		$since    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$where[]  = 'created_at >= %s';
		$values[] = $since;
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	// Extract result_count and search_term once; reuse across queries.
	$result_count_expr = "CAST(JSON_EXTRACT(event_data, '$.result_count') AS SIGNED)";
	$term_expr         = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.search_term'))";

	// First-pass SQL bot filter: cheap, deterministic exclusions that don't need
	// the full regex catalog. Drops absurdly long terms and obvious payload
	// markers. The authoritative bot filter is the shared classifier applied in
	// PHP below (see extrachill_analytics_search_gap_is_bot_term); this SQL layer
	// only trims the candidate set so we fetch fewer rows.
	$max_len  = (int) EXTRACHILL_ANALYTICS_SEARCH_GAP_MAX_TERM_LENGTH;
	$where[]  = "{$term_expr} IS NOT NULL";
	$where[]  = "CHAR_LENGTH({$term_expr}) <= %d";
	$values[] = $max_len;

	// Exclude non-human searches flagged at insert time by the canonical
	// classifier (now stamped on EVERY event in extrachill_track_analytics_event;
	// see issue #57). The flag is a JSON boolean, so JSON_EXTRACT returns the
	// literal `true` for bot rows. Rows written before the flag existed have no
	// key (JSON_EXTRACT → NULL) and COALESCE keeps them as non-bot here.
	$where[] = "COALESCE(JSON_EXTRACT(event_data, '$.is_bot'), false) = false";

	// Defense-in-depth for legacy-contaminated rows. The OLD search rule
	// (is_bot = ua_bot || (no-cookie && empty-UA)) — the #51 root cause — wrote
	// is_bot=FALSE for cookieless programmatic searches that carried a normal
	// UA (events pipeline, community lookups spraying real band names). Those
	// rows are already in the DB stamped human, so the is_bot filter above
	// cannot catch them. The strongest human signal that retention also relies
	// on is a present visitor_id: a genuine human reaches the search box only
	// after loading a page that mints the ec_vid cookie.
	//
	// IMPORTANT TRADEOFF (measured against live data, 2026-06-20): on this
	// install search events almost never carry a stitched visitor_id (1 of
	// ~234k over 28 days) even though pageviews do (~91%), because the search
	// volume is overwhelmingly programmatic. A hard visitor_id gate therefore
	// empties the report. That is "correct" in the strict sense (almost none of
	// that traffic is a cookied human) but operationally useless, so the gate is
	// FILTERABLE and defaults OFF. The canonical is_bot stamp (now written on
	// every event) is the primary go-forward fix; flip this filter on once
	// enough cookie-stitched human searches exist to make the gate meaningful.
	//
	// Filter: extrachill_analytics_search_gaps_require_visitor_id (bool, default
	// false) — when true, also require a non-empty visitor_id.
	$require_visitor_id = (bool) apply_filters( 'extrachill_analytics_search_gaps_require_visitor_id', false );
	if ( $require_visitor_id ) {
		$where[] = "visitor_id IS NOT NULL AND visitor_id != ''";
	}

	$where_clause = implode( ' AND ', $where );
	$where_values = $values;

	// Read-only aggregation over a custom analytics table — the table name and
	// JSON-extract expressions are interpolated from trusted, code-defined
	// constants (never request input), and every value bound through the WHERE
	// clause flows through $wpdb->prepare(). Caching is intentionally skipped:
	// this is an on-demand admin/CLI report, not a hot path.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Total human-plausible searches in window (after the length filter AND the
	// endpoint-automation `is_bot` exclusion in $where; the term-classifier
	// exclusion is applied per-term below for the buckets, and reflected via
	// excluded_bot). Because the is_bot filter lives in the shared $where, it
	// trims the denominator and the buckets identically, keeping
	// zero_result_rate honest.
	$total_sql      = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
	$total_searches = empty( $where_values )
		? (int) $wpdb->get_var( $total_sql )
		: (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $where_values ) );

	// Grouped gap terms. Each term is bucketed by its BEST result (MIN): a term
	// is "zero-result" if it ever returned nothing, "low-result" otherwise. The
	// per-bucket COUNT is exact, not the term's total search volume: zero_cnt
	// counts only the searches that returned exactly 0, and low_cnt only the
	// searches that returned 1..max_results. This keeps zero_result_total a true
	// "searches that returned nothing" figure even when max_results > 0 pulls a
	// term's other (low-result) searches into the same GROUP BY.
	$gap_where        = $where;
	$gap_where[]      = "{$result_count_expr} >= 0"; // Defensive: ignore malformed/negative counts.
	$gap_where[]      = "{$result_count_expr} <= %d";
	$gap_values       = $where_values;
	$gap_values[]     = $max_results;
	$gap_where_clause = implode( ' AND ', $gap_where );

	$zero_cnt_expr = "SUM(CASE WHEN {$result_count_expr} = 0 THEN 1 ELSE 0 END)";
	$low_cnt_expr  = "SUM(CASE WHEN {$result_count_expr} > 0 THEN 1 ELSE 0 END)";

	$gap_sql = "SELECT {$term_expr} AS term, MIN({$result_count_expr}) AS min_results, "
		. "{$zero_cnt_expr} AS zero_cnt, {$low_cnt_expr} AS low_cnt, COUNT(*) AS cnt "
		. "FROM {$table} WHERE {$gap_where_clause} "
		. 'GROUP BY term ORDER BY cnt DESC';

	$gap_rows = empty( $gap_values )
		? $wpdb->get_results( $gap_sql )
		: $wpdb->get_results( $wpdb->prepare( $gap_sql, $gap_values ) );

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$zero_result  = array();
	$low_result   = array();
	$excluded_bot = 0;
	$zero_total   = 0;

	foreach ( $gap_rows as $row ) {
		$term = (string) $row->term;

		// Authoritative read-time bot filter: the SAME classifier the insert path
		// uses. Anything it flags is scanner/injection junk, not human demand.
		if ( extrachill_analytics_search_gap_is_bot_term( $term ) ) {
			$excluded_bot += (int) $row->cnt;
			continue;
		}

		$min_results = (int) $row->min_results;
		$zero_cnt    = (int) $row->zero_cnt;
		$low_cnt     = (int) $row->low_cnt;

		if ( 0 === $min_results ) {
			$zero_total   += $zero_cnt;
			$zero_result[] = array(
				'term'  => $term,
				'count' => $zero_cnt,
			);
		} else {
			$low_result[] = array(
				'term'        => $term,
				'count'       => $low_cnt,
				'min_results' => $min_results,
			);
		}
	}

	// Re-sort each bucket by its OWN count (descending). The SQL ordered by total
	// term volume so a single scan covers both buckets; the bucket-specific
	// counts can reorder the top of each list.
	$sort_by_count = static function ( $a, $b ) {
		return $b['count'] <=> $a['count'];
	};
	usort( $zero_result, $sort_by_count );
	usort( $low_result, $sort_by_count );

	// Apply per-bucket limit AFTER bot filtering so junk never occupies a slot.
	$zero_result_distinct = count( $zero_result );
	$low_result_distinct  = count( $low_result );

	if ( $limit > 0 ) {
		$zero_result = array_slice( $zero_result, 0, $limit );
		$low_result  = array_slice( $low_result, 0, $limit );
	}

	$zero_result_rate = $total_searches > 0
		? round( ( $zero_total / $total_searches ) * 100, 2 )
		: 0.0;

	$report = array(
		'zero_result'          => $zero_result,
		'zero_result_distinct' => $zero_result_distinct,
		'total_searches'       => $total_searches,
		'zero_result_total'    => $zero_total,
		'zero_result_rate'     => $zero_result_rate,
		'excluded_bot'         => $excluded_bot,
		'max_results'          => $max_results,
		'days'                 => $days,
		'period'               => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'since'                => $since,
		'as_of'                => $now_utc,
	);

	// Only surface the low-result bucket when the caller asked for near-misses.
	if ( $max_results > 0 ) {
		$report['low_result']          = $low_result;
		$report['low_result_distinct'] = $low_result_distinct;
	}

	return $report;
}

/**
 * Read-time bot/payload test for a candidate search term.
 *
 * Delegates to the shared security classifier so the read path and the insert
 * path use one catalog. Returns true when the term looks like scanner /
 * injection junk and must be excluded from the human-demand report.
 *
 * @param string $term Raw search term.
 * @return bool True if the term is bot/payload junk.
 */
function extrachill_analytics_search_gap_is_bot_term( $term ) {
	$term = (string) $term;

	if ( '' === $term ) {
		return true;
	}

	// Length ceiling — defense-in-depth even though the SQL layer also trims it.
	if ( strlen( $term ) > (int) EXTRACHILL_ANALYTICS_SEARCH_GAP_MAX_TERM_LENGTH ) {
		return true;
	}

	// Shared classifier: SQLi / XSS / path-traversal / scanner-marker payloads.
	if ( function_exists( 'extrachill_analytics_classify_search_payload' ) ) {
		if ( null !== extrachill_analytics_classify_search_payload( $term ) ) {
			return true;
		}
	}

	return false;
}
