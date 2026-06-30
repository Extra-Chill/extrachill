<?php
/**
 * Get Bot-Filter Impact Ability
 *
 * Standing guardrail that counts how much LOGGED-IN human activity the canonical
 * visitor classifier is silently filtering out as `is_bot`. It turns a recurring
 * judgment call — "are we mislabeling real, authenticated people (especially team
 * members) as bots?" — into a deterministic, reproducible number, so nobody has
 * to re-discover the problem by hand each time.
 *
 * WHY THIS EXISTS (the finding it makes permanent):
 *
 *   The canonical visitor classifier false-flags authenticated, logged-in users
 *   as bots when their action is captured server-side via REST (see issue #103 —
 *   a fix for the classifier ITSELF is in flight in a separate PR). The most
 *   concrete prod evidence: user_id 38 (qrisg, extra_chill_team) had 39 Roadie
 *   tool-calls in 28 days, every single one stamped `is_bot:true` despite being a
 *   real logged-in human.
 *
 *   This ability does NOT fix the classifier. It MEASURES the blast radius of the
 *   mislabeling so the impact is visible and trackable over time, and so a
 *   regression after #103 lands is caught automatically instead of by chance.
 *
 * WHAT IT MEASURES:
 *
 *   Captured analytics events that are stamped `is_bot:true` BUT also carry a
 *   non-zero logged-in user_id — i.e. events the pipeline both (a) attributed to
 *   a specific authenticated WordPress user and (b) discarded as bot traffic.
 *   Those two facts are contradictory: a bot has no logged-in user_id. Every such
 *   row is a real human's action being dropped from the human-side analytics.
 *
 *   The user_id signal is read from BOTH places the pipeline stamps it: the
 *   first-class `user_id` column AND `event_data.user_id`. They do not always
 *   agree row-for-row (some event types populate only one — e.g. user_registration
 *   carries it in JSON only, email_sent in the column only), so the guardrail
 *   treats a logged-in human as EITHER signal being a positive integer. Using only
 *   one source would undercount the mislabeling.
 *
 * MariaDB JSON-BOOLEAN CORRECTNESS (issue #85 / #87):
 *
 *   `is_bot` is stored as a JSON boolean. On this MariaDB server, comparing the
 *   JSON value to a SQL boolean (`= true` / `= false`, or COALESCE-to-false) is a
 *   silent NO-OP that does NOT match the bot rows — the #85 footgun that inflated
 *   a headline ~23x. The correct ternary-aware predicate is `IS TRUE`: it matches
 *   rows whose JSON value is literally `true` and excludes explicit `false` AND
 *   NULL/no-flag legacy rows. This ability is the INVERSE of get-search-gaps
 *   (which uses `IS NOT TRUE` to keep humans): here we WANT only the bot-flagged
 *   rows, so we use `IS TRUE`.
 *
 * @package ExtraChill\Analytics
 * @since 0.24.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-bot-filter-impact ability.
 */
