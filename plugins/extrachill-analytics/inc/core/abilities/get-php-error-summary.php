<?php
/**
 * Get PHP Error Summary Ability
 *
 * Read-side ability that buckets WordPress PHP error-log (debug.log) entries by
 * normalized signature and reports a count plus a stable per-day rate per
 * signature over a requested window.
 *
 * Data sources are merged so the report is trustworthy regardless of rotation:
 *   1. Persisted daily counts (extrachill_analytics_php_errors) — durable trend
 *      that survives log rotation, populated by the daily snapshot.
 *   2. A live tail of the current debug.log for entries not yet snapshotted,
 *      so the command works even before any snapshot has run.
 *
 * This is NOT the Data Machine job logger; it reads the raw PHP error log file.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_php_error_summary_ability' );

/**
 * Register the get-php-error-summary ability.
 */
function extrachill_analytics_register_php_error_summary_ability() {
	wp_register_ability(
		'extrachill/get-php-error-summary',
		array(
			'label'               => __( 'Get PHP Error Summary', 'extrachill-analytics' ),
			'description'         => __( 'Buckets WordPress PHP debug.log errors by normalized signature with stable per-day rates that survive log rotation.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'     => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all available history.', 'extrachill-analytics' ),
						'default'     => 7,
					),
					'severity' => array(
						'type'        => 'string',
						'description' => __( 'Filter by severity.', 'extrachill-analytics' ),
						'enum'        => array( 'all', 'fatal', 'warning', 'notice', 'deprecated', 'strict', 'parse' ),
						'default'     => 'all',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => __( 'Maximum signatures to return. 0 for unlimited.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'snapshot' => array(
						'type'        => 'boolean',
						'description' => __( 'Run a live snapshot pass before reporting so the current log tail is captured.', 'extrachill-analytics' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Summary with rows array (signature, severity, file:line, count, per_day, sample), totals, and window metadata.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_php_error_summary',
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
 * Execute callback for get-php-error-summary ability.
 *
 * @param array $input Input parameters.
 * @return array Summary data with shape:
 *   [
 *     'rows'                => [ [ signature, severity, file, count, per_day, sample, first_seen, last_seen ], ... ],
 *     'total'               => int,
 *     'active_total'        => int,    // Occurrences whose timestamps fall inside the active window.
 *     'active_per_day'      => float,  // active_total normalized to the active-window length (per day).
 *     'active_window_hours' => int,    // The activity threshold defining "currently firing".
 *     'active_signatures'   => int,    // Distinct signatures with at least one active-window occurrence.
 *     'distinct_signatures' => int,
 *     'window_days'         => int,
 *     'days_covered'        => int,
 *     'period'              => string,
 *     'source'              => string,
 *     'truncated'           => bool,
 *     'log_path'            => string,
 *   ]
 *
 * The `active_*` keys are the "currently firing" lens: `active_total` counts
 * ONLY occurrences whose own timestamps fall inside the active window — not the
 * full-window count of a signature that merely has a recent tail (issue #128).
 * A resolved multi-day spike that left a single fresh occurrence contributes
 * just that one recent occurrence, so a fixed spike stops inflating the current
 * error rate. The full-window `total` and per-signature `per_day` are preserved
 * unchanged for trend continuity. The activity window defaults to 24 hours and
 * is filterable via `extrachill_analytics_error_active_window_hours`.
 *
 * Persisted/live merge: the persisted daily table covers bytes [0, watermark)
 * already snapshotted; the live tail is read from that watermark so the two
 * sources are byte-disjoint and never count the same occurrence twice.
 */
function extrachill_analytics_ability_get_php_error_summary( $input ) {
	global $wpdb;

	$days     = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 7;
	$severity = isset( $input['severity'] ) ? strtolower( (string) $input['severity'] ) : 'all';
	$limit    = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 25;
	$run_snap = ! empty( $input['snapshot'] );

	$valid_severities = array( 'all', 'fatal', 'warning', 'notice', 'deprecated', 'strict', 'parse' );
	if ( ! in_array( $severity, $valid_severities, true ) ) {
		$severity = 'all';
	}

	// Optionally capture the current log tail into the durable table first.
	if ( $run_snap ) {
		extrachill_analytics_snapshot_php_errors();
	}

	$since_ts  = $days > 0 ? strtotime( "-{$days} days", time() ) : null;
	$since_day = null !== $since_ts ? gmdate( 'Y-m-d', $since_ts ) : null;

	// Active-window threshold (computed early so both persisted and live sources
	// share one cutoff). active_total counts only occurrences inside this window,
	// not the full-window count of a signature that merely has a recent tail (#128).
	$active_window_hours = (int) apply_filters( 'extrachill_analytics_error_active_window_hours', 24 );
	$active_window_hours = max( 1, $active_window_hours );
	$active_cutoff       = time() - ( $active_window_hours * HOUR_IN_SECONDS );

	// 1. Persisted daily counts from the durable table.
	$table  = extrachill_analytics_php_error_table();
	$where  = array( '1=1' );
	$values = array();

	if ( null !== $since_day ) {
		$where[]  = 'snapshot_day >= %s';
		$values[] = $since_day;
	}
	if ( 'all' !== $severity ) {
		$where[]  = 'severity = %s';
		$values[] = $severity;
	}

	$where_clause = implode( ' AND ', $where );

	// $table is an internal constant table name and $where_clause is built only
	// from hardcoded fragments whose values are bound via %s placeholders in
	// $values, so the interpolated query is safe.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sql = "SELECT signature, severity, file_line, sample_message,
				SUM(count) AS total_count,
				MIN(first_seen) AS first_seen,
				MAX(last_seen) AS last_seen,
				COUNT(DISTINCT snapshot_day) AS days_present
			FROM {$table}
			WHERE {$where_clause}
			GROUP BY signature, severity, file_line, sample_message";

	$persisted = empty( $values )
		? $wpdb->get_results( $sql )
		: $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Active-window persisted counts: sum the per-day rows whose latest
	// occurrence (last_seen) falls inside the active cutoff. The durable table
	// stores daily aggregates, so active resolution is day-granularity: a daily
	// row counts toward active_total when its newest occurrence that day is
	// recent. This correctly EXCLUDES resolved multi-day-old spikes even when the
	// same signature has one fresh occurrence, fixing the #128 regression. Sub-day
	// splitting is the documented limit of the daily schema.
	$active_where        = $where;
	$active_values       = $values;
	$active_where[]      = 'last_seen >= %s';
	$active_values[]     = gmdate( 'Y-m-d H:i:s', $active_cutoff );
	$active_where_clause = implode( ' AND ', $active_where );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$active_sql = "SELECT signature, SUM(count) AS active_count
				FROM {$table}
				WHERE {$active_where_clause}
				GROUP BY signature";

	$persisted_active = $wpdb->get_results( $wpdb->prepare( $active_sql, $active_values ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$persisted_active_map = array();
	if ( is_array( $persisted_active ) ) {
		foreach ( $persisted_active as $active_row ) {
			$persisted_active_map[ (string) $active_row->signature ] = (int) $active_row->active_count;
		}
	}

	$agg          = array();
	$covered_days = array();

	if ( is_array( $persisted ) ) {
		foreach ( $persisted as $row ) {
			$sig         = (string) $row->signature;
			$agg[ $sig ] = array(
				'signature'    => $sig,
				'severity'     => (string) $row->severity,
				'file'         => (string) $row->file_line,
				'count'        => (int) $row->total_count,
				'active_count' => isset( $persisted_active_map[ $sig ] ) ? $persisted_active_map[ $sig ] : 0,
				'sample'       => (string) $row->sample_message,
				'first_seen'   => (string) $row->first_seen,
				'last_seen'    => (string) $row->last_seen,
			);
			if ( $row->first_seen ) {
				$covered_days[ gmdate( 'Y-m-d', strtotime( $row->first_seen ) ) ] = true;
			}
			if ( $row->last_seen ) {
				$covered_days[ gmdate( 'Y-m-d', strtotime( $row->last_seen ) ) ] = true;
			}
		}
	}

	// 2. Live tail of the current debug.log for not-yet-snapshotted entries.
	$log_path = extrachill_analytics_php_error_log_path();
	// Read the live tail starting at the snapshot byte watermark so live entries
	// are the not-yet-snapshotted bytes only — disjoint from the persisted set
	// (which already covers bytes [0, watermark)). This prevents the same
	// occurrence being counted in both sources. The memory cap still applies.
	$watermark = extrachill_analytics_php_error_log_snapshot_offset( $log_path );
	$live      = extrachill_analytics_parse_php_error_log( $log_path, $since_ts, 32 * MB_IN_BYTES, $watermark );

	foreach ( $live['entries'] as $entry ) {
		if ( 'all' !== $severity && $entry['severity'] !== $severity ) {
			continue;
		}

		$sig = $entry['signature'];

		if ( ! isset( $agg[ $sig ] ) ) {
			$agg[ $sig ] = array(
				'signature'    => $sig,
				'severity'     => $entry['severity'],
				'file'         => $entry['file'],
				'count'        => 0,
				'active_count' => 0,
				'sample'       => $entry['sample'],
				'first_seen'   => null,
				'last_seen'    => null,
			);
		}

		++$agg[ $sig ]['count'];

		// Live occurrences inside the active window count toward active volume.
		if ( null !== $entry['ts'] && $entry['ts'] >= $active_cutoff ) {
			++$agg[ $sig ]['active_count'];
		}

		if ( null !== $entry['ts'] ) {
			$ts_str = gmdate( 'Y-m-d H:i:s', $entry['ts'] );
			if ( null === $agg[ $sig ]['first_seen'] || $ts_str < $agg[ $sig ]['first_seen'] ) {
				$agg[ $sig ]['first_seen'] = $ts_str;
			}
			if ( null === $agg[ $sig ]['last_seen'] || $ts_str > $agg[ $sig ]['last_seen'] ) {
				$agg[ $sig ]['last_seen'] = $ts_str;
			}
			$covered_days[ gmdate( 'Y-m-d', $entry['ts'] ) ] = true;
		}
	}

	// Determine the per-day denominator. Prefer the requested window; if the data
	// only spans fewer days than requested, use the actual covered span so a short
	// log does not understate the rate (the whole point of the issue).
	$days_covered = count( $covered_days );
	if ( $days > 0 ) {
		$denominator = $days;
	} else {
		$denominator = max( 1, $days_covered );
	}
	$denominator = max( 1, $denominator );

	// Build, sort, and compute per-day rates.
	$rows = array_values( $agg );
	usort(
		$rows,
		function ( $a, $b ) {
			return $b['count'] <=> $a['count'];
		}
	);

	// Finalize per-row rates and the active-window totals. The active lens is
	// driven by each row's active_count (occurrences inside the active window),
	// NOT its full-window count — the #128 fix. Isolated in a pure helper so the
	// counting contract is unit-testable without a database.
	$computed = extrachill_analytics_compute_php_error_active_totals(
		$rows,
		$denominator,
		$active_window_hours
	);

	$rows              = $computed['rows'];
	$grand_total       = $computed['total'];
	$active_total      = $computed['active_total'];
	$active_per_day    = $computed['active_per_day'];
	$active_signatures = $computed['active_signatures'];

	$distinct  = count( $rows );
	$truncated = false;
	if ( $limit > 0 && count( $rows ) > $limit ) {
		$rows      = array_slice( $rows, 0, $limit );
		$truncated = true;
	}

	$source = 'persisted+live';
	if ( empty( $persisted ) ) {
		$source = 'live';
	} elseif ( empty( $live['entries'] ) ) {
		$source = 'persisted';
	}

	return array(
		'rows'                => $rows,
		'total'               => $grand_total,
		'active_total'        => $active_total,
		'active_per_day'      => $active_per_day,
		'active_window_hours' => $active_window_hours,
		'active_signatures'   => $active_signatures,
		'distinct_signatures' => $distinct,
		'window_days'         => $days,
		'days_covered'        => $days_covered,
		'period'              => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all available history',
		'source'              => $source,
		'truncated'           => $truncated,
		'log_path'            => $log_path,
	);
}

/**
 * Finalize per-row rates and compute the active-window (currently-firing) totals.
 *
 * Pure — no WP / DB dependencies — so the counting contract at the heart of
 * issue #128 is unit-testable in isolation. Each row contributes to the active
 * volume via its `active_count` (occurrences inside the active window), NOT its
 * full-window `count`. A signature is "active" when it has at least one recent
 * occurrence; a resolved multi-day spike that left a single fresh occurrence
 * contributes just that one occurrence, not its entire historical count.
 *
 * `active_per_day` normalizes active volume to the active-window length (e.g.
 * last-24h count expressed per day), matching the platform-health consumer's
 * own active-window normalization.
 *
 * @param array $rows Aggregated rows; each carries 'count' (full window) and
 *                    optionally 'active_count' (active window, defaulted to 0).
 *                    Mutated in place: 'per_day', 'active_count', and 'active'
 *                    are stamped on each row.
 * @param int   $denominator Full-window per-day denominator (for per_day trend).
 * @param int   $active_window_hours Active window length in hours.
 * @return array{
 *     rows: array<int, array>,
 *     total: int,
 *     active_total: int,
 *     active_per_day: float,
 *     active_signatures: int,
 * }
 */
function extrachill_analytics_compute_php_error_active_totals( array $rows, $denominator, $active_window_hours ) {
	$denominator        = max( 1, (int) $denominator );
	$active_window_days = max( 1.0, (float) $active_window_hours / 24.0 );

	$grand_total       = 0;
	$active_total      = 0;
	$active_signatures = 0;

	foreach ( $rows as &$row ) {
		$grand_total += (int) $row['count'];

		// Full-window per-day trend rate (unchanged): total count over the
		// requested window denominator.
		$row['per_day'] = round( (int) $row['count'] / $denominator, 1 );

		// Active lens: count only occurrences inside the active window.
		if ( ! isset( $row['active_count'] ) ) {
			$row['active_count'] = 0;
		}
		$row['active_count'] = (int) $row['active_count'];
		$row['active']       = $row['active_count'] > 0;
		if ( $row['active'] ) {
			$active_total += $row['active_count'];
			++$active_signatures;
		}
	}
	unset( $row );

	return array(
		'rows'              => $rows,
		'total'             => $grand_total,
		'active_total'      => $active_total,
		'active_per_day'    => round( $active_total / $active_window_days, 1 ),
		'active_signatures' => $active_signatures,
	);
}
