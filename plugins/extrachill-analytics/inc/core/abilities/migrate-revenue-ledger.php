<?php
/**
 * Legacy revenue table -> Data Machine Business revenue ledger migration.
 *
 * One-time, operator-run move of `{base_prefix}extrachill_analytics_mediavine_revenue`
 * into the DMB ledger through `datamachine/revenue-ingest`:
 *
 * - Each legacy snapshot (blog_id, import_batch, period_label, period_start,
 *   period_end) becomes one ledger account+window (see revenue-ledger.php), so
 *   re-running the migration never duplicates and never mixes snapshots.
 * - Rows map to source=mediavine, kind=ad_revenue, amount=revenue (USD), the
 *   rates and views into `metrics`, and URL/post attribution into the ledger
 *   attribution fields (url, blog_id, object_ref).
 * - Per-period totals are computed before (legacy table) and after (ledger,
 *   read back through datamachine/revenue-query) and compared: parity.
 * - Dry-run by default. A snapshot already present in the ledger is never
 *   overwritten: matching totals are reported as already migrated, differing
 *   totals as a conflict.
 * - Undated rows (the flat "all-time" lifetime file has no period) cannot be
 *   stored in a dated ledger and are reported, never silently dropped.
 *
 * The legacy table is NEVER dropped by the migration. A separate, explicit
 * drop (dry-run by default) refuses unless parity holds.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a legacy row carries a valid dated window the ledger can store.
 *
 * @param array $row Legacy row.
 * @return bool
 */
function extrachill_analytics_revenue_migration_row_is_dated( array $row ) {
	$start = isset( $row['period_start'] ) ? (string) $row['period_start'] : '';
	$end   = isset( $row['period_end'] ) ? (string) $row['period_end'] : '';
	return extrachill_analytics_revenue_is_valid_date( $start )
		&& extrachill_analytics_revenue_is_valid_date( $end )
		&& $start <= $end;
}

/**
 * Group legacy rows into ledger snapshots (pure).
 *
 * @param array<int, array|object> $legacy_rows Legacy table rows.
 * @return array{snapshots:array<string,array>,undated:array{rows:int,views:int,revenue:float,periods:array<int,string>}}
 */
function extrachill_analytics_revenue_migration_plan( array $legacy_rows ) {
	$snapshots = array();
	$undated   = array(
		'rows'    => 0,
		'views'   => 0,
		'revenue' => 0.0,
		'periods' => array(),
	);

	foreach ( $legacy_rows as $row ) {
		$row = (array) $row;
		if ( ! extrachill_analytics_revenue_migration_row_is_dated( $row ) ) {
			++$undated['rows'];
			$undated['views']   += isset( $row['views'] ) ? (int) $row['views'] : 0;
			$undated['revenue'] += isset( $row['revenue'] ) ? (float) $row['revenue'] : 0.0;
			$label               = isset( $row['period_label'] ) ? (string) $row['period_label'] : 'all-time';
			if ( ! in_array( $label, $undated['periods'], true ) ) {
				$undated['periods'][] = $label;
			}
			continue;
		}

		$blog_id = isset( $row['blog_id'] ) ? (int) $row['blog_id'] : 1;
		$batch   = isset( $row['import_batch'] ) ? (string) $row['import_batch'] : '';
		$account = extrachill_analytics_revenue_ledger_account( $blog_id, $batch );
		$key     = $account . '|' . $row['period_start'] . '|' . $row['period_end'];

		if ( ! isset( $snapshots[ $key ] ) ) {
			$snapshots[ $key ] = array(
				'account'      => $account,
				'blog_id'      => $blog_id,
				'import_batch' => $batch,
				'period_label' => isset( $row['period_label'] ) ? (string) $row['period_label'] : '',
				'period_start' => (string) $row['period_start'],
				'period_end'   => (string) $row['period_end'],
				'rows'         => array(),
				'views'        => 0,
				'revenue'      => 0.0,
			);
		}
		$snapshots[ $key ]['rows'][]   = extrachill_analytics_revenue_ledger_row_from_record( $row );
		$snapshots[ $key ]['views']   += isset( $row['views'] ) ? (int) $row['views'] : 0;
		$snapshots[ $key ]['revenue'] += isset( $row['revenue'] ) ? (float) $row['revenue'] : 0.0;
	}

	ksort( $snapshots );
	$undated['revenue'] = round( $undated['revenue'], 4 );

	return array(
		'snapshots' => $snapshots,
		'undated'   => $undated,
	);
}

