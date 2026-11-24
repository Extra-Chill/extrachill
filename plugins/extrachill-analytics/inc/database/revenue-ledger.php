<?php
/**
 * Revenue ledger adapter (Data Machine Business).
 *
 * Extra Chill no longer owns revenue storage. Every revenue row lives in the
 * generic Data Machine Business ledger (`{base_prefix}datamachine_revenue_ledger`)
 * and is written only through `datamachine/revenue-ingest` and read only through
 * `datamachine/revenue-query`. This file is the Extra Chill layer on top of that
 * ledger:
 *
 * - Snapshot identity. Each Extra Chill snapshot (blog + import batch, the
 *   deterministic identity from ingest-revenue) is one ledger `account`
 *   (`extrachill:blog-{blog_id}:{import_batch}`), so the ledger's
 *   (source, account, kind, window) replace scope is exactly the snapshot.
 * - Attribution. The ledger attribution fields carry the source URL (`url`), the
 *   owning content blog (`blog_id`) and the resolved post (`object_ref` =
 *   `post:{blog_id}:{post_id}`). Mediavine-native numbers (views, rpm, cpm,
 *   viewability, fill_rate, impressions_per_pageview) plus the normalized slug,
 *   canonical URL and period label live in `metrics`.
 * - Read shape. Ledger rows are mapped back to the legacy row object every
 *   revenue reader already understands (slug, url, post_id, content_blog_id,
 *   views, revenue, rates, period_label/start/end, import_batch), so the ARC,
 *   rollups, page lens, diagnostics and delivery coverage keep their Extra
 *   Chill semantics (slug/URL to post resolution, residual vs forward format,
 *   outbound joins) while the bytes live in the ledger.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_REVENUE_LEDGER_QUERY_ABILITY', 'datamachine/revenue-query' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_LEDGER_INGEST_ABILITY', 'datamachine/revenue-ingest' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_LEDGER_KIND', 'ad_revenue' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_LEDGER_CURRENCY', 'USD' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_LEDGER_ACCOUNT_PREFIX', 'extrachill:blog-' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_LEDGER_ROW_LIMIT', 1000 );

/**
 * Resolve a Data Machine ledger ability.
 *
 * Filterable so tests (and alternate deployments) can provide an object with an
 * `execute( array $input )` method without the Data Machine plugin.
 *
 * @param string $name Ability name.
 * @return object|null Ability-like object, or null when unavailable.
 */
function extrachill_analytics_revenue_ledger_ability( $name ) {
	/**
	 * Short-circuit the ledger ability used by the Extra Chill revenue adapter.
	 *
	 * @param object|null $ability Ability-like object exposing execute( array ), or null for the registered ability.
	 * @param string      $name    Ability name.
	 */
	$ability = apply_filters( 'extrachill_analytics_revenue_ledger_ability', null, $name );
	if ( null === $ability && function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
		$ability = wp_get_ability( $name );
	}

	return is_object( $ability ) && method_exists( $ability, 'execute' ) ? $ability : null;
}

/**
 * Execute a ledger ability, normalizing failures to WP_Error.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return array|WP_Error Ability result.
 */
function extrachill_analytics_revenue_ledger_execute( $name, array $input ) {
	$ability = extrachill_analytics_revenue_ledger_ability( $name );
	if ( null === $ability ) {
		return new WP_Error( 'extrachill_revenue_ledger_unavailable', sprintf( 'The %s ability is not registered. Activate Data Machine Business (revenue ledger).', $name ) );
	}

	$execute = array( $ability, 'execute' );
	if ( ! is_callable( $execute ) ) {
		return new WP_Error( 'extrachill_revenue_ledger_unavailable', sprintf( 'The %s ability cannot be executed.', $name ) );
	}

	$result = call_user_func( $execute, $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		$message = is_array( $result ) && ! empty( $result['error'] ) ? (string) $result['error'] : sprintf( '%s returned an unsuccessful result.', $name );
		return new WP_Error( 'extrachill_revenue_ledger_failed', $message );
	}

	return $result;
}