function extrachill_analytics_register_bot_filter_impact_ability() {
	wp_register_ability(
		'extrachill/get-bot-filter-impact',
		array(
			'label'               => __( 'Get Bot-Filter Impact', 'extrachill-analytics' ),
			'description'         => __( 'Deterministic guardrail: counts analytics events stamped is_bot:true that nonetheless carry a non-zero logged-in user_id, broken down by event_type — measuring how much authenticated human activity the visitor classifier is filtering out.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'    => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'examples' => array(
						'type'        => 'integer',
						'description' => __( 'Number of example rows to return for spot-checking. 0 to skip.', 'extrachill-analytics' ),
						'default'     => 10,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with by_event_type breakdown, totals, example rows, and the exact UTC window.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_bot_filter_impact',
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
 * Execute callback for get-bot-filter-impact ability.
 *
 * @param array $input Input parameters.
 * @return array Report data with shape:
 *   [
 *     'by_event_type'   => [ ['event_type' => ..., 'count' => N, 'distinct_users' => M], ... ],
 *     'total_events'    => int,    // bot-flagged events carrying a logged-in user_id
 *     'distinct_users'  => int,    // distinct logged-in users mislabeled at least once
 *     'examples'        => [ ['event_type' => ..., 'user_id' => N, 'created_at' => ...], ... ],
 *     'days'            => int,
 *     'blog_id'         => int,
 *     'period'          => string,
 *     'since'           => string, // UTC inclusive lower bound (empty for all-time)
 *     'as_of'           => string, // UTC instant the report ran
 *     'note'            => string,
 *   ]
 */
function extrachill_analytics_ability_get_bot_filter_impact( $input ) {
	global $wpdb;

	$days     = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 28;
	$blog_id  = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$examples = isset( $input['examples'] ) ? max( 0, (int) $input['examples'] ) : 10;

	$table  = extrachill_analytics_events_table();
	$where  = array();
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

	// THE bot predicate. `is_bot` is a JSON boolean; on this MariaDB server only
	// the ternary-aware `IS TRUE` reliably matches the literal JSON `true` (see
	// #85/#87 — `= true`/COALESCE-to-false silently match nothing). This ability
	// is the inverse of get-search-gaps: there we keep humans with `IS NOT TRUE`,
	// here we deliberately select ONLY the bot-flagged rows with `IS TRUE`.
	$where[] = "JSON_EXTRACT(event_data, '$.is_bot') IS TRUE";

	// The contradiction we are measuring: a bot-flagged row that nonetheless
	// carries a non-zero logged-in user_id. The user_id is read from BOTH stamp
	// sites — the first-class column AND event_data.user_id — because different
	// event types populate only one (see header note). A positive value in
	// EITHER means the pipeline attributed this "bot" event to a real
	// authenticated WordPress user.
	$user_id_expr = "COALESCE(NULLIF(user_id, 0), CAST(JSON_EXTRACT(event_data, '$.user_id') AS SIGNED), 0)";
	$where[]      = "{$user_id_expr} > 0";

	$where_clause = implode( ' AND ', $where );

	// Read-only aggregation over a custom analytics table. The table name and the
	// JSON-extract expressions are interpolated from trusted, code-defined
	// strings (never request input); every value bound through the WHERE clause
	// flows through $wpdb->prepare(). Caching is intentionally skipped: this is an
	// on-demand admin/CLI guardrail report, not a hot path.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Per-event-type breakdown: which instruments are dropping logged-in humans,
	// with the volume and the count of distinct real users affected by each.
	$breakdown_sql = "SELECT event_type,
			COUNT(*) AS cnt,
			COUNT(DISTINCT {$user_id_expr}) AS distinct_users
		FROM {$table}
		WHERE {$where_clause}
		GROUP BY event_type
		ORDER BY cnt DESC";

	$breakdown_rows = empty( $values )
		? $wpdb->get_results( $breakdown_sql )
		: $wpdb->get_results( $wpdb->prepare( $breakdown_sql, $values ) );

	// Network-wide totals: total mislabeled events and distinct humans affected
	// across all event types.
	$total_sql = "SELECT COUNT(*) AS cnt, COUNT(DISTINCT {$user_id_expr}) AS distinct_users
		FROM {$table}
		WHERE {$where_clause}";

	$total_row = empty( $values )
		? $wpdb->get_row( $total_sql )
		: $wpdb->get_row( $wpdb->prepare( $total_sql, $values ) );

	// A few example rows for spot-checking (event_type, the resolved user_id,
	// when it happened). Most-recent first so a caller can eyeball live traffic.
	$example_rows = array();
	if ( $examples > 0 ) {
		$example_sql = "SELECT event_type, {$user_id_expr} AS user_id, created_at
			FROM {$table}
			WHERE {$where_clause}
			ORDER BY created_at DESC
			LIMIT %d";

		$example_values   = $values;
		$example_values[] = $examples;
		$example_rows     = $wpdb->get_results( $wpdb->prepare( $example_sql, $example_values ) );
	}

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$by_event_type = array();
	foreach ( (array) $breakdown_rows as $row ) {
		$by_event_type[] = array(
			'event_type'     => (string) $row->event_type,
			'count'          => (int) $row->cnt,
			'distinct_users' => (int) $row->distinct_users,
		);
	}

	$examples_out = array();
	foreach ( (array) $example_rows as $row ) {
		$examples_out[] = array(
			'event_type' => (string) $row->event_type,
			'user_id'    => (int) $row->user_id,
			'created_at' => (string) $row->created_at,
		);
	}

	return array(
		'by_event_type'  => $by_event_type,
		'total_events'   => $total_row ? (int) $total_row->cnt : 0,
		'distinct_users' => $total_row ? (int) $total_row->distinct_users : 0,
		'examples'       => $examples_out,
		'days'           => $days,
		'blog_id'        => $blog_id,
		'period'         => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'since'          => $since,
		'as_of'          => $now_utc,
		'note'           => 'Each counted event is stamped is_bot:true yet carries a non-zero logged-in user_id (read from the user_id column OR event_data.user_id) — a contradiction that means a real authenticated human was dropped from human-side analytics. This MEASURES the mislabeling; it does not fix the classifier (issue #103, separate PR). is_bot is tested with the ternary-aware `IS TRUE` predicate required by this MariaDB server (issue #85/#87).',
	);
}