/**
 * Per-period totals (pure): rows, views, revenue per blog + period window.
 *
 * Works on legacy rows and on ledger rows mapped back to the legacy shape, so
 * the before/after comparison uses one aggregation.
 *
 * @param iterable $rows Rows (arrays or objects).
 * @return array<string, array> Totals keyed by "blog|label|start|end", sorted.
 */
function extrachill_analytics_revenue_period_totals( $rows ) {
	$totals = array();
	foreach ( $rows as $row ) {
		$row = (array) $row;
		if ( ! extrachill_analytics_revenue_migration_row_is_dated( $row ) ) {
			continue;
		}
		$blog_id = isset( $row['blog_id'] ) ? (int) $row['blog_id'] : 1;
		$label   = isset( $row['period_label'] ) ? (string) $row['period_label'] : '';
		$key     = $blog_id . '|' . $label . '|' . $row['period_start'] . '|' . $row['period_end'];
		if ( ! isset( $totals[ $key ] ) ) {
			$totals[ $key ] = array(
				'blog_id'      => $blog_id,
				'period_label' => $label,
				'period_start' => (string) $row['period_start'],
				'period_end'   => (string) $row['period_end'],
				'rows'         => 0,
				'views'        => 0,
				'revenue'      => 0.0,
			);
		}
		++$totals[ $key ]['rows'];
		$totals[ $key ]['views']   += isset( $row['views'] ) ? (int) $row['views'] : 0;
		$totals[ $key ]['revenue'] += isset( $row['revenue'] ) ? (float) $row['revenue'] : 0.0;
	}
	foreach ( $totals as &$total ) {
		$total['revenue'] = round( $total['revenue'], 4 );
	}
	unset( $total );
	ksort( $totals );
	return $totals;
}

/**
 * Compare per-period totals before (legacy) and after (ledger) (pure).
 *
 * Parity holds when every dated legacy period exists in the ledger with the
 * same row count, views, and revenue (to 1/10000 of a dollar). Ledger-only
 * periods (fetched after the migration) never break parity.
 *
 * @param array<string, array> $before Legacy period totals.
 * @param array<string, array> $after  Ledger period totals.
 * @return array{holds:bool,matched:int,mismatched:int,missing:int,periods:array<int,array>}
 */
function extrachill_analytics_revenue_parity( array $before, array $after ) {
	$periods    = array();
	$matched    = 0;
	$mismatched = 0;
	$missing    = 0;

	foreach ( $before as $key => $legacy ) {
		$ledger = isset( $after[ $key ] ) ? $after[ $key ] : null;
		if ( null === $ledger ) {
			$status = 'missing';
			++$missing;
		} elseif ( $legacy['rows'] === $ledger['rows'] && $legacy['views'] === $ledger['views'] && abs( $legacy['revenue'] - $ledger['revenue'] ) < 0.00005 ) {
			$status = 'match';
			++$matched;
		} else {
			$status = 'mismatch';
			++$mismatched;
		}
		$periods[] = array(
			'blog_id'        => $legacy['blog_id'],
			'period_label'   => $legacy['period_label'],
			'period_start'   => $legacy['period_start'],
			'period_end'     => $legacy['period_end'],
			'status'         => $status,
			'legacy_rows'    => $legacy['rows'],
			'legacy_views'   => $legacy['views'],
			'legacy_revenue' => $legacy['revenue'],
			'ledger_rows'    => null === $ledger ? 0 : $ledger['rows'],
			'ledger_views'   => null === $ledger ? 0 : $ledger['views'],
			'ledger_revenue' => null === $ledger ? 0.0 : $ledger['revenue'],
		);
	}

	return array(
		'holds'      => 0 === $mismatched && 0 === $missing,
		'matched'    => $matched,
		'mismatched' => $mismatched,
		'missing'    => $missing,
		'periods'    => $periods,
	);
}