/**
 * Ledger account for one Extra Chill revenue snapshot.
 *
 * @param int    $blog_id      Snapshot (importing) blog.
 * @param string $import_batch Snapshot identity / batch label.
 * @return string Account string.
 */
function extrachill_analytics_revenue_ledger_account( $blog_id, $import_batch ) {
	return EXTRACHILL_ANALYTICS_REVENUE_LEDGER_ACCOUNT_PREFIX . (int) $blog_id . ':' . (string) $import_batch;
}

/**
 * Parse an Extra Chill ledger account.
 *
 * @param string $account Ledger account.
 * @return array{blog_id:int,import_batch:string}|null Parsed identity, or null when not an Extra Chill account.
 */
function extrachill_analytics_revenue_ledger_parse_account( $account ) {
	if ( ! preg_match( '/^' . preg_quote( EXTRACHILL_ANALYTICS_REVENUE_LEDGER_ACCOUNT_PREFIX, '/' ) . '(\d+):(.*)$/sD', (string) $account, $m ) ) {
		return null;
	}

	return array(
		'blog_id'      => (int) $m[1],
		'import_batch' => (string) $m[2],
	);
}

/**
 * Format a revenue float as a ledger decimal string (major units, 6 places).
 *
 * @param mixed $value Revenue value.
 * @return string Decimal string.
 */
function extrachill_analytics_revenue_ledger_amount( $value ) {
	return number_format( round( (float) $value, 6 ), 6, '.', '' );
}

/**
 * Canonical period label for a dated ledger window.
 *
 * @param string $start Window start (Y-m-d).
 * @param string $end   Window end (Y-m-d).
 * @return string YYYY-MM for a calendar month, YYYY for a calendar year, else start..end.
 */
function extrachill_analytics_revenue_ledger_period_label( $start, $end ) {
	$start = (string) $start;
	$end   = (string) $end;
	if ( preg_match( '/^(\d{4})-(\d{2})-01$/', $start, $m ) ) {
		$month_end = gmdate( 'Y-m-t', (int) strtotime( $start . ' 00:00:00 UTC' ) );
		if ( $month_end === $end ) {
			return $m[1] . '-' . $m[2];
		}
		if ( '01' === $m[2] && $m[1] . '-12-31' === $end ) {
			return $m[1];
		}
	}
	return $start . '..' . $end;
}

/**
 * Map one Extra Chill revenue record to a normalized ledger row.
 *
 * Pure. Used by the ingest store and the one-time legacy migration so both
 * write identical ledger rows.
 *
 * @param array|object $record Legacy-shaped revenue record.
 * @return array Ledger row for datamachine/revenue-ingest.
 */
