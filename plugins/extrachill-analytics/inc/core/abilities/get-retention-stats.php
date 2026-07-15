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
			'description'         => __( 'Returns deterministic, bot-filtered visitor-retention metrics (return rate, cohort retention, cross-site return, session depth) plus a cross-surface referrer-host breakdown from first-party pageview events.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'         => array(
						'type'        => 'integer',
						'description' => __( 'Number of days the window spans. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'end_days_ago' => array(
						'type'        => 'integer',
						'description' => __( 'How many days ago the window ENDS. 0 (default) means the window ends now. A positive value shifts the whole window into the past, enabling an exact prior-period read (e.g. days=28, end_days_ago=28 reads the 28-day window immediately before the most recent 28 days). Applies to return rate, cross-site return, and session depth; the cohort curve always anchors at now.', 'extrachill-analytics' ),
						'default'     => 0,
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
			'permission_callback' => 'extrachill_analytics_can_read_reports',
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
	$end_days_ago = isset( $input['end_days_ago'] ) ? max( 0, (int) $input['end_days_ago'] ) : 0;
	$blog_id      = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$cohort_weeks = isset( $input['cohort_weeks'] ) ? max( 1, (int) $input['cohort_weeks'] ) : 8;

	$table      = extrachill_analytics_events_table();
	$event_type = defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview';

	// The window ENDS at $end_days_ago days before now (0 = now) and SPANS
	// $days. Shifting the end into the past yields an exact prior-period read
	// without any end-date-less proxying.
	$now_utc    = gmdate( 'Y-m-d H:i:s' );
	$window_end = gmdate( 'Y-m-d H:i:s', strtotime( "-{$end_days_ago} days" ) );
	$since      = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $days + $end_days_ago ) . ' days' ) );

	// Common WHERE: pageviews, in window, with a non-NULL visitor_id (opted-out
	// rows are excluded from per-visitor metrics), optional blog filter. An
	// upper bound is added only when the window ends in the past so the default
	// (end_days_ago=0) query and its index usage are unchanged.
	$base_where  = array( 'event_type = %s', "visitor_id IS NOT NULL AND visitor_id != ''", 'created_at >= %s' );
	$base_values = array( $event_type, $since );

	if ( $end_days_ago > 0 ) {
		$base_where[]  = 'created_at < %s';
		$base_values[] = $window_end;
	}

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

	// phpcs:disable WordPress.DB.PreparedSQL -- $return_sql interpolates only a code-defined table name and a placeholder where_clause bound via prepare().
	$return_row = $wpdb->get_row( $wpdb->prepare( $return_sql, $base_values ) );
	// phpcs:enable WordPress.DB.PreparedSQL

	$total_visitors     = $return_row ? (int) $return_row->total_visitors : 0;
	$returning_visitors = $return_row ? (int) $return_row->returning_visitors : 0;
	$return_rate        = $total_visitors > 0 ? round( $returning_visitors / $total_visitors, 4 ) : 0.0;

	// ---------------------------------------------------------------------
	// (b) Cohort retention: acquisition is the first observed pageview in the
	// available history (within the requested blog scope). W1 and W2 are the
	// first and second complete ISO weeks after that acquisition week.
	// ---------------------------------------------------------------------
	$cohort_since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$cohort_weeks} weeks" ) );

	$cohort_where  = array( 'event_type = %s', "visitor_id IS NOT NULL AND visitor_id != ''", 'created_at < %s' );
	$cohort_values = array( $event_type, $now_utc );
	if ( $blog_id > 0 ) {
		$cohort_where[]  = 'blog_id = %d';
		$cohort_values[] = $blog_id;
	}
	$cohort_where_clause = implode( ' AND ', $cohort_where );

	// The first derived table reads all available pageview history to establish
	// acquisition before restricting the requested cohort range. The activity
	// join is bounded to W1/W2 and uses the existing (visitor_id, created_at)
	// index for each acquired visitor.
	$cohort_sql = "SELECT
			cohort_week_start,
			COUNT(*) AS cohort_size,
			SUM(active_w1) AS returned_w1,
			SUM(active_w2) AS returned_w2
		FROM (
			SELECT
				acquisition.visitor_id,
				acquisition.cohort_week_start,
				MAX(CASE WHEN activity.created_at >= DATE_ADD(acquisition.cohort_week_start, INTERVAL 7 DAY) AND activity.created_at < DATE_ADD(acquisition.cohort_week_start, INTERVAL 14 DAY) THEN 1 ELSE 0 END) AS active_w1,
				MAX(CASE WHEN activity.created_at >= DATE_ADD(acquisition.cohort_week_start, INTERVAL 14 DAY) AND activity.created_at < DATE_ADD(acquisition.cohort_week_start, INTERVAL 21 DAY) THEN 1 ELSE 0 END) AS active_w2
			FROM (
				SELECT
					visitor_id,
					DATE_SUB( DATE( MIN(created_at) ), INTERVAL WEEKDAY( MIN(created_at) ) DAY ) AS cohort_week_start
				FROM {$table}
				WHERE {$cohort_where_clause}
				GROUP BY visitor_id
				HAVING MIN(created_at) >= %s
			) AS acquisition
			LEFT JOIN {$table} AS activity
				ON activity.visitor_id = acquisition.visitor_id
				AND activity.event_type = %s
				AND activity.visitor_id IS NOT NULL
				AND activity.created_at < %s
				AND activity.created_at >= DATE_ADD(acquisition.cohort_week_start, INTERVAL 7 DAY)
				AND activity.created_at < DATE_ADD(acquisition.cohort_week_start, INTERVAL 21 DAY)";

	if ( $blog_id > 0 ) {
		$cohort_sql .= ' AND activity.blog_id = %d';
	}

	$cohort_sql .= '
			GROUP BY acquisition.visitor_id, acquisition.cohort_week_start
		) AS per_visitor
		GROUP BY cohort_week_start
		ORDER BY cohort_week_start ASC';

	$cohort_query_values = array_merge( $cohort_values, array( $cohort_since, $event_type, $now_utc ) );
	if ( $blog_id > 0 ) {
		$cohort_query_values[] = $blog_id;
	}

	// phpcs:disable WordPress.DB.PreparedSQL -- $cohort_sql interpolates only a code-defined table name and a placeholder where_clause bound via prepare().
	$cohort_rows = $wpdb->get_results( $wpdb->prepare( $cohort_sql, $cohort_query_values ) );
	// phpcs:enable WordPress.DB.PreparedSQL

	$cohort_retention = extrachill_analytics_build_cohort_retention( $cohort_rows, $now_utc );

	// ---------------------------------------------------------------------
	// (c) Cross-site return: visitors who hit >= 2 distinct blog_ids on
	// different UTC days. Only meaningful network-wide, so this ignores the
	// blog_id filter by design (the question is inherently cross-site).
	// ---------------------------------------------------------------------
	$xsite_where  = array( 'event_type = %s', "visitor_id IS NOT NULL AND visitor_id != ''", 'created_at >= %s' );
	$xsite_values = array( $event_type, $since );
	if ( $end_days_ago > 0 ) {
		$xsite_where[]  = 'created_at < %s';
		$xsite_values[] = $window_end;
	}
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

	// phpcs:disable WordPress.DB.PreparedSQL -- $xsite_sql interpolates only a code-defined table name and a placeholder where_clause bound via prepare().
	$xsite_row = $wpdb->get_row( $wpdb->prepare( $xsite_sql, $xsite_values ) );
	// phpcs:enable WordPress.DB.PreparedSQL

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

	// phpcs:disable WordPress.DB.PreparedSQL -- $depth_sql interpolates only a code-defined table name and a placeholder where_clause bound via prepare().
	$depth_row = $wpdb->get_row( $wpdb->prepare( $depth_sql, $base_values ) );
	// phpcs:enable WordPress.DB.PreparedSQL

	$avg_depth = $depth_row && null !== $depth_row->avg_depth ? round( (float) $depth_row->avg_depth, 2 ) : 0.0;
	$max_depth = $depth_row ? (int) $depth_row->max_depth : 0;

	// ---------------------------------------------------------------------
	// (e) Referrer-host breakdown: top cross-surface origins that SENT readers
	// to a pageview in the window (AI-citation / social / search / cross-
	// subdomain landing attribution). Stamped onto new pageview rows as the
	// normalized, host-only `referrer_host` (issue #90). Unlike the per-visitor
	// metrics above this counts ALL pageviews (including anonymous/opted-out)
	// since referrer provenance is meaningful regardless of visitor_id, and only
	// rows that actually carry a referrer_host participate (direct + same-host
	// traffic omit the field). Old rows predating the field simply don't appear.
	// ---------------------------------------------------------------------
	$ref_where  = array( 'event_type = %s', 'created_at >= %s', "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.referrer_host')) IS NOT NULL" );
	$ref_values = array( $event_type, $since );
	if ( $end_days_ago > 0 ) {
		$ref_where[]  = 'created_at < %s';
		$ref_values[] = $window_end;
	}
	if ( $blog_id > 0 ) {
		$ref_where[]  = 'blog_id = %d';
		$ref_values[] = $blog_id;
	}
	$ref_where_clause = implode( ' AND ', $ref_where );

	$ref_sql = "SELECT
			JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.referrer_host')) AS referrer_host,
			COUNT(*) AS landings
		FROM {$table}
		WHERE {$ref_where_clause}
		GROUP BY referrer_host
		ORDER BY landings DESC
		LIMIT 25";

	// phpcs:disable WordPress.DB.PreparedSQL -- $ref_sql interpolates only a code-defined table name and a placeholder where_clause bound via prepare().
	$ref_rows = $wpdb->get_results( $wpdb->prepare( $ref_sql, $ref_values ) );
	// phpcs:enable WordPress.DB.PreparedSQL

	$by_referrer_host = array();
	foreach ( (array) $ref_rows as $row ) {
		$by_referrer_host[] = array(
			'referrer_host' => (string) $row->referrer_host,
			'landings'      => (int) $row->landings,
		);
	}

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
			'definition' => 'Acquisition is each visitor\'s first observed pageview in the available event history, scoped by blog_id when set. W1 is activity during the first complete ISO week after acquisition; W2 is activity during the second. A horizon is reported only after its full week has elapsed; incomplete horizons are null and must not be treated as zero retention. This cohort history is distinct from the rolling-window return_rate metric.',
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
		'by_referrer_host'  => array(
			'hosts'      => $by_referrer_host,
			'definition' => 'Top cross-surface referrer hosts (host-only, no query strings/PII) that sent readers to a pageview in the window — AI-citation (chatgpt.com / perplexity.ai), social, search engines, and cross-subdomain landings. Counts ALL pageviews carrying a referrer_host (not just identified visitors); direct + same-host traffic omit the field, and rows predating issue #90 do not appear.',
		),
		'days'              => $days,
		'end_days_ago'      => $end_days_ago,
		'blog_id'           => $blog_id,
		'period'            => gmdate( 'Y-m-d', strtotime( $since ) ) . ' to ' . gmdate( 'Y-m-d', strtotime( $window_end ) ),
		'since'             => $since,
		'until'             => $window_end,
		'as_of'             => $now_utc,
		'note'              => 'Deterministic + bot-filtered: pageview rows are written server-side only for non-bot, JS-executing browsers; visitor_id is an anonymous first-party UUID v4 (no PII). Opted-out (GPC/DNT) rows have NULL visitor_id and are excluded from per-visitor metrics.',
	);
}