/**
 * Read every Extra Chill ledger row for the given blogs (legacy shape).
 *
 * @param array<int> $blog_ids Blogs to read (empty = every Extra Chill blog).
 * @return array{rows:array<int,object>,snapshots:array<string,array>,truncated:array<int,string>}|WP_Error
 */
function extrachill_analytics_revenue_migration_ledger_state( array $blog_ids ) {
	$snapshots = extrachill_analytics_revenue_ledger_snapshots( null );
	if ( is_wp_error( $snapshots ) ) {
		return $snapshots;
	}

	$rows      = array();
	$by_key    = array();
	$truncated = array();
	foreach ( $snapshots as $snapshot ) {
		if ( ! empty( $blog_ids ) && ! in_array( (int) $snapshot['blog_id'], $blog_ids, true ) ) {
			continue;
		}
		if ( $snapshot['truncated'] ) {
			$truncated[] = $snapshot['account'];
		}
		foreach ( $snapshot['rows'] as $row ) {
			$rows[] = $row;
			$key    = $snapshot['account'] . '|' . $row->period_start . '|' . $row->period_end;
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = array(
					'rows'    => 0,
					'views'   => 0,
					'revenue' => 0.0,
				);
			}
			++$by_key[ $key ]['rows'];
			$by_key[ $key ]['views']   += (int) $row->views;
			$by_key[ $key ]['revenue'] += (float) $row->revenue;
		}
	}

	return array(
		'rows'      => $rows,
		'snapshots' => $by_key,
		'truncated' => $truncated,
	);
}

/**
 * Blog ids present in a set of legacy rows.
 *
 * @param array $legacy_rows Legacy rows.
 * @return array<int>
 */
function extrachill_analytics_revenue_migration_blog_ids( array $legacy_rows ) {
	$ids = array();
	foreach ( $legacy_rows as $row ) {
		$row        = (array) $row;
		$id         = isset( $row['blog_id'] ) ? (int) $row['blog_id'] : 1;
		$ids[ $id ] = true;
	}
	$ids = array_keys( $ids );
	sort( $ids );
	return $ids;
}

/**
 * Compute legacy-vs-ledger parity for a set of legacy rows.
 *
 * @param array $legacy_rows Legacy rows.
 * @return array|WP_Error { before, after, parity, undated, truncated, ledger }.
 */
function extrachill_analytics_revenue_migration_parity_report( array $legacy_rows ) {
	$plan   = extrachill_analytics_revenue_migration_plan( $legacy_rows );
	$before = extrachill_analytics_revenue_period_totals( $legacy_rows );
	$state  = extrachill_analytics_revenue_migration_ledger_state( extrachill_analytics_revenue_migration_blog_ids( $legacy_rows ) );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$after = array_intersect_key( extrachill_analytics_revenue_period_totals( $state['rows'] ), $before );

	return array(
		'before'    => array_values( $before ),
		'after'     => array_values( $after ),
		'parity'    => extrachill_analytics_revenue_parity( $before, $after ),
		'undated'   => $plan['undated'],
		'truncated' => $state['truncated'],
		'plan'      => $plan,
		'ledger'    => $state,
	);
}