function extrachill_analytics_revenue_ledger_row_from_record( $record ) {
	$record          = (array) $record;
	$post_id         = ! empty( $record['post_id'] ) ? (int) $record['post_id'] : 0;
	$content_blog_id = ! empty( $record['content_blog_id'] ) ? (int) $record['content_blog_id'] : 0;
	$url             = isset( $record['url'] ) ? (string) $record['url'] : '';
	$slug            = isset( $record['slug'] ) ? (string) $record['slug'] : '';

	$metrics = array(
		'views'                    => isset( $record['views'] ) ? max( 0, (int) $record['views'] ) : 0,
		'rpm'                      => isset( $record['rpm'] ) ? (float) $record['rpm'] : 0.0,
		'cpm'                      => isset( $record['cpm'] ) ? (float) $record['cpm'] : 0.0,
		'viewability'              => isset( $record['viewability'] ) ? (float) $record['viewability'] : 0.0,
		'fill_rate'                => isset( $record['fill_rate'] ) ? (float) $record['fill_rate'] : 0.0,
		'impressions_per_pageview' => isset( $record['impressions_per_pageview'] ) ? (float) $record['impressions_per_pageview'] : 0.0,
		'slug'                     => $slug,
		'canonical_url'            => isset( $record['canonical_url'] ) ? (string) $record['canonical_url'] : '',
		'period_label'             => isset( $record['period_label'] ) ? (string) $record['period_label'] : '',
	);

	$row = array(
		'kind'         => EXTRACHILL_ANALYTICS_REVENUE_LEDGER_KIND,
		'amount'       => extrachill_analytics_revenue_ledger_amount( isset( $record['revenue'] ) ? $record['revenue'] : 0 ),
		'currency'     => EXTRACHILL_ANALYTICS_REVENUE_LEDGER_CURRENCY,
		'period_start' => isset( $record['period_start'] ) ? (string) $record['period_start'] : '',
		'period_end'   => isset( $record['period_end'] ) ? (string) $record['period_end'] : '',
		'url'          => '' !== $url ? $url : ( '' !== $slug ? '/' . trim( $slug, '/' ) . '/' : '/' ),
		'object_ref'   => $post_id > 0 ? 'post:' . $content_blog_id . ':' . $post_id : null,
		'blog_id'      => $post_id > 0 && $content_blog_id > 0 ? $content_blog_id : null,
		'metrics'      => $metrics,
	);

	return $row;
}

/**
 * Map one ledger row back to the legacy revenue row object.
 *
 * @param array $row     Ledger row (datamachine/revenue-query rows[] shape).
 * @param int   $blog_id Snapshot blog parsed from the account.
 * @param string $batch  Snapshot batch parsed from the account.
 * @return \stdClass Legacy-shaped row.
 */
function extrachill_analytics_revenue_ledger_record_from_row( array $row, $blog_id, $batch ) {
	$metrics = isset( $row['metrics'] ) && is_array( $row['metrics'] ) ? $row['metrics'] : array();
	$url     = isset( $row['url'] ) ? (string) $row['url'] : '';

	$post_id         = null;
	$content_blog_id = null;
	if ( isset( $row['object_ref'] ) && preg_match( '/^post:(\d+):(\d+)$/', (string) $row['object_ref'], $m ) && (int) $m[2] > 0 ) {
		$post_id         = (int) $m[2];
		$content_blog_id = (int) $m[1] > 0 ? (int) $m[1] : null;
	}
	if ( null === $content_blog_id && null !== $post_id && ! empty( $row['blog_id'] ) ) {
		$content_blog_id = (int) $row['blog_id'];
	}

	if ( array_key_exists( 'slug', $metrics ) ) {
		$slug = (string) $metrics['slug'];
	} else {
		$path = extrachill_analytics_revenue_ledger_path( $url );
		$slug = trim( $path, '/' );
	}

	$start = isset( $row['period_start'] ) ? (string) $row['period_start'] : '';
	$end   = isset( $row['period_end'] ) ? (string) $row['period_end'] : '';
	$label = isset( $metrics['period_label'] ) && '' !== (string) $metrics['period_label']
		? (string) $metrics['period_label']
		: extrachill_analytics_revenue_ledger_period_label( $start, $end );

	return (object) array(
		'id'                       => isset( $row['id'] ) ? (int) $row['id'] : 0,
		'blog_id'                  => (int) $blog_id,
		'slug'                     => $slug,
		'url'                      => $url,
		'post_id'                  => $post_id,
		'content_blog_id'          => $content_blog_id,
		'canonical_url'            => isset( $metrics['canonical_url'] ) ? (string) $metrics['canonical_url'] : '',
		'views'                    => isset( $metrics['views'] ) ? (int) $metrics['views'] : 0,
		'revenue'                  => isset( $row['amount'] ) ? (float) $row['amount'] : 0.0,
		'rpm'                      => isset( $metrics['rpm'] ) ? (float) $metrics['rpm'] : 0.0,
		'cpm'                      => isset( $metrics['cpm'] ) ? (float) $metrics['cpm'] : 0.0,
		'viewability'              => isset( $metrics['viewability'] ) ? (float) $metrics['viewability'] : 0.0,
		'fill_rate'                => isset( $metrics['fill_rate'] ) ? (float) $metrics['fill_rate'] : 0.0,
		'impressions_per_pageview' => isset( $metrics['impressions_per_pageview'] ) ? (float) $metrics['impressions_per_pageview'] : 0.0,
		'period_label'             => $label,
		'period_start'             => '' !== $start ? $start : null,
		'period_end'               => '' !== $end ? $end : null,
		'import_batch'             => (string) $batch,
		'imported_at'              => isset( $row['imported_at'] ) ? (string) $row['imported_at'] : '',
		'source'                   => isset( $row['source'] ) ? (string) $row['source'] : '',
		'ledger_account'           => isset( $row['account'] ) ? (string) $row['account'] : '',
	);
}

