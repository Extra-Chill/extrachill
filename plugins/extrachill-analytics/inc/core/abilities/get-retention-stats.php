<?php
/**
 * Get Retention Stats Ability
 *
 * Read-side ability that answers the platform's hardest question deterministically:
 * *do people come back?* It computes return rate, cohort-retention curves,
 * cross-site return, and session depth from the first-party `pageview` events in
 * `c8c_extrachill_analytics_events`, keyed on the anonymous `visitor_id`.
 *
 * Why this is deterministic and bot-resistant:
 *
 *   `pageview` rows are written server-side by the track-page-view ability ONLY
 *   for real, JS-executing browsers (the beacon fires post-load) and ONLY when
 *   the request is not a known bot (user-agent filter, mirroring the 404 path).
 *   The visitor_id is a random first-party UUID v4 — no PII, no fingerprint —
 *   so counting distinct visitor_ids across distinct days is a clean,
 *   reproducible retention signal that GA4's sampled/bot-inflated numbers cannot
 *   match. Rows with a NULL visitor_id (opted-out via GPC/DNT, or pre-cookie)
 *   are simply excluded from the per-visitor metrics — they never inflate or
 *   deflate a retention ratio.
 *
 * All four metrics operate over a UTC window and are plain aggregate queries:
 * no normalization, no sampling, no dedup beyond the explicit DISTINCT/day logic
 * each metric documents.
 *
 * @package ExtraChill\Analytics
 * @since 0.11.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-retention-stats ability.
 */