/**
 * Migrate the legacy revenue table into the revenue ledger.
 *
 * @param array      $args        { execute: bool (default false = dry run), blog_id: int (0 = all) }.
 * @param array|null $legacy_rows Inject legacy rows (tests); null reads the table.
 * @return array Migration report.
 */
function extrachill_analytics_revenue_migrate_legacy( array $args = array(), $legacy_rows = null ) {
	$execute = ! empty( $args['execute'] );
	$blog_id = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : 0;

	if ( null === $legacy_rows ) {
		$legacy_rows = extrachill_analytics_revenue_legacy_rows( $blog_id );
	} elseif ( $blog_id > 0 ) {
		$legacy_rows = array_values(
			array_filter(
				$legacy_rows,
				static function ( $row ) use ( $blog_id ) {
					$row = (array) $row;
					return isset( $row['blog_id'] ) && (int) $row['blog_id'] === $blog_id;
				}
			)
		);
	}

	$initial = extrachill_analytics_revenue_migration_parity_report( $legacy_rows );
	if ( is_wp_error( $initial ) ) {
		return array(
			'success' => false,
			'dry_run' => ! $execute,
			'error'   => $initial->get_error_message(),
		);
	}

	$results = array();
	$errors  = array();
	foreach ( $initial['plan']['snapshots'] as $key => $snapshot ) {
		$entry = array(
			'account'      => $snapshot['account'],
			'blog_id'      => $snapshot['blog_id'],
			'import_batch' => $snapshot['import_batch'],
			'period_label' => $snapshot['period_label'],
			'period_start' => $snapshot['period_start'],
			'period_end'   => $snapshot['period_end'],
			'rows'         => count( $snapshot['rows'] ),
			'views'        => $snapshot['views'],
			'revenue'      => round( $snapshot['revenue'], 4 ),
		);

		$existing = isset( $initial['ledger']['snapshots'][ $key ] ) ? $initial['ledger']['snapshots'][ $key ] : null;
		if ( null !== $existing ) {
			$same            = $existing['rows'] === $entry['rows'] && $existing['views'] === $entry['views'] && abs( round( $existing['revenue'], 4 ) - $entry['revenue'] ) < 0.00005;
			$entry['status'] = $same ? 'already_migrated' : 'conflict';
			$results[]       = $entry;
			continue;
		}

		$result = extrachill_analytics_revenue_ledger_execute(
			EXTRACHILL_ANALYTICS_REVENUE_LEDGER_INGEST_ABILITY,
			array(
				'source'       => 'mediavine',
				'account'      => $snapshot['account'],
				'start_date'   => $snapshot['period_start'],
				'end_date'     => $snapshot['period_end'],
				'kinds'        => array( EXTRACHILL_ANALYTICS_REVENUE_LEDGER_KIND ),
				'rows'         => $snapshot['rows'],
				'import_batch' => substr( 'ec-legacy-' . ( '' !== $snapshot['import_batch'] ? $snapshot['import_batch'] : 'unbatched' ), 0, 64 ),
				'dry_run'      => ! $execute,
			)
		);
		if ( is_wp_error( $result ) ) {
			$entry['status'] = 'error';
			$entry['error']  = $result->get_error_message();
			$errors[]        = $snapshot['account'] . ' ' . $snapshot['period_start'] . '..' . $snapshot['period_end'] . ': ' . $result->get_error_message();
		} else {
			$entry['status'] = $execute ? 'migrated' : 'would_migrate';
		}
		$results[] = $entry;
	}

	$final = $execute ? extrachill_analytics_revenue_migration_parity_report( $legacy_rows ) : $initial;
	if ( is_wp_error( $final ) ) {
		return array(
			'success' => false,
			'dry_run' => ! $execute,
			'error'   => $final->get_error_message(),
		);
	}

	return array(
		'success'      => empty( $errors ),
		'dry_run'      => ! $execute,
		'legacy_rows'  => count( $legacy_rows ),
		'before'       => $initial['before'],
		'after'        => $final['after'],
		'parity'       => $final['parity'],
		'snapshots'    => $results,
		'undated'      => $final['undated'],
		'truncated'    => $final['truncated'],
		'errors'       => $errors,
		'legacy_table' => 'kept',
		'caveat'       => __( 'The legacy table is never dropped by the migration. Run the explicit drop (revenue drop-legacy) once parity holds. Undated (all-time) rows cannot be stored in the dated ledger and are reported here, not migrated.', 'extrachill-analytics' ),
	);
}