/**
 * Path component of a URL or path, without query/fragment.
 *
 * @param string $url URL or path.
 * @return string Path ('' when none).
 */
function extrachill_analytics_revenue_ledger_path( $url ) {
	$path = wp_parse_url( (string) $url, PHP_URL_PATH );
	return is_string( $path ) ? $path : '';
}

/**
 * Last ledger read status (error + truncation), for read paths to disclose.
 *
 * @param array|null $set Replace the status when non-null.
 * @return array{error:string,truncated_snapshots:array<int,string>}
 */
function extrachill_analytics_revenue_ledger_read_status( $set = null ) {
	static $status = array(
		'error'               => '',
		'truncated_snapshots' => array(),
	);
	if ( is_array( $set ) ) {
		$status = array_merge(
			array(
				'error'               => '',
				'truncated_snapshots' => array(),
			),
			$set
		);
	}
	return $status;
}

/**
 * Read Extra Chill revenue snapshots from the ledger via datamachine/revenue-query.
 *
 * One coverage query discovers every Extra Chill snapshot account; each
 * snapshot is then read on its own (rows + exact ledger totals), so the
 * query ability's per-call row cap applies per snapshot, never per history.
 * A snapshot whose matched rows exceed the cap is reported as truncated.
 *
 * @param int|null $blog_id Snapshot blog, or null for every Extra Chill blog.
 * @param array    $args    Optional period_start / period_end window (Y-m-d).
 * @return array<int, array>|WP_Error Snapshots: account, blog_id, import_batch, rows (legacy objects), matched_rows, returned_rows, revenue, truncated.
 */
