<?php
/**
 * Analytics Revenue CLI Command
 *
 * The content-category revenue + RPM lens. Makes the manual "join a Mediavine
 * Pages CSV to content categories" stitch repeatable: import the CSV, then roll
 * revenue up by content format or category with the honest $/page metric.
 *
 * Mediavine exposes NO per-page revenue API — its Control Panel plugin only
 * fetches ad-script settings — so the CSV import is the only path to per-URL
 * revenue. The `import` subcommand ingests a Mediavine Pages export; `rollup`
 * wraps the extrachill/get-content-revenue ability to report pages, views,
 * revenue, RPM, and $/page per format/category, with a recent-vs-lifetime lens.
 *
 * Why $/page and not RPM: RPM is a multiplier, not a volume measure. A format
 * can show a high RPM yet a tiny $/page because it has almost no views ("high
 * RPM on tiny volume = pennies"). $/page (revenue/pages) is the only honest
 * answer to "is this format worth producing," so the rollup ranks on it.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RevenueCommand {

	/**
	 * Fetch per-period Mediavine revenue over HTTP and load it into the store.
	 *
	 * The no-manual-CSV path. Wraps the datamachine/mediavine-reports DMB ability
	 * (direct GraphQL login + per-page CSV report — no browser, no manual export)
	 * and feeds the returned rows straight into the SAME revenue importer the
	 * `import` subcommand uses, so slug→post resolution, period stamping, and the
	 * arc all behave identically. Pass a single window (--start/--end + --period)
	 * for one bucket, or --periods for a multi-month backfill in one shot.
	 *
	 * Credentials live server-side in the datamachine_mediavine_config option —
	 * never on the command line. Set them once:
	 *   wp option update datamachine_mediavine_config '{"email":"...","password":"..."}' --format=json
	 *
	 * ## OPTIONS
	 *
	 * [--start=<date>]
	 * : Window start (Y-m-d). Used with --end for a single-period fetch.
	 *
	 * [--end=<date>]
	 * : Window end (Y-m-d). Used with --start for a single-period fetch.
	 *
	 * [--period=<period>]
	 * : Period label (e.g. 2026-05) to stamp the fetched rows with — what the arc groups by.
	 *
	 * [--periods=<json>]
	 * : JSON array for a backfill: [{"period":"2026-05","start_date":"2026-05-01","end_date":"2026-05-31"}, ...].
	 *   When supplied, --start/--end/--period are ignored and each entry is imported as its own batch.
	 *
	 * [--site-id=<id>]
	 * : Mediavine site id. Defaults to the configured/Extra Chill default.
	 *
	 * [--hostname=<host>]
	 * : Hostname for slug→post resolution.
	 * ---
	 * default: extrachill.com
	 * ---
	 *
	 * [--dry-run]
	 * : Fetch and resolve but do not write to the store.
	 *
	 * ## EXAMPLES
	 *
	 *     # One month, fetched live and loaded:
	 *     wp extrachill analytics revenue fetch --start=2026-05-01 --end=2026-05-31 --period=2026-05
	 *
	 *     # Multi-month backfill in one command:
	 *     wp extrachill analytics revenue fetch --periods='[{"period":"2026-04","start_date":"2026-04-01","end_date":"2026-04-30"},{"period":"2026-05","start_date":"2026-05-01","end_date":"2026-05-31"}]'
	 *
	 *     wp extrachill analytics revenue fetch --start=2026-05-01 --end=2026-05-31 --period=2026-05 --dry-run
	 *
	 * @subcommand fetch
	 * @when after_wp_load
	 */
	public function fetch( $args, $assoc_args ) {
		$ability = wp_get_ability( 'datamachine/mediavine-reports' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/mediavine-reports ability not found. Is Data Machine Business active and up to date (PR #48)?' );
		}
		if ( ! function_exists( 'extrachill_analytics_revenue_import_csv' ) ) {
			WP_CLI::error( 'extrachill-analytics revenue importer not available. Is Extra Chill Analytics active and up to date?' );
		}

		$hostname = $assoc_args['hostname'] ?? 'extrachill.com';
		$dry_run  = isset( $assoc_args['dry-run'] );

		// Build the period list: an explicit --periods backfill, or one window.
		$periods = array();
		if ( ! empty( $assoc_args['periods'] ) ) {
			$decoded = json_decode( (string) $assoc_args['periods'], true );
			if ( ! is_array( $decoded ) || empty( $decoded ) ) {
				WP_CLI::error( '--periods must be a non-empty JSON array of {period, start_date, end_date} objects.' );
			}
			$periods = $decoded;
		} else {
			$periods[] = array(
				'period'     => $assoc_args['period'] ?? '',
				'start_date' => $assoc_args['start'] ?? '',
				'end_date'   => $assoc_args['end'] ?? '',
			);
		}

		$ability_input = array(
			'action'  => 'backfill',
			'periods' => $periods,
		);
		if ( ! empty( $assoc_args['site-id'] ) ) {
			$ability_input['site_id'] = $assoc_args['site-id'];
		}

		WP_CLI::log( sprintf( 'Fetching %d period(s) from Mediavine…', count( $periods ) ) );

		$result = $ability->execute( $ability_input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Mediavine fetch failed.' );
		}

		$rows = (array) ( $result['results'] ?? array() );

		// Surface any per-period fetch errors the ability reported.
		foreach ( (array) ( $result['periods'] ?? array() ) as $summary ) {
			if ( ! empty( $summary['error'] ) ) {
				WP_CLI::warning( sprintf( 'Period %s failed: %s', ( '' !== ( $summary['period'] ?? '' ) ? $summary['period'] : '(window)' ), $summary['error'] ) );
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::error( 'Mediavine returned no rows for the requested period(s).' );
		}

		// Group rows by their stamped period so each lands in its own batch —
		// identical to importing one monthly CSV per period.
		$by_period = array();
		foreach ( $rows as $row ) {
			$period                 = (string) ( $row['period'] ?? '' );
			$by_period[ $period ][] = $row;
		}

		$total_parsed     = 0;
		$total_resolved   = 0;
		$total_unresolved = 0;
		$total_imported   = 0;

		foreach ( $by_period as $period => $period_rows ) {
			$csv_path = self::write_rows_csv( $period_rows );
			if ( is_wp_error( $csv_path ) ) {
				WP_CLI::warning( sprintf( 'Period %s: %s', ( '' !== $period ? $period : 'all-time' ), $csv_path->get_error_message() ) );
				continue;
			}

			$import = extrachill_analytics_revenue_import_csv(
				$csv_path,
				array(
					'period'   => $period,
					'hostname' => $hostname,
					'dry_run'  => $dry_run,
				)
			);

			wp_delete_file( $csv_path );

			if ( empty( $import['success'] ) ) {
				WP_CLI::warning( sprintf( 'Period %s import failed: %s', ( '' !== $period ? $period : 'all-time' ), $import['error'] ?? 'unknown' ) );
				continue;
			}

			$total_parsed     += (int) $import['rows'];
			$total_resolved   += (int) $import['resolved'];
			$total_unresolved += (int) $import['unresolved'];
			$total_imported   += (int) $import['imported'];

			WP_CLI::log(
				sprintf(
					'  %s — batch %s · parsed %d · resolved %d · unresolved %d · imported %d',
					( '' !== $period ? $period : 'all-time' ),
					$import['batch'],
					(int) $import['rows'],
					(int) $import['resolved'],
					(int) $import['unresolved'],
					(int) $import['imported']
				)
			);
		}

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'Totals across %d period(s): parsed %d · resolved %d · unresolved %d · imported %d%s',
				count( $by_period ),
				$total_parsed,
				$total_resolved,
				$total_unresolved,
				$total_imported,
				$dry_run ? ' (DRY RUN — nothing written)' : ''
			)
		);

		if ( ! $dry_run ) {
			WP_CLI::success( 'Mediavine fetch complete. See the arc with: wp extrachill analytics revenue arc' );
		}
	}

	/**
	 * Write ability rows to a temp CSV in the importer's expected header format.
	 *
	 * The mediavine-reports ability already returns rows keyed by the same tokens
	 * the importer matches (slug, views, revenue, rpm, cpm, viewability, fillRate,
	 * impressionsPerPageview), so we just serialize them to a CSV the existing
	 * importer can stream — reusing all of its slug→post resolution and store
	 * logic rather than duplicating it.
	 *
	 * @param array $rows Ability rows.
	 * @return string|\WP_Error Temp CSV path or error.
	 */
	private static function write_rows_csv( array $rows ) {
		$headers = array( 'slug', 'views', 'revenue', 'rpm', 'cpm', 'viewability', 'fillRate', 'impressionsPerPageview' );

		$tmp = wp_tempnam( 'mediavine-revenue' );
		if ( ! $tmp ) {
			return new \WP_Error( 'mediavine_tmp_failed', 'Could not create a temp file for the fetched rows.' );
		}

		$handle = fopen( $tmp, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- streaming CSV write to a temp file; WP_Filesystem has no streaming writer.
		if ( false === $handle ) {
			return new \WP_Error( 'mediavine_tmp_open', 'Could not open the temp CSV for writing.' );
		}

		fputcsv( $handle, $headers );

		foreach ( $rows as $row ) {
			fputcsv(
				$handle,
				array(
					$row['slug'] ?? '',
					$row['views'] ?? 0,
					$row['revenue'] ?? 0,
					$row['rpm'] ?? 0,
					$row['cpm'] ?? 0,
					$row['viewability'] ?? 0,
					$row['fillRate'] ?? 0,
					$row['impressionsPerPageview'] ?? 0,
				)
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- paired with the streaming fopen above.

		return $tmp;
	}

	/**
	 * Import a Mediavine Pages CSV into the revenue store.
	 *
	 * Mediavine has NO per-page revenue API (its Control Panel plugin only fetches
	 * ad-serving config), so this CSV import is the only path. The flat Mediavine
	 * pages export is also TIME-BLIND — no date column, one cumulative lifetime
	 * total per URL — so to build a real revenue arc the operator exports a
	 * DATE-RANGED (monthly) CSV from the Mediavine dashboard and passes the period
	 * here via --period=YYYY-MM. Each row is resolved to a WordPress post and
	 * stamped with that period; re-importing the same (period, batch) is idempotent.
	 *
	 * ## OPTIONS
	 *
	 * <csv>
	 * : Absolute path to the Mediavine Pages CSV export.
	 *
	 * [--period=<period>]
	 * : Time bucket these rows belong to: YYYY-MM (a monthly export), YYYY (a year),
	 *   or omitted for the flat lifetime file (lands in the "all-time" bucket). This
	 *   is what the `arc` revenue time-series groups by — supply it for monthly exports.
	 *
	 * [--period-start=<date>]
	 * : Explicit window start (Y-m-d) override. Defaults to the start derived from --period.
	 *
	 * [--period-end=<date>]
	 * : Explicit window end (Y-m-d) override. Defaults to the end derived from --period.
	 *
	 * [--batch=<label>]
	 * : Import batch label. Defaults to a label derived from the filename + period.
	 *
	 * [--hostname=<host>]
	 * : Hostname whose pages map to this blog's posts.
	 * ---
	 * default: extrachill.com
	 * ---
	 *
	 * [--dry-run]
	 * : Parse and resolve but do not write to the store.
	 *
	 * ## EXAMPLES
	 *
	 *     # Flat lifetime file (time-blind — good for category $/page, NOT the arc):
	 *     wp extrachill analytics revenue import /tmp/mediavine-all-time.csv
	 *
	 *     # Monthly exports build the revenue arc — one --period per file:
	 *     wp extrachill analytics revenue import /tmp/mv-2026-05.csv --period=2026-05
	 *     wp extrachill analytics revenue import /tmp/mv-2026-06.csv --period=2026-06
	 *
	 *     wp extrachill analytics revenue import /tmp/mv.csv --dry-run
	 *
	 * @subcommand import
	 * @when after_wp_load
	 */
	public function import( $args, $assoc_args ) {
		if ( ! function_exists( 'extrachill_analytics_revenue_import_csv' ) ) {
			WP_CLI::error( 'extrachill-analytics revenue importer not available. Is Extra Chill Analytics active and up to date?' );
		}

		$file = $args[0] ?? '';
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "CSV not readable: {$file}" );
		}

		$result = extrachill_analytics_revenue_import_csv(
			$file,
			array(
				'period'       => $assoc_args['period'] ?? '',
				'period_start' => $assoc_args['period-start'] ?? '',
				'period_end'   => $assoc_args['period-end'] ?? '',
				'import_batch' => $assoc_args['batch'] ?? '',
				'hostname'     => $assoc_args['hostname'] ?? 'extrachill.com',
				'dry_run'      => isset( $assoc_args['dry-run'] ),
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'CSV import failed.' );
		}

		$dry = ! empty( $result['dry_run'] );
		WP_CLI::log( sprintf( 'Batch: %s · period bucket: %s%s', $result['batch'], $result['period'] ?? 'all-time', $dry ? ' (DRY RUN — nothing written)' : '' ) );
		WP_CLI::log(
			sprintf(
				'Parsed %d rows · resolved %d to posts · %d unresolved · imported %d',
				(int) $result['rows'],
				(int) $result['resolved'],
				(int) $result['unresolved'],
				(int) $result['imported']
			)
		);

		if ( ! empty( $result['samples'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Sample rows:' );
			Utils\format_items( 'table', $result['samples'], array( 'slug', 'post_id', 'revenue', 'views' ) );
		}

		if ( (int) $result['unresolved'] > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Note: unresolved rows (often legacy .html ghost pages) are still stored and roll up under legacy-html/uncategorized.' );
		}

		if ( ! $dry ) {
			WP_CLI::success( 'Import complete. Roll up with: wp extrachill analytics revenue rollup' );
		}
	}

	/**
	 * Roll up imported revenue by content format or category.
	 *
	 * Wraps the extrachill/get-content-revenue ability. Reports pages, views,
	 * revenue, RPM and $/page per bucket, sorted by $/page (the honest "worth
	 * producing" metric — RPM alone misleads on low volume). Pass a window
	 * (--period-start/--period-end) for the earning-NOW lens versus the default
	 * lifetime-accumulated view.
	 *
	 * ## OPTIONS
	 *
	 * [--group-by=<axis>]
	 * : Rollup axis.
	 * ---
	 * default: format
	 * options:
	 *   - format
	 *   - category
	 *   - both
	 * ---
	 *
	 * [--period=<period>]
	 * : Scope the rollup to one time bucket (e.g. 2026-05, or all-time). Empty = every bucket combined.
	 *
	 * [--period-start=<date>]
	 * : Window start (Y-m-d). With --period-end, restricts to snapshots for that window (recent lens).
	 *
	 * [--period-end=<date>]
	 * : Window end (Y-m-d).
	 *
	 * [--batch=<label>]
	 * : Restrict to one import batch so multiple imports are never double-counted.
	 *
	 * [--hostname=<host>]
	 * : Hostname for resolving any still-unresolved slugs.
	 * ---
	 * default: extrachill.com
	 * ---
	 *
	 * [--limit=<n>]
	 * : Max buckets to show per axis.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics revenue rollup
	 *     wp extrachill analytics revenue rollup --group-by=category
	 *     wp extrachill analytics revenue rollup --period=2026-05 --group-by=format
	 *     wp extrachill analytics revenue rollup --group-by=both --format=json
	 *
	 * @subcommand rollup
	 * @when after_wp_load
	 */
	public function rollup( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-content-revenue' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-content-revenue ability not found. Is Extra Chill Analytics active and up to date?' );
		}

		$limit  = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		$format = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'group_by'     => $assoc_args['group-by'] ?? 'format',
				'period'       => $assoc_args['period'] ?? '',
				'period_start' => $assoc_args['period-start'] ?? '',
				'period_end'   => $assoc_args['period-end'] ?? '',
				'import_batch' => $assoc_args['batch'] ?? '',
				'hostname'     => $assoc_args['hostname'] ?? 'extrachill.com',
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['caveat'] ?? ( $result['error'] ?? 'Revenue rollup failed.' ) );
		}

		if ( 'table' !== $format ) {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
			return;
		}

		WP_CLI::log( sprintf( 'Content Revenue Lens — %s', $result['window'] ?? '' ) );
		$totals = $result['totals'] ?? array();
		WP_CLI::log(
			sprintf(
				'Totals: %d pages · %s views · $%s revenue · $%s/page · $%s RPM',
				(int) ( $totals['pages'] ?? 0 ),
				number_format( (int) ( $totals['views'] ?? 0 ) ),
				number_format( (float) ( $totals['revenue'] ?? 0 ), 2 ),
				number_format( (float) ( $totals['dollars_per_page'] ?? 0 ), 2 ),
				number_format( (float) ( $totals['rpm'] ?? 0 ), 2 )
			)
		);
		WP_CLI::log( str_repeat( '─', 78 ) );

		$rollups = $result['rollups'] ?? array();
		if ( empty( $rollups ) ) {
			WP_CLI::log( $result['caveat'] ?? 'No revenue data for this window.' );
			return;
		}

		foreach ( $rollups as $axis => $buckets ) {
			$label = 'by_format' === $axis ? 'By content format' : 'By category';
			WP_CLI::log( '' );
			WP_CLI::log( $label . ' (sorted by $/page — the honest "worth producing" metric):' );
			WP_CLI::log( '' );

			$rows = array_map( array( $this, 'row' ), array_slice( $buckets, 0, $limit ) );
			Utils\format_items( 'table', $rows, array( 'bucket', 'pages', 'views', 'revenue', 'dollars_per_page', 'rpm' ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( '$/page = revenue / pages — RPM alone misleads (high RPM on tiny volume = pennies).' );
		WP_CLI::log( 'High LIFETIME revenue can just mean a page is OLD; pass --period-start/--period-end for earning-NOW.' );
		WP_CLI::log( 'Revenue is Mediavine-imported (the only source of ad income) — never estimated.' );
	}

	/**
	 * Show the revenue ARC — revenue per time bucket, month-over-month.
	 *
	 * The first-class time-series view. Sums each imported period (one monthly
	 * Mediavine export = one point) chronologically so you can see the revenue
	 * arc and the HCU cliff IN DOLLARS, with a month-over-month delta per point.
	 * Requires DATE-RANGED monthly exports imported with --period=YYYY-MM; the
	 * flat lifetime file is a single cumulative "all-time" total (time-blind) and
	 * is excluded by default.
	 *
	 * ## OPTIONS
	 *
	 * [--include-alltime]
	 * : Also show the cumulative "all-time" flat-file bucket (a lifetime total, not a point on the arc).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics revenue arc
	 *     wp extrachill analytics revenue arc --include-alltime
	 *     wp extrachill analytics revenue arc --format=json
	 *
	 * @subcommand arc
	 * @when after_wp_load
	 */
	public function arc( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-content-revenue' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-content-revenue ability not found. Is Extra Chill Analytics active and up to date?' );
		}

		$format = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'group_by'        => 'timeseries',
				'include_alltime' => isset( $assoc_args['include-alltime'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['caveat'] ?? ( $result['error'] ?? 'Revenue arc failed.' ) );
		}

		$series = (array) ( $result['series'] ?? array() );

		if ( 'table' !== $format ) {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
			return;
		}

		if ( empty( $series ) ) {
			WP_CLI::log( 'No per-period data yet. Import DATE-RANGED monthly exports with --period=YYYY-MM to build the arc:' );
			WP_CLI::log( '  wp extrachill analytics revenue import /tmp/mv-2026-05.csv --period=2026-05' );
			return;
		}

		WP_CLI::log( 'Revenue ARC — per-period (month-over-month). Each point = one imported monthly Mediavine export.' );
		$peak   = $result['peak'] ?? array();
		$totals = $result['totals'] ?? array();
		WP_CLI::log(
			sprintf(
				'%d periods · $%s total · peak %s ($%s)',
				(int) ( $totals['periods'] ?? 0 ),
				number_format( (float) ( $totals['revenue'] ?? 0 ), 2 ),
				$peak['period'] ?? '—',
				number_format( (float) ( $peak['revenue'] ?? 0 ), 2 )
			)
		);
		WP_CLI::log( str_repeat( '─', 78 ) );

		$rows = array();
		foreach ( $series as $p ) {
			$mom    = $p['mom_delta'];
			$rows[] = array(
				'period'  => $p['period'] ?? '',
				'pages'   => (int) ( $p['pages'] ?? 0 ),
				'views'   => number_format( (int) ( $p['views'] ?? 0 ) ),
				'revenue' => '$' . number_format( (float) ( $p['revenue'] ?? 0 ), 2 ),
				'mom'     => ( null === $mom ) ? '—' : ( ( $mom >= 0 ? '+$' : '-$' ) . number_format( abs( (float) $mom ), 2 ) ),
				'mom_pct' => ( null === $p['mom_pct'] ) ? '—' : ( ( (float) $p['mom_pct'] >= 0 ? '+' : '' ) . $p['mom_pct'] . '%' ),
				'rpm'     => '$' . number_format( (float) ( $p['rpm'] ?? 0 ), 2 ),
			);
		}
		Utils\format_items( 'table', $rows, array( 'period', 'pages', 'views', 'revenue', 'mom', 'mom_pct', 'rpm' ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'The flat lifetime export is time-blind (no dates) and undercounts the 2022-2023 peak — export monthly CSVs for the true arc.' );
		WP_CLI::log( 'Revenue is Mediavine-imported (the only source of ad income) — never estimated.' );
	}

	/**
	 * List imported revenue batches for the current blog.
	 *
	 * Shows each (batch, period) snapshot set with its row count and totals so
	 * you can pick a --batch for rollup and avoid double-counting overlapping
	 * imports.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics revenue batches
	 *
	 * @subcommand batches
	 * @when after_wp_load
	 */
	public function batches( $args, $assoc_args ) {
		if ( ! function_exists( 'extrachill_analytics_revenue_list_batches' ) ) {
			WP_CLI::error( 'extrachill-analytics revenue store not available. Is Extra Chill Analytics active and up to date?' );
		}

		$format  = $assoc_args['format'] ?? 'table';
		$batches = extrachill_analytics_revenue_list_batches();

		if ( empty( $batches ) ) {
			WP_CLI::log( 'No revenue batches imported yet. Import one: wp extrachill analytics revenue import <csv>' );
			return;
		}

		$rows = array();
		foreach ( $batches as $b ) {
			$rows[] = array(
				'batch'        => $b->import_batch,
				'period'       => $b->period_label ?? 'all-time',
				'period_start' => $b->period_start ?? '—',
				'period_end'   => $b->period_end ?? '—',
				'pages'        => (int) $b->rows_count,
				'views'        => (int) $b->views,
				'revenue'      => number_format( (float) $b->revenue, 2 ),
				'imported_at'  => $b->imported_at,
			);
		}

		Utils\format_items( $format, $rows, array( 'batch', 'period', 'period_start', 'period_end', 'pages', 'views', 'revenue', 'imported_at' ) );
	}

	/**
	 * Shape one rollup bucket row for display.
	 *
	 * @param array $b Bucket row from the ability.
	 * @return array<string, mixed>
	 */
	private function row( array $b ) {
		return array(
			'bucket'           => $b['bucket'] ?? '',
			'pages'            => (int) ( $b['pages'] ?? 0 ),
			'views'            => number_format( (int) ( $b['views'] ?? 0 ) ),
			'revenue'          => '$' . number_format( (float) ( $b['revenue'] ?? 0 ), 2 ),
			'dollars_per_page' => '$' . number_format( (float) ( $b['dollars_per_page'] ?? 0 ), 2 ),
			'rpm'              => '$' . number_format( (float) ( $b['rpm'] ?? 0 ), 2 ),
		);
	}
}