/**
 * Explicit, parity-guarded drop of the legacy revenue table.
 *
 * Dry-run by default. Refuses unless parity holds for every dated legacy
 * period, no ledger snapshot read was truncated, and (unless allow_undated is
 * set) there are no undated rows that the ledger cannot hold.
 *
 * @param array         $args        { execute: bool, allow_undated: bool }.
 * @param array|null    $legacy_rows Inject legacy rows (tests); null reads the table.
 * @param callable|null $dropper     Inject the drop operation (tests); null drops the table.
 * @return array Drop report.
 */
function extrachill_analytics_revenue_drop_legacy( array $args = array(), $legacy_rows = null, $dropper = null ) {
	$execute       = ! empty( $args['execute'] );
	$allow_undated = ! empty( $args['allow_undated'] );

	if ( null === $legacy_rows ) {
		if ( ! extrachill_analytics_revenue_legacy_table_exists() ) {
			return array(
				'success' => true,
				'dry_run' => ! $execute,
				'dropped' => false,
				'refused' => false,
				'reasons' => array( 'The legacy revenue table does not exist; nothing to drop.' ),
			);
		}
		$legacy_rows = extrachill_analytics_revenue_legacy_rows( 0 );
	}

	$report = extrachill_analytics_revenue_migration_parity_report( $legacy_rows );
	if ( is_wp_error( $report ) ) {
		return array(
			'success' => false,
			'dry_run' => ! $execute,
			'dropped' => false,
			'refused' => true,
			'reasons' => array( 'Could not read the revenue ledger: ' . $report->get_error_message() ),
		);
	}

	$reasons = array();
	if ( ! $report['parity']['holds'] ) {
		$reasons[] = sprintf(
			'Parity does not hold: %d period(s) missing from the ledger and %d mismatched. Run the migration first.',
			$report['parity']['missing'],
			$report['parity']['mismatched']
		);
	}
	if ( ! empty( $report['truncated'] ) ) {
		$reasons[] = 'Some ledger snapshots exceed the revenue-query row cap, so parity cannot be verified row-for-row.';
	}
	if ( $report['undated']['rows'] > 0 && ! $allow_undated ) {
		$reasons[] = sprintf( '%d undated (all-time) legacy row(s) cannot be stored in the dated ledger; pass allow_undated to accept dropping them.', $report['undated']['rows'] );
	}

	$refused = ! empty( $reasons );
	$dropped = false;
	if ( ! $refused && $execute ) {
		$dropper = is_callable( $dropper ) ? $dropper : 'extrachill_analytics_revenue_drop_legacy_table';
		$dropped = (bool) call_user_func( $dropper );
		if ( ! $dropped ) {
			$reasons[] = 'The DROP TABLE did not complete.';
		}
	}

	return array(
		'success'     => ! $refused && ( ! $execute || $dropped ),
		'dry_run'     => ! $execute,
		'dropped'     => $dropped,
		'would_drop'  => ! $refused && ! $execute,
		'refused'     => $refused,
		'reasons'     => $reasons,
		'legacy_rows' => count( $legacy_rows ),
		'parity'      => $report['parity'],
		'undated'     => $report['undated'],
	);
}

/**
 * Permission gate shared by the migration abilities (network-level or CLI).
 *
 * @return bool
 */
function extrachill_analytics_revenue_migration_permission() {
	return current_user_can( 'manage_network_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
}