function extrachill_analytics_revenue_ledger_snapshots( $blog_id = null, array $args = array() ) {
	$window = array();
	if ( ! empty( $args['period_start'] ) && ! empty( $args['period_end'] ) ) {
		$window = array(
			'start_date' => (string) $args['period_start'],
			'end_date'   => (string) $args['period_end'],
		);
	}

	$coverage = extrachill_analytics_revenue_ledger_execute(
		EXTRACHILL_ANALYTICS_REVENUE_LEDGER_QUERY_ABILITY,
		array_merge( array( 'kind' => EXTRACHILL_ANALYTICS_REVENUE_LEDGER_KIND ), $window )
	);
	if ( is_wp_error( $coverage ) ) {
		return $coverage;
	}

	$snapshots = array();
	$entries   = isset( $coverage['coverage'] ) && is_array( $coverage['coverage'] ) ? $coverage['coverage'] : array();
	foreach ( $entries as $entry ) {
		$account = isset( $entry['account'] ) ? (string) $entry['account'] : '';
		$parsed  = extrachill_analytics_revenue_ledger_parse_account( $account );
		if ( null === $parsed || ( null !== $blog_id && (int) $blog_id !== $parsed['blog_id'] ) ) {
			continue;
		}

		$input = array(
			'source'       => isset( $entry['source'] ) ? (string) $entry['source'] : '',
			'account'      => $account,
			'kind'         => EXTRACHILL_ANALYTICS_REVENUE_LEDGER_KIND,
			'include_rows' => true,
			'limit'        => EXTRACHILL_ANALYTICS_REVENUE_LEDGER_ROW_LIMIT,
		);
		if ( ! empty( $window ) ) {
			$input = array_merge( $input, $window );
		} elseif ( ! empty( $entry['first_date'] ) && ! empty( $entry['last_date'] ) ) {
			$input['start_date'] = (string) $entry['first_date'];
			$input['end_date']   = (string) $entry['last_date'];
		}

		$result = extrachill_analytics_revenue_ledger_execute( EXTRACHILL_ANALYTICS_REVENUE_LEDGER_QUERY_ABILITY, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows = array();
		foreach ( isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array() as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = extrachill_analytics_revenue_ledger_record_from_row( $row, $parsed['blog_id'], $parsed['import_batch'] );
			}
		}

		$revenue = 0.0;
		foreach ( isset( $result['totals'] ) && is_array( $result['totals'] ) ? $result['totals'] : array() as $total ) {
			$revenue += isset( $total['amount'] ) ? (float) $total['amount'] : 0.0;
		}
		$matched = isset( $result['counts']['matched_rows'] ) ? (int) $result['counts']['matched_rows'] : count( $rows );

		$snapshots[] = array(
			'account'       => $account,
			'source'        => $input['source'],
			'blog_id'       => $parsed['blog_id'],
			'import_batch'  => $parsed['import_batch'],
			'rows'          => $rows,
			'matched_rows'  => $matched,
			'returned_rows' => count( $rows ),
			'revenue'       => $revenue,
			'truncated'     => $matched > count( $rows ),
		);
	}

	return $snapshots;
}

/**
 * Fetch revenue rows for a blog from the ledger, in the legacy row shape.
 *
 * Scope semantics are unchanged from the retired table: exact period_label,
 * optional import batch, and a window that the snapshot must sit inside.
 *
 * @param array $args {
 *     Query arguments.
 *
 *     @type int    $blog_id               Blog ID (default: current blog).
 *     @type string $import_batch          Restrict to one import batch (default: '' = any).
 *     @type bool   $restrict_import_batch Match import_batch exactly, including an empty batch.
 *     @type string $period_label          Restrict to one time bucket, e.g. "2026-05".
 *     @type string $period_start          Inclusive window start (Y-m-d), or '' for any.
 *     @type string $period_end            Inclusive window end (Y-m-d), or '' for any.
 * }
 * @return array<int, \stdClass> Revenue rows.
 */
function extrachill_analytics_revenue_get_rows( array $args = array() ) {
	$blog_id        = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
	$import_batch   = isset( $args['import_batch'] ) ? (string) $args['import_batch'] : '';
	$restrict_batch = ! empty( $args['restrict_import_batch'] ) || '' !== $import_batch;
	$period_label   = isset( $args['period_label'] ) ? (string) $args['period_label'] : '';
	$period_start   = isset( $args['period_start'] ) ? (string) $args['period_start'] : '';
	$period_end     = isset( $args['period_end'] ) ? (string) $args['period_end'] : '';
	$window         = '' !== $period_start && '' !== $period_end
		? array(
			'period_start' => $period_start,
			'period_end'   => $period_end,
		)
		: array();

	$snapshots = extrachill_analytics_revenue_ledger_snapshots( $blog_id, $window );
	if ( is_wp_error( $snapshots ) ) {
		extrachill_analytics_revenue_ledger_read_status( array( 'error' => $snapshots->get_error_message() ) );
		return array();
	}

	$rows      = array();
	$truncated = array();
	foreach ( $snapshots as $snapshot ) {
		if ( $restrict_batch && $snapshot['import_batch'] !== $import_batch ) {
			continue;
		}
		$matched_any = false;
		foreach ( $snapshot['rows'] as $row ) {
			if ( '' !== $period_label && $row->period_label !== $period_label ) {
				continue;
			}
			if ( ! empty( $window ) && ( null === $row->period_start || $row->period_start < $period_start || $row->period_end > $period_end ) ) {
				continue;
			}
			$rows[]      = $row;
			$matched_any = true;
		}
		if ( $snapshot['truncated'] && ( $matched_any || empty( $snapshot['rows'] ) ) ) {
			$truncated[] = $snapshot['account'];
		}
	}

	extrachill_analytics_revenue_ledger_read_status( array( 'truncated_snapshots' => $truncated ) );

	return $rows;
}