function extrachill_analytics_register_retention_stats_ability() {
	wp_register_ability(
		'extrachill/get-retention-stats',
		array(
			'label'               => __( 'Get Retention Stats', 'extrachill-analytics' ),
			'description'         => __( 'Returns deterministic, bot-filtered visitor-retention metrics (return rate, cohort retention, cross-site return, session depth) from first-party pageview events.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'         => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back for the window. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for network-wide (required for cross-site return).', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'cohort_weeks' => array(
						'type'        => 'integer',
						'description' => __( 'Number of weekly cohorts to compute for the retention curve. Default 8.', 'extrachill-analytics' ),
						'default'     => 8,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with return_rate, cohort_retention, cross_site_return, session_depth, and the exact UTC window.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_retention_stats',
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
 * Execute callback for get-retention-stats ability.
 *
 * @param array $input Input parameters.
 * @return array Retention metrics.
 */
function extrachill_analytics_ability_get_retention_stats( $input ) {
	global $wpdb;

	$days         = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 28;
	$blog_id      = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$cohort_weeks = isset( $input['cohort_weeks'] ) ? max( 1, (int) $input['cohort_weeks'] ) : 8;

	$table      = extrachill_analytics_events_table();
	$event_type = defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview';

	$now_utc = gmdate( 'Y-m-d H:i:s' );
	$since   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	// Common WHERE: pageviews, in window, with a non-NULL visitor_id (opted-out
	// rows are excluded from per-visitor metrics), optional blog filter.
	$base_where  = array( 'event_type = %s', "visitor_id IS NOT NULL AND visitor_id != ''", 'created_at >= %s' );
	$base_values = array( $event_type, $since );

	if ( $blog_id > 0 ) {
		$base_where[]  = 'blog_id = %d';
		$base_values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $base_where );

	// ---------------------------------------------------------------------
	// (a) Return rate: % of visitors active on >= 2 distinct UTC days.
	// ---------------------------------------------------------------------
	$return_sql = "SELECT
			COUNT(*) AS total_visitors,
			SUM(CASE WHEN active_days >= 2 THEN 1 ELSE 0 END) AS returning_visitors
		FROM (
			SELECT visitor_id, COUNT(DISTINCT DATE(created_at)) AS active_days
			FROM {$table}
			WHERE {$where_clause}
			GROUP BY visitor_id
		) AS per_visitor";

	$return_row = $wpdb->get_row( $wpdb->prepare( $return_sql, $base_values ) );

	$total_visitors     = $return_row ? (int) $return_row->total_visitors : 0;
	$returning_visitors = $return_row ? (int) $return_row->returning_visitors : 0;
	$return_rate        = $total_visitors > 0 ? round( $returning_visitors / $total_visitors, 4 ) : 0.0;

	// ---------------------------------------------------------------------
	// (b) Cohort retention: of visitors first-seen in week N, what % return in
	// a later week (N+1, N+2, ...). "Week" is the ISO week of the visitor's
	// first-ever pageview within the window. We compute, per cohort, the
	// share still active 1 and 2 weeks later.
	// ---------------------------------------------------------------------
	$cohort_since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$cohort_weeks} weeks" ) );

	$cohort_where  = array( 'event_type = %s', "visitor_id IS NOT NULL AND visitor_id != ''", 'created_at >= %s' );
	$cohort_values = array( $event_type, $cohort_since );
	if ( $blog_id > 0 ) {
		$cohort_where[]  = 'blog_id = %d';
		$cohort_values[] = $blog_id;
	}
	$cohort_where_clause = implode( ' AND ', $cohort_where );

	// Per visitor: their first-seen week, and the full set of weeks they were active.
	$cohort_sql = "SELECT
			first_week,
			COUNT(*) AS cohort_size,
			SUM(CASE WHEN max_week >= first_week + 1 THEN 1 ELSE 0 END) AS returned_w1,
			SUM(CASE WHEN max_week >= first_week + 2 THEN 1 ELSE 0 END) AS returned_w2
		FROM (
			SELECT
				visitor_id,
				MIN(YEARWEEK(created_at, 3)) AS first_week,
				MAX(YEARWEEK(created_at, 3)) AS max_week
			FROM {$table}
			WHERE {$cohort_where_clause}
			GROUP BY visitor_id
		) AS v
		GROUP BY first_week
		ORDER BY first_week ASC";

	$cohort_rows = $wpdb->get_results( $wpdb->prepare( $cohort_sql, $cohort_values ) );

	$cohort_retention = array();
	foreach ( (array) $cohort_rows as $row ) {
		$size               = (int) $row->cohort_size;
		$cohort_retention[] = array(
			'cohort_week'  => (string) $row->first_week,
			'cohort_size'  => $size,
			'returned_w1'  => (int) $row->returned_w1,
			'returned_w2'  => (int) $row->returned_w2,
			'retention_w1' => $size > 0 ? round( (int) $row->returned_w1 / $size, 4 ) : 0.0,
			'retention_w2' => $size > 0 ? round( (int) $row->returned_w2 / $size, 4 ) : 0.0,
		);
	}

	// ---------------------------------------------------------------------
	// (c) Cross-site return: visitors who hit >= 2 distinct blog_ids on
	// different UTC days. Only meaningful network-wide, so this ignores the
	// blog_id filter by design (the question is inherently cross-site).
	// ---------------------------------------------------------------------
	$xsite_where        = array( 'event_type = %s', "visitor_id IS NOT NULL AND visitor_id != ''", 'created_at >= %s' );
	$xsite_values       = array( $event_type, $since );
	$xsite_where_clause = implode( ' AND ', $xsite_where );

	$xsite_sql = "SELECT
			COUNT(*) AS total_visitors,
			SUM(CASE WHEN distinct_sites >= 2 AND distinct_days >= 2 THEN 1 ELSE 0 END) AS cross_site_visitors
		FROM (
			SELECT
				visitor_id,
				COUNT(DISTINCT blog_id) AS distinct_sites,
				COUNT(DISTINCT DATE(created_at)) AS distinct_days
			FROM {$table}
			WHERE {$xsite_where_clause}
			GROUP BY visitor_id
		) AS per_visitor";

	$xsite_row = $wpdb->get_row( $wpdb->prepare( $xsite_sql, $xsite_values ) );

	$xsite_total     = $xsite_row ? (int) $xsite_row->total_visitors : 0;
	$xsite_visitors  = $xsite_row ? (int) $xsite_row->cross_site_visitors : 0;
	$cross_site_rate = $xsite_total > 0 ? round( $xsite_visitors / $xsite_total, 4 ) : 0.0;

	// ---------------------------------------------------------------------
	// (d) Session depth: average pageview events per visitor per active day.
	// ---------------------------------------------------------------------
	$depth_sql = "SELECT
			AVG(views_per_day) AS avg_depth,
			MAX(views_per_day) AS max_depth
		FROM (
			SELECT visitor_id, DATE(created_at) AS day, COUNT(*) AS views_per_day
			FROM {$table}
			WHERE {$where_clause}
			GROUP BY visitor_id, DATE(created_at)
		) AS per_visitor_day";

	$depth_row = $wpdb->get_row( $wpdb->prepare( $depth_sql, $base_values ) );

	$avg_depth = $depth_row && null !== $depth_row->avg_depth ? round( (float) $depth_row->avg_depth, 2 ) : 0.0;
	$max_depth = $depth_row ? (int) $depth_row->max_depth : 0;

	return array(
		'return_rate'       => array(
			'total_visitors'     => $total_visitors,
			'returning_visitors' => $returning_visitors,
			'rate'               => $return_rate,
			'definition'         => 'Share of visitors active on >= 2 distinct UTC days within the window.',
		),
		'cohort_retention'  => array(
			'cohorts'    => $cohort_retention,
			'weeks'      => $cohort_weeks,
			'definition' => 'Per weekly first-seen cohort (ISO YEARWEEK), share still active 1 and 2 weeks later.',
		),
		'cross_site_return' => array(
			'total_visitors'      => $xsite_total,
			'cross_site_visitors' => $xsite_visitors,
			'rate'                => $cross_site_rate,
			'definition'          => 'Share of visitors who hit >= 2 distinct blog_ids on >= 2 distinct UTC days (network-wide; ignores blog_id filter).',
		),
		'session_depth'     => array(
			'avg_pageviews_per_visitor_day' => $avg_depth,
			'max_pageviews_per_visitor_day' => $max_depth,
			'definition'                    => 'Average (and max) pageview events per visitor per active UTC day.',
		),
		'days'              => $days,
		'blog_id'           => $blog_id,
		'period'            => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' ),
		'since'             => $since,
		'as_of'             => $now_utc,
		'note'              => 'Deterministic + bot-filtered: pageview rows are written server-side only for non-bot, JS-executing browsers; visitor_id is an anonymous first-party UUID v4 (no PII). Opted-out (GPC/DNT) rows have NULL visitor_id and are excluded from per-visitor metrics.',
	);
}