/**
 * Independent ledger aggregate of rows, views, and revenue for a scope.
 *
 * Revenue and row counts come from the ledger's own totals (every matching
 * row, never capped), so the diagnostics reconciliation compares the row-level
 * read against the ledger aggregate and catches a truncated read.
 *
 * @param array $args See extrachill_analytics_revenue_get_rows().
 * @return \stdClass|null { rows_count, views, revenue } or null on error.
 */
function extrachill_analytics_revenue_get_scope_totals( array $args = array() ) {
	$blog_id      = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
	$import_batch = isset( $args['import_batch'] ) ? (string) $args['import_batch'] : '';
	$restrict     = ! empty( $args['restrict_import_batch'] ) || '' !== $import_batch;
	$period_label = isset( $args['period_label'] ) ? (string) $args['period_label'] : '';
	$window       = ! empty( $args['period_start'] ) && ! empty( $args['period_end'] )
		? array(
			'period_start' => (string) $args['period_start'],
			'period_end'   => (string) $args['period_end'],
		)
		: array();

	$snapshots = extrachill_analytics_revenue_ledger_snapshots( $blog_id, $window );
	if ( is_wp_error( $snapshots ) ) {
		return null;
	}

	$totals = (object) array(
		'rows_count' => 0,
		'views'      => 0,
		'revenue'    => 0.0,
	);
	foreach ( $snapshots as $snapshot ) {
		if ( $restrict && $snapshot['import_batch'] !== $import_batch ) {
			continue;
		}
		if ( '' !== $period_label ) {
			$labels = array_unique( wp_list_pluck( $snapshot['rows'], 'period_label' ) );
			if ( array( $period_label ) !== array_values( $labels ) ) {
				// Mixed or non-matching labels: aggregate only the matching rows.
				foreach ( $snapshot['rows'] as $row ) {
					if ( $row->period_label === $period_label ) {
						++$totals->rows_count;
						$totals->views   += (int) $row->views;
						$totals->revenue += (float) $row->revenue;
					}
				}
				continue;
			}
		}
		$totals->rows_count += (int) $snapshot['matched_rows'];
		$totals->revenue    += (float) $snapshot['revenue'];
		foreach ( $snapshot['rows'] as $row ) {
			$totals->views += (int) $row->views;
		}
	}

	return $totals;
}

/**
 * List distinct import batches (snapshots) for a blog, newest first.
 *
 * @param int $blog_id Blog ID (default: current blog).
 * @return array<int, \stdClass> Rows with import_batch, period_label, period_start, period_end, rows_count, imported_at, revenue, views.
 */
function extrachill_analytics_revenue_list_batches( $blog_id = 0 ) {
	$blog_id = $blog_id > 0 ? (int) $blog_id : get_current_blog_id();
	$groups  = array();
	foreach ( extrachill_analytics_revenue_get_rows( array( 'blog_id' => $blog_id ) ) as $row ) {
		$key = $row->import_batch . '|' . $row->period_label . '|' . $row->period_start . '|' . $row->period_end;
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = (object) array(
				'import_batch' => $row->import_batch,
				'period_label' => $row->period_label,
				'period_start' => $row->period_start,
				'period_end'   => $row->period_end,
				'rows_count'   => 0,
				'imported_at'  => '',
				'revenue'      => 0.0,
				'views'        => 0,
			);
		}
		++$groups[ $key ]->rows_count;
		$groups[ $key ]->revenue += (float) $row->revenue;
		$groups[ $key ]->views   += (int) $row->views;
		if ( strcmp( (string) $row->imported_at, $groups[ $key ]->imported_at ) > 0 ) {
			$groups[ $key ]->imported_at = (string) $row->imported_at;
		}
	}

	$batches = array_values( $groups );
	usort(
		$batches,
		static function ( $a, $b ) {
			return strcmp( $b->imported_at, $a->imported_at );
		}
	);
	return $batches;
}

/**
 * Aggregate legacy-shaped revenue rows into ARC points (one per period label).
 *
 * Pure. Distinct pages are keyed by post (resolved) or slug (unresolved), the
 * same storage-agnostic key the rollups use. Points sort chronologically; an
 * undated or "all-time" bucket sorts last.
 *
 * @param iterable $rows            Legacy-shaped rows (objects or arrays).
 * @param bool     $include_alltime Include the cumulative "all-time" bucket.
 * @return array<int, \stdClass> Points: period_label, period_start, period_end, pages, views, revenue.
 */
function extrachill_analytics_revenue_build_timeseries( $rows, $include_alltime = false ) {
	$points = array();
	foreach ( $rows as $row ) {
		$row   = (object) $row;
		$label = (string) $row->period_label;
		if ( ! $include_alltime && 'all-time' === $label ) {
			continue;
		}
		if ( ! isset( $points[ $label ] ) ) {
			$points[ $label ] = array(
				'period_label' => $label,
				'period_start' => null,
				'period_end'   => null,
				'pages'        => array(),
				'views'        => 0,
				'revenue'      => 0.0,
			);
		}
		$start = isset( $row->period_start ) ? (string) $row->period_start : '';
		$end   = isset( $row->period_end ) ? (string) $row->period_end : '';
		if ( '' !== $start && ( null === $points[ $label ]['period_start'] || $start < $points[ $label ]['period_start'] ) ) {
			$points[ $label ]['period_start'] = $start;
		}
		if ( '' !== $end && ( null === $points[ $label ]['period_end'] || $end > $points[ $label ]['period_end'] ) ) {
			$points[ $label ]['period_end'] = $end;
		}
		$page_key                               = ! empty( $row->post_id ) && (int) $row->post_id > 0 ? 'p' . (int) $row->post_id : 'u' . (string) $row->slug;
		$points[ $label ]['pages'][ $page_key ] = true;
		$points[ $label ]['views']             += (int) $row->views;
		$points[ $label ]['revenue']           += (float) $row->revenue;
	}

	$out = array();
	foreach ( $points as $point ) {
		$point['pages'] = count( $point['pages'] );
		$out[]          = (object) $point;
	}
	usort(
		$out,
		static function ( $a, $b ) {
			$a_last = 'all-time' === $a->period_label || null === $a->period_start;
			$b_last = 'all-time' === $b->period_label || null === $b->period_start;
			if ( $a_last !== $b_last ) {
				return $a_last ? 1 : -1;
			}
			$cmp = strcmp( (string) $a->period_start, (string) $b->period_start );
			return 0 !== $cmp ? $cmp : strcmp( $a->period_label, $b->period_label );
		}
	);
	return $out;
}

/**
 * The revenue ARC: revenue/views totals per time bucket, read from the ledger.
 *
 * @param array $args {
 *     Query args.
 *
 *     @type int  $blog_id         Blog ID (default: current blog).
 *     @type bool $include_alltime Include the cumulative "all-time" bucket (default: false).
 * }
 * @return array<int, \stdClass> Rows: period_label, period_start, period_end, pages, views, revenue.
 */
function extrachill_analytics_revenue_get_timeseries( array $args = array() ) {
	$blog_id = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
	return extrachill_analytics_revenue_build_timeseries(
		extrachill_analytics_revenue_get_rows( array( 'blog_id' => $blog_id ) ),
		! empty( $args['include_alltime'] )
	);
}
