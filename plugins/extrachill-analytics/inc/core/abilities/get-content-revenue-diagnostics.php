<?php
/**
 * Get Content Revenue Diagnostics Ability
 *
 * Structured data-integrity lens for the Mediavine revenue store. Where
 * extrachill/get-content-revenue rolls up revenue and extrachill/get-content-
 * revenue-pages ranks individual pages, THIS ability reports the HEALTH of the
 * store itself: is the latest period fresh, are monthly periods contiguous,
 * are any periods double-imported, does the read model reconcile against an
 * INDEPENDENT SQL aggregate, how much traffic is unresolved, are there zero-
 * view-revenue or impossible-value rows, and does stored RPM agree with derived
 * RPM?
 *
 * Why a sibling and not a diagnostic flag on the rollup: the rollup output
 * contract is rollups/totals/unresolved — it has no place for an array of
 * independent checks each carrying status/evidence/totals. The issue explicitly
 * permits narrowly scoped siblings. This ability reuses the SAME store, resolver,
 * and classifiers — no new tables, no parallel aggregation.
 *
 * Status contract: each check returns one of 'pass', 'warning', or 'fail'.
 *   - 'fail' is reserved for genuine data-integrity violations that make the
 *     numbers untrustworthy (negative/impossible values, a read model that does
 *     not reconcile against the independent SQL aggregate). Source quirks
 *     (zero-view revenue, duplicate batches, RPM variance) are NEVER 'fail'
 *     without evidence — they are 'warning' so the analyst sees them without the
 *     system crying wolf. The issue is explicit: do not claim source anomalies
 *     are corruption without evidence.
 *   - The overall status is fail if any check fails, else warning if any warns,
 *     else pass.
 *
 * Reconciliation note (non-tautological): totals_reconciliation compares the
 * PHP row-sum of get_rows() against an INDEPENDENT SQL SUM() aggregate
 * (get_scope_totals) over the same scope — two genuinely different read paths
 * (PHP hydration vs MySQL's aggregator). A divergence is a read-path regression
 * signal. content_unresolved_reconciliation separately verifies the resolved/
 * unresolved partition and dedupe are lossless and that rows with a stored
 * post_id that is no longer published are correctly routed to unresolved.
 *
 * Network/blog authorization: a current-site manage_options holder may read
 * their own blog; a CROSS-blog read requires manage_network_options. WP_CLI is
 * allowed. All resolution runs switch_to_blog($blog_id).
 *
 * @package ExtraChill\Analytics
 * @since 0.28.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-content-revenue-diagnostics ability.
 */
function extrachill_analytics_register_content_revenue_diagnostics_ability() {
	wp_register_ability(
		'extrachill/get-content-revenue-diagnostics',
		array(
			'label'               => __( 'Get Content Revenue Diagnostics Lens', 'extrachill-analytics' ),
			'description'         => __( 'Structured data-integrity checks for the Mediavine revenue store. Returns independent checks — latest-period freshness (by period boundary), missing periods, duplicate period/batch imports, totals reconciliation (against an independent SQL aggregate), content/unresolved reconciliation, resolution and content-format coverage (on deduped pages), revenue with zero pageviews, views with zero revenue, negative/impossible values (range-checked), stored-vs-derived RPM variance, and provenance coverage — each with a stable pass|warning|fail status, evidence, and totals. Source quirks are warnings, never corruption claims without evidence; fail is reserved for genuine integrity violations (negative/impossible values, a read model that does not reconcile). An empty scoped query never passes. Cross-blog reads require network admin. Reuses the same store, resolver, and classifiers — no new tables. CLI consumers map args and format output only.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'period'       => array(
						'type'        => 'string',
						'description' => __( 'Scope row-level checks to one time bucket, e.g. "2026-05" or "all-time". Empty = whole store (the integrity view). Freshness/missing/duplicate checks always consider the whole per-blog history.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_start' => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window start (Y-m-d).', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_end'   => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window end (Y-m-d).', 'extrachill-analytics' ),
						'default'     => '',
					),
					'import_batch' => array(
						'type'        => 'string',
						'description' => __( 'Restrict row-level checks to one import batch. Empty = all batches.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Blog ID whose revenue to diagnose. 0 = current blog. Cross-blog reads require manage_network_options.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'hostname'     => array(
						'type'        => 'string',
						'description' => __( 'Optional hostname for resolving relative slugs to posts. Empty = the target blog hostname.', 'extrachill-analytics' ),
						'default'     => '',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'        => array(
						'type'        => 'boolean',
						'description' => 'Whether the read succeeded.',
					),
					'blog_id'        => array(
						'type'        => 'integer',
						'description' => 'Blog ID diagnosed.',
					),
					'window'         => array(
						'type'        => 'string',
						'description' => 'Human-readable window label.',
					),
					'overall_status' => array(
						'type'        => 'string',
						'enum'        => array( 'pass', 'warning', 'fail' ),
						'description' => 'fail if any check failed, else warning if any warned, else pass.',
					),
					'summary'        => array(
						'type'       => 'object',
						'properties' => array(
							'pass'    => array(
								'type'        => 'integer',
								'description' => 'Count of checks at pass.',
							),
							'warning' => array(
								'type'        => 'integer',
								'description' => 'Count of checks at warning.',
							),
							'fail'    => array(
								'type'        => 'integer',
								'description' => 'Count of checks at fail.',
							),
							'total'   => array(
								'type'        => 'integer',
								'description' => 'Total checks.',
							),
						),
						'required'   => array( 'pass', 'warning', 'fail', 'total' ),
					),
					'checks'         => array(
						'type'        => 'array',
						'description' => 'Integrity checks.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'check'    => array(
									'type'        => 'string',
									'description' => 'Stable check identifier.',
								),
								'status'   => array(
									'type'        => 'string',
									'enum'        => array( 'pass', 'warning', 'fail' ),
									'description' => 'Check outcome.',
								),
								'evidence' => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => 'Human+machine readable facts.',
								),
								'totals'   => array(
									'type'        => 'object',
									'description' => 'Numeric totals / structured evidence.',
									'properties'  => extrachill_analytics_revenue_diagnostic_totals_schema(),
								),
							),
							'required'   => array( 'check', 'status', 'evidence', 'totals' ),
						),
					),
					'scope'          => array(
						'type'       => 'object',
						'properties' => array(
							'blog_id'          => array( 'type' => 'integer' ),
							'period'           => array( 'type' => 'string' ),
							'period_start'     => array( 'type' => 'string' ),
							'period_end'       => array( 'type' => 'string' ),
							'import_batch'     => array( 'type' => 'string' ),
							'selected_periods' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Distinct period_labels in scope.',
							),
							'selected_batches' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Distinct import_batches in scope.',
							),
						),
						'required'   => array( 'blog_id', 'period', 'period_start', 'period_end', 'import_batch', 'selected_periods', 'selected_batches' ),
					),
					'caveat'         => array(
						'type'        => 'string',
						'description' => 'Stable analyst caveat.',
					),
					'error'          => array(
						'type'        => 'string',
						'description' => 'Error message when success is false.',
					),
				),
				'required'   => array( 'success', 'blog_id', 'window', 'overall_status', 'summary', 'checks', 'scope', 'caveat' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_content_revenue_diagnostics',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
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
 * Return the union schema used by diagnostic check totals.
 *
 * Checks expose different totals, but every emitted property is declared here
 * so ability consumers do not receive an untyped object contract.
 *
 * @return array<string,array> Diagnostic totals properties.
 */
function extrachill_analytics_revenue_diagnostic_totals_schema() {
	$aggregate = array(
		'type'       => 'object',
		'properties' => array(
			'rows'    => array( 'type' => 'integer' ),
			'views'   => array( 'type' => 'integer' ),
			'revenue' => array( 'type' => 'number' ),
		),
		'required'   => array( 'rows', 'views', 'revenue' ),
	);

	$sample = array(
		'type'       => 'object',
		'properties' => array(
			'slug'        => array( 'type' => 'string' ),
			'revenue'     => array( 'type' => 'number' ),
			'views'       => array( 'type' => 'integer' ),
			'issues'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'source_rpm'  => array( 'type' => 'number' ),
			'derived_rpm' => array( 'type' => 'number' ),
			'relative'    => array( 'type' => 'number' ),
		),
	);

	return array(
		'periods'             => array( 'type' => 'integer' ),
		'rows'                => array( 'type' => 'integer' ),
		'has_dated'           => array( 'type' => 'boolean' ),
		'latest_period'       => array( 'type' => 'string' ),
		'latest_period_end'   => array( 'type' => 'string' ),
		'data_age_days'       => array( 'type' => array( 'integer', 'null' ) ),
		'dated_periods'       => array( 'type' => 'integer' ),
		'missing_periods'     => array( 'type' => 'integer' ),
		'missing'             => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
		'period_labels'       => array( 'type' => 'integer' ),
		'duplicate_labels'    => array( 'type' => 'integer' ),
		'duplicates'          => array(
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
		),
		'row_sum'             => $aggregate,
		'independent'         => $aggregate,
		'reconciled'          => array( 'type' => 'boolean' ),
		'raw_rows'            => array( 'type' => 'integer' ),
		'distinct_pages'      => array( 'type' => 'integer' ),
		'resolved_pages'      => array( 'type' => 'integer' ),
		'unresolved_pages'    => array( 'type' => 'integer' ),
		'stale_post_id_rows'  => array( 'type' => 'integer' ),
		'partition_complete'  => array( 'type' => 'boolean' ),
		'content_rows'        => array( 'type' => 'integer' ),
		'unresolved_rows'     => array( 'type' => 'integer' ),
		'partition_views'     => array( 'type' => 'integer' ),
		'partition_revenue'   => array( 'type' => 'number' ),
		'total_pages'         => array( 'type' => 'integer' ),
		'resolved_views'      => array( 'type' => 'integer' ),
		'unresolved_views'    => array( 'type' => 'integer' ),
		'unresolved_ratio'    => array( 'type' => 'number' ),
		'by_route_family'     => array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'integer' ),
		),
		'uncategorized_pages' => array( 'type' => 'integer' ),
		'uncategorized_ratio' => array( 'type' => 'number' ),
		'by_format'           => array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'integer' ),
		),
		'revenue'             => array( 'type' => 'number' ),
		'samples'             => array(
			'type'  => 'array',
			'items' => $sample,
		),
		'views'               => array( 'type' => 'integer' ),
		'ratio'               => array( 'type' => 'number' ),
		'checked'             => array( 'type' => 'integer' ),
		'high_variance'       => array( 'type' => 'integer' ),
		'high_rate_rows'      => array( 'type' => 'integer' ),
		'high_rate_samples'   => array(
			'type'  => 'array',
			'items' => $sample,
		),
		'missing_batch'       => array( 'type' => 'integer' ),
		'missing_period'      => array( 'type' => 'integer' ),
	);
}

/**
 * Execute callback for get-content-revenue-diagnostics ability.
 *
 * Owns the WordPress-dependent half: network/blog authorization, SQL (the
 * shared store helpers — including the independent scope aggregate for
 * reconciliation), switch_to_blog-scoped resolution + classification. Builds a
 * normalized snapshot and hands it to the pure builder.
 *
 * @param array $input Input parameters.
 * @return array Diagnostics response (consistent shape whether empty or not).
 */
function extrachill_analytics_ability_get_content_revenue_diagnostics( $input ) {
	$period       = isset( $input['period'] ) ? (string) $input['period'] : '';
	$period_start = isset( $input['period_start'] ) ? (string) $input['period_start'] : '';
	$period_end   = isset( $input['period_end'] ) ? (string) $input['period_end'] : '';
	$import_batch = isset( $input['import_batch'] ) ? (string) $input['import_batch'] : '';
	$blog_id      = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : get_current_blog_id();
	$hostname     = isset( $input['hostname'] ) ? trim( (string) $input['hostname'] ) : '';

	$scope_args = array(
		'blog_id'      => $blog_id,
		'import_batch' => $import_batch,
		'period_label' => $period,
		'period_start' => $period_start,
		'period_end'   => $period_end,
	);

	$scope_block_base = array(
		'blog_id'          => $blog_id,
		'period'           => $period,
		'period_start'     => $period_start,
		'period_end'       => $period_end,
		'import_batch'     => $import_batch,
		'selected_periods' => array(),
		'selected_batches' => array(),
	);

	$empty_summary = array(
		'pass'    => 0,
		'warning' => 0,
		'fail'    => 0,
		'total'   => 0,
	);

	$fail_shape = function ( $error_message ) use ( $blog_id, $scope_block_base, $empty_summary ) {
		return array(
			'success'        => false,
			'error'          => $error_message,
			'blog_id'        => $blog_id,
			'window'         => '',
			'overall_status' => 'fail',
			'summary'        => $empty_summary,
			'checks'         => array(),
			'scope'          => $scope_block_base,
			'caveat'         => $error_message,
		);
	};

	// Network/blog authorization.
	$auth = extrachill_analytics_revenue_authorize_blog_read( $blog_id );
	if ( true !== $auth ) {
		return $fail_shape( is_string( $auth ) ? $auth : __( 'Permission denied.', 'extrachill-analytics' ) );
	}

	// Independent SQL aggregate over the scope — the reconciliation check
	// compares this against the PHP row-sum. Genuinely different read paths.
	$scope_totals = extrachill_analytics_revenue_get_scope_totals( $scope_args );
	$independent  = array(
		'rows'    => $scope_totals && isset( $scope_totals->rows_count ) ? (int) $scope_totals->rows_count : 0,
		'views'   => $scope_totals && isset( $scope_totals->views ) ? (int) $scope_totals->views : 0,
		'revenue' => $scope_totals && isset( $scope_totals->revenue ) ? round( (float) $scope_totals->revenue, 4 ) : 0.0,
	);

	$rows = extrachill_analytics_revenue_get_rows( $scope_args );

	// switch_to_blog so resolution + publish-status + term/format classification
	// all run against the TARGET blog.
	$normalized = extrachill_analytics_revenue_run_in_blog(
		$blog_id,
		static function () use ( $rows, $hostname ) {
			$normalized = array();
			$hostname   = extrachill_analytics_revenue_resolution_hostname( $hostname );
			foreach ( $rows as $row ) {
				$post_id = (int) $row->post_id;
				if ( $post_id <= 0 && '' !== $row->slug ) {
					$post_id = extrachill_analytics_revenue_resolve_post_id( $row->url ? $row->url : $row->slug, $hostname );
				}
				$is_content = $post_id > 0 && 'publish' === get_post_status( $post_id );

				$views   = (int) $row->views;
				$revenue = (float) $row->revenue;

				$normalized[] = array(
					'period_label'             => (string) $row->period_label,
					'import_batch'             => (string) $row->import_batch,
					'period_start'             => $row->period_start ? (string) $row->period_start : null,
					'period_end'               => $row->period_end ? (string) $row->period_end : null,
					'imported_at'              => (string) $row->imported_at,
					'slug'                     => (string) $row->slug,
					'stored_post_id'           => (int) $row->post_id,
					'post_id'                  => $is_content ? $post_id : 0,
					'is_content'               => $is_content,
					'route_family'             => $is_content ? '' : extrachill_analytics_revenue_classify_route_family( $row->url ? $row->url : $row->slug ),
					'format'                   => $is_content ? extrachill_analytics_classify_format( $post_id ) : '',
					'views'                    => $views,
					'revenue'                  => $revenue,
					'source_rpm'               => (float) $row->rpm,
					'cpm'                      => (float) $row->cpm,
					'viewability'              => (float) $row->viewability,
					'fill_rate'                => (float) $row->fill_rate,
					'impressions_per_pageview' => (float) $row->impressions_per_pageview,
					'derived_rpm'              => $views > 0 ? round( $revenue / ( $views / 1000 ), 4 ) : 0.0,
				);
			}

			return $normalized;
		}
	);

	// Selected periods/batches in scope.
	$selected_periods = array();
	$selected_batches = array();
	foreach ( $normalized as $r ) {
		$selected_periods[ $r['period_label'] ] = true;
		$selected_batches[ $r['import_batch'] ] = true;
	}
	$scope_block_base['selected_periods'] = array_keys( $selected_periods );
	$scope_block_base['selected_batches'] = array_keys( $selected_batches );

	// Period-batch aggregates UNscoped by period/window/batch so freshness,
	// missing-period, and duplicate-batch checks see the whole per-blog history.
	$period_aggs = extrachill_analytics_revenue_list_batches( $blog_id );

	$periods = array();
	foreach ( $period_aggs as $p ) {
		$periods[] = array(
			'period_label' => (string) $p->period_label,
			'import_batch' => (string) $p->import_batch,
			'period_start' => $p->period_start ? (string) $p->period_start : null,
			'period_end'   => $p->period_end ? (string) $p->period_end : null,
			'rows'         => (int) $p->rows_count,
			'revenue'      => (float) $p->revenue,
			'views'        => (int) $p->views,
			'imported_at'  => (string) $p->imported_at,
		);
	}

	$built = extrachill_analytics_revenue_build_diagnostics(
		array(
			'scope'              => array(
				'blog_id'      => $blog_id,
				'period'       => $period,
				'period_start' => $period_start,
				'period_end'   => $period_end,
				'import_batch' => $import_batch,
			),
			'periods'            => $periods,
			'rows'               => $normalized,
			'independent_totals' => $independent,
		)
	);

	return array(
		'success'        => true,
		'blog_id'        => $blog_id,
		'window'         => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
		'overall_status' => $built['overall_status'],
		'summary'        => $built['summary'],
		'checks'         => $built['checks'],
		'scope'          => $scope_block_base,
		'caveat'         => __( 'Source quirks (zero-view revenue, duplicate period batches, stored-vs-derived RPM variance, unresolved routes) are warnings — disclosure, not corruption claims. fail is reserved for genuine integrity violations: negative/impossible values or a read model that does not reconcile against the independent SQL aggregate. Freshness uses the period boundary (period_end), not the import timestamp. Resolution/format coverage dedupe by page_key before counting. Freshness/missing-period/duplicate-batch checks consider the whole per-blog history (unscoped); row-level checks honor the requested period/window/batch scope. An empty scoped query never passes. Resolution coverage uses the same publish-status gate as the rollup/pages abilities.', 'extrachill-analytics' ),
	);
}

/**
 * Build the diagnostics checks from a normalized store snapshot.
 *
 * PURE — no WordPress / DB dependency — so every check's status/evidence/totals
 * contract is unit-testable in isolation. The execute_callback owns querying,
 * authorization, and resolution; this function owns the integrity logic.
 *
 * Snapshot shape:
 *   {
 *     scope: { blog_id, period, period_start, period_end, import_batch },
 *     periods: [ { period_label, import_batch, period_start, period_end, rows, revenue, views, imported_at }, ... ],
 *     rows: [ { period_label, import_batch, stored_post_id, post_id, is_content, route_family, format, views, revenue, source_rpm, derived_rpm, cpm, viewability, fill_rate, impressions_per_pageview }, ... ],
 *     independent_totals: { rows, views, revenue },  // from the independent SQL SUM() aggregate
 *   }
 *
 * @param array $snapshot Normalized snapshot.
 * @return array { checks, overall_status, summary, scope }
 */
function extrachill_analytics_revenue_build_diagnostics( array $snapshot ) {
	$periods = isset( $snapshot['periods'] ) && is_array( $snapshot['periods'] ) ? $snapshot['periods'] : array();
	$rows    = isset( $snapshot['rows'] ) && is_array( $snapshot['rows'] ) ? $snapshot['rows'] : array();
	$indep   = isset( $snapshot['independent_totals'] ) && is_array( $snapshot['independent_totals'] ) ? $snapshot['independent_totals'] : array(
		'rows'    => 0,
		'views'   => 0,
		'revenue' => 0.0,
	);
	$scope   = isset( $snapshot['scope'] ) && is_array( $snapshot['scope'] ) ? $snapshot['scope'] : array();

	$checks   = array();
	$checks[] = extrachill_analytics_revenue_diag_latest_period_freshness( $periods, $rows );
	$checks[] = extrachill_analytics_revenue_diag_missing_periods( $periods );
	$checks[] = extrachill_analytics_revenue_diag_duplicate_period_batches( $periods );
	$checks[] = extrachill_analytics_revenue_diag_totals_reconciliation( $rows, $indep );
	$checks[] = extrachill_analytics_revenue_diag_content_unresolved_reconciliation( $rows );
	$checks[] = extrachill_analytics_revenue_diag_resolution_coverage( $rows );
	$checks[] = extrachill_analytics_revenue_diag_format_coverage( $rows );
	$checks[] = extrachill_analytics_revenue_diag_zero_view_revenue( $rows );
	$checks[] = extrachill_analytics_revenue_diag_views_zero_revenue( $rows );
	$checks[] = extrachill_analytics_revenue_diag_negative_impossible_values( $rows );
	$checks[] = extrachill_analytics_revenue_diag_rpm_variance( $rows );
	$checks[] = extrachill_analytics_revenue_diag_provenance_coverage( $rows );

	$summary = array(
		'pass'    => 0,
		'warning' => 0,
		'fail'    => 0,
		'total'   => count( $checks ),
	);
	foreach ( $checks as $c ) {
		$status = isset( $c['status'] ) ? $c['status'] : 'warning';
		if ( isset( $summary[ $status ] ) ) {
			++$summary[ $status ];
		}
	}

	if ( $summary['fail'] > 0 ) {
		$overall = 'fail';
	} elseif ( $summary['warning'] > 0 ) {
		$overall = 'warning';
	} else {
		$overall = 'pass';
	}

	return array(
		'checks'         => $checks,
		'overall_status' => $overall,
		'summary'        => $summary,
		'scope'          => $scope,
	);
}

/**
 * Latest-period freshness: is there a dated period, and how old is its DATA?
 *
 * Freshness uses the period BOUNDARY (period_end) — the age of the data itself —
 * not the import timestamp (which only records when the operator happened to run
 * the import). Warn when the newest dated period's data is older than 45 days.
 *
 * @param array $periods Period-batch aggregates.
 * @param array $rows    Normalized rows (used for the empty-store case).
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_latest_period_freshness( array $periods, array $rows ) {
	if ( empty( $rows ) && empty( $periods ) ) {
		return array(
			'check'    => 'latest_period_freshness',
			'status'   => 'fail',
			'evidence' => array( 'No revenue snapshots imported for this blog.' ),
			'totals'   => array(
				'periods' => 0,
				'rows'    => 0,
			),
		);
	}

	// Find the most recent DATED period by period_end (the data's boundary).
	$latest_label = '';
	$latest_end   = '';
	$has_dated    = false;
	foreach ( $periods as $p ) {
		if ( ! empty( $p['period_end'] ) ) {
			$has_dated = true;
			$end       = (string) $p['period_end'];
			if ( '' === $latest_end || $end > $latest_end ) {
				$latest_end   = $end;
				$latest_label = isset( $p['period_label'] ) ? (string) $p['period_label'] : '';
			}
		}
	}

	$totals = array(
		'periods'           => count( $periods ),
		'has_dated'         => $has_dated,
		'latest_period'     => $latest_label,
		'latest_period_end' => $latest_end,
		'data_age_days'     => null,
	);

	if ( ! $has_dated ) {
		return array(
			'check'    => 'latest_period_freshness',
			'status'   => 'warning',
			'evidence' => array( 'Only the flat "all-time" bucket is present — no dated (monthly) periods imported. Import date-ranged CSVs with --period=YYYY-MM to enable freshness tracking.' ),
			'totals'   => $totals,
		);
	}

	// Data age = now - latest period_end.
	$stale    = false;
	$age_days = null;
	if ( '' !== $latest_end ) {
		$end_ts = strtotime( $latest_end );
		if ( false !== $end_ts ) {
			$age_days = (int) floor( ( time() - $end_ts ) / DAY_IN_SECONDS );
			$stale    = $age_days > 45;
		}
	}
	$totals['data_age_days'] = $age_days;

	$evidence   = array();
	$status     = 'pass';
	$evidence[] = "Newest dated period: {$latest_label} (data through {$latest_end}).";
	if ( null !== $age_days ) {
		$evidence[] = "Data is {$age_days} day(s) old (by period boundary, not import time).";
	}
	if ( $stale ) {
		$status     = 'warning';
		$evidence[] = 'Newest dated data is older than 45 days — the revenue arc may be stale.';
	}

	return array(
		'check'    => 'latest_period_freshness',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => $totals,
	);
}

/**
 * Missing-period detection: are there gaps in the YYYY-MM dated sequence?
 *
 * @param array $periods Period-batch aggregates.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_missing_periods( array $periods ) {
	$dated = array();
	foreach ( $periods as $p ) {
		$label = isset( $p['period_label'] ) ? (string) $p['period_label'] : '';
		if ( preg_match( '/^\d{4}-\d{2}$/', $label ) ) {
			$dated[ $label ] = true;
		}
	}

	$sorted = array_keys( $dated );
	sort( $sorted );
	$n = count( $sorted );

	$missing = array();
	if ( $n >= 2 ) {
		$first  = $sorted[0];
		$last   = $sorted[ $n - 1 ];
		$cursor = strtotime( $first . '-01' );
		$end_ts = strtotime( $last . '-01' );
		while ( false !== $cursor && false !== $end_ts && $cursor <= $end_ts ) {
			$bucket = gmdate( 'Y-m', $cursor );
			if ( ! isset( $dated[ $bucket ] ) ) {
				$missing[] = $bucket;
			}
			$cursor = strtotime( '+1 month', $cursor );
		}
	}

	$status   = empty( $missing ) ? 'pass' : 'warning';
	$evidence = array();
	if ( empty( $missing ) ) {
		$evidence[] = $n >= 2
			? sprintf( 'Dated periods are contiguous from %s to %s (%d period(s)).', $sorted[0], $sorted[ $n - 1 ], $n )
			: sprintf( '%d dated period(s) — no sequence to gap-check.', $n );
	} else {
		$evidence[] = sprintf( 'Gap(s) in the monthly sequence: %s.', implode( ', ', $missing ) );
		$evidence[] = 'A gap may mean a month was never imported, or was imported under a non-YYYY-MM label.';
	}

	return array(
		'check'    => 'missing_periods',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'dated_periods'   => $n,
			'missing_periods' => count( $missing ),
			'missing'         => $missing,
		),
	);
}

/**
 * Duplicate-period-batch detection: same period_label across multiple batches.
 *
 * This is NOT corruption — re-imports are idempotent via REPLACE INTO on the
 * unique (blog, slug, period, batch) key. But if two DIFFERENT batches carry the
 * SAME period_label, a rollup that ignores batch can double-count. Disclose it.
 *
 * @param array $periods Period-batch aggregates.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_duplicate_period_batches( array $periods ) {
	$by_label = array();
	foreach ( $periods as $p ) {
		$label = isset( $p['period_label'] ) ? (string) $p['period_label'] : '';
		if ( '' === $label ) {
			continue;
		}
		if ( ! isset( $by_label[ $label ] ) ) {
			$by_label[ $label ] = array();
		}
		$by_label[ $label ][] = isset( $p['import_batch'] ) ? (string) $p['import_batch'] : '';
	}

	$duplicates = array();
	foreach ( $by_label as $label => $batches ) {
		$unique = array_unique( $batches );
		if ( count( $unique ) > 1 ) {
			$duplicates[ $label ] = array_values( $unique );
		}
	}

	$status   = empty( $duplicates ) ? 'pass' : 'warning';
	$evidence = array();
	if ( empty( $duplicates ) ) {
		$evidence[] = 'No period label spans more than one import batch.';
	} else {
		$evidence[] = 'One or more period labels appear across multiple import batches — a rollup that ignores --batch may double-count these:';
		foreach ( $duplicates as $label => $batches ) {
			$evidence[] = "  {$label}: " . implode( ', ', $batches );
		}
		$evidence[] = 'Pass --batch to scope, or re-import under one batch to collapse them.';
	}

	return array(
		'check'    => 'duplicate_period_batches',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'period_labels'    => count( $by_label ),
			'duplicate_labels' => count( $duplicates ),
			'duplicates'       => $duplicates,
		),
	);
}

/**
 * Totals reconciliation: PHP row-sum vs an INDEPENDENT SQL SUM() aggregate.
 *
 * Non-tautological by design: the independent_totals come from MySQL's SUM()
 * aggregator (get_scope_totals) over the canonical scope, while the row-sum is
 * a PHP loop over get_rows() — two genuinely different read paths. A divergence
 * is a read-path regression signal (row hydration, type coercion, a silent row
 * cap), NOT a Mediavine data issue. This is the one check where 'fail' is
 * warranted on a numeric disagreement.
 *
 * An empty scoped query is a warning (cannot reconcile nothing) so it never
 * silently passes.
 *
 * @param array $rows Normalized rows (the PHP-side sum).
 * @param array $indep Independent SQL aggregate { rows, views, revenue }.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_totals_reconciliation( array $rows, array $indep ) {
	$sum_rows    = count( $rows );
	$sum_views   = 0;
	$sum_revenue = 0.0;
	foreach ( $rows as $r ) {
		$sum_views   += isset( $r['views'] ) ? (int) $r['views'] : 0;
		$sum_revenue += isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
	}
	$sum_revenue = round( $sum_revenue, 4 );

	$indep_rows    = isset( $indep['rows'] ) ? (int) $indep['rows'] : 0;
	$indep_views   = isset( $indep['views'] ) ? (int) $indep['views'] : 0;
	$indep_revenue = round( isset( $indep['revenue'] ) ? (float) $indep['revenue'] : 0.0, 4 );

	// Empty scoped query → cannot reconcile, never pass.
	if ( 0 === $sum_rows && 0 === $indep_rows ) {
		return array(
			'check'    => 'totals_reconciliation',
			'status'   => 'warning',
			'evidence' => array( 'No rows in the requested scope — reconciliation cannot run, so the scope does not pass.', 'If a scope was expected, check the period/batch/window or import data.' ),
			'totals'   => array(
				'row_sum'     => array(
					'rows'    => $sum_rows,
					'views'   => $sum_views,
					'revenue' => $sum_revenue,
				),
				'independent' => array(
					'rows'    => $indep_rows,
					'views'   => $indep_views,
					'revenue' => $indep_revenue,
				),
				'reconciled'  => false,
			),
		);
	}

	$match    = ( $sum_rows === $indep_rows ) && ( $sum_views === $indep_views ) && ( abs( $sum_revenue - $indep_revenue ) < 0.01 );
	$status   = $match ? 'pass' : 'fail';
	$evidence = array();
	if ( $match ) {
		$evidence[] = "Row-sum reconciles with the independent SQL aggregate: {$sum_rows} row(s), {$sum_views} views, \${$sum_revenue} revenue.";
	} else {
		$evidence[] = 'Row-sum does NOT reconcile with the independent SQL aggregate.';
		if ( $sum_rows !== $indep_rows ) {
			$evidence[] = "Rows: row-sum {$sum_rows} vs independent {$indep_rows}.";
		}
		if ( $sum_views !== $indep_views ) {
			$evidence[] = "Views: row-sum {$sum_views} vs independent {$indep_views}.";
		}
		if ( abs( $sum_revenue - $indep_revenue ) >= 0.01 ) {
			$evidence[] = "Revenue: row-sum {$sum_revenue} vs independent {$indep_revenue}.";
		}
		$evidence[] = 'A reconciliation mismatch indicates the read model is self-inconsistent — a regression, not a Mediavine data issue.';
	}

	return array(
		'check'    => 'totals_reconciliation',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'row_sum'     => array(
				'rows'    => $sum_rows,
				'views'   => $sum_views,
				'revenue' => $sum_revenue,
			),
			'independent' => array(
				'rows'    => $indep_rows,
				'views'   => $indep_views,
				'revenue' => $indep_revenue,
			),
			'reconciled'  => $match,
		),
	);
}

/**
 * Content/unresolved reconciliation: verify the resolved/unresolved partition
 * and dedupe are lossless, and disclose stale stored post_ids.
 *
 * Meaningful checks:
 *   - Every row is classified as exactly one of content / unresolved (partition
 *     completeness).
 *   - A row with a stored post_id > 0 that is NOT 'publish' is correctly routed
 *     to unresolved (a stale post — trashed since import). Disclosed as evidence.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_content_unresolved_reconciliation( array $rows ) {
	$content_keys    = array();
	$unresolved_keys = array();
	$stale_post_ids  = 0;
	$content_rows    = 0;
	$unresolved_rows = 0;
	$partition_views = 0;
	$partition_rev   = 0.0;

	foreach ( $rows as $r ) {
		$key = ! empty( $r['is_content'] )
			? 'p' . (int) $r['post_id']
			: 'u' . md5( isset( $r['slug'] ) ? (string) $r['slug'] : '' );

		if ( ! empty( $r['is_content'] ) ) {
			$content_keys[ $key ] = true;
			++$content_rows;
		} else {
			$unresolved_keys[ $key ] = true;
			++$unresolved_rows;
			// Stored a post_id at import that no longer resolves/publishes.
			if ( ! empty( $r['stored_post_id'] ) && (int) $r['stored_post_id'] > 0 ) {
				++$stale_post_ids;
			}
		}
		$partition_views += isset( $r['views'] ) ? (int) $r['views'] : 0;
		$partition_rev   += isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
	}

	$content_n    = count( $content_keys );
	$unresolved_n = count( $unresolved_keys );
	$distinct_n   = $content_n + $unresolved_n;
	$raw_n        = count( $rows );
	$complete     = ( $content_rows + $unresolved_rows ) === $raw_n;

	// The partition is complete by construction (every row is one or the other).
	// The meaningful signal here is the stale-post-id count and the
	// distinct-vs-raw ratio (dedupe losslessness is reported as a ratio).
	$status     = $complete ? 'pass' : 'fail';
	$evidence   = array();
	$evidence[] = "{$content_n} distinct resolved page(s), {$unresolved_n} distinct unresolved page(s), {$distinct_n} distinct total ({$raw_n} raw row(s)).";
	if ( $stale_post_ids > 0 ) {
		$status     = $complete ? 'warning' : 'fail';
		$evidence[] = "{$stale_post_ids} unresolved row(s) carry a stored post_id that is no longer published (stale — the post was trashed since import).";
		$evidence[] = 'These are correctly routed to the unresolved cohort (not counted as content).';
	}

	return array(
		'check'    => 'content_unresolved_reconciliation',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'raw_rows'           => $raw_n,
			'distinct_pages'     => $distinct_n,
			'resolved_pages'     => $content_n,
			'unresolved_pages'   => $unresolved_n,
			'stale_post_id_rows' => $stale_post_ids,
			'partition_complete' => $complete,
			'content_rows'       => $content_rows,
			'unresolved_rows'    => $unresolved_rows,
			'partition_views'    => $partition_views,
			'partition_revenue'  => round( $partition_rev, 4 ),
		),
	);
}

/**
 * Resolution coverage: resolved published posts vs unresolved routes, counted on
 * DEDUPED pages (not raw rows) so a post with N URL variants counts once.
 *
 * A high unresolved ratio is a coverage signal (much of the imported traffic is
 * non-content routes), not corruption.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_resolution_coverage( array $rows ) {
	$resolved         = array();
	$unresolved       = array();
	$by_family        = array();
	$resolved_views   = 0;
	$unresolved_views = 0;

	foreach ( $rows as $r ) {
		$key = ! empty( $r['is_content'] )
			? 'p' . (int) $r['post_id']
			: 'u' . md5( isset( $r['slug'] ) ? (string) $r['slug'] : '' );

		if ( ! empty( $r['is_content'] ) ) {
			$resolved[ $key ] = true;
			$resolved_views  += isset( $r['views'] ) ? (int) $r['views'] : 0;
		} else {
			if ( ! isset( $unresolved[ $key ] ) ) {
				$family = isset( $r['route_family'] ) ? (string) $r['route_family'] : 'other';
				if ( ! isset( $by_family[ $family ] ) ) {
					$by_family[ $family ] = 0;
				}
				++$by_family[ $family ];
			}
			$unresolved[ $key ] = true;
			$unresolved_views  += isset( $r['views'] ) ? (int) $r['views'] : 0;
		}
	}

	$resolved_n   = count( $resolved );
	$unresolved_n = count( $unresolved );
	$total        = $resolved_n + $unresolved_n;
	$ratio        = $total > 0 ? $unresolved_n / $total : 0.0;
	// Warn above 50% — most distinct pages are non-content routes.
	$status     = ( $total > 0 && $ratio > 0.50 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$resolved_n} distinct resolved page(s), {$unresolved_n} distinct unresolved page(s) of {$total} total (deduped).";
	if ( $total > 0 ) {
		$evidence[] = sprintf( 'Unresolved ratio: %.1f%%.', $ratio * 100 );
	}
	if ( 'warning' === $status ) {
		$evidence[] = 'More than half of distinct pages are unresolved routes — coverage signal, not corruption. Legacy .html ghosts, taxonomy archives, and app/account routes are expected here.';
	}
	arsort( $by_family );

	return array(
		'check'    => 'resolution_coverage',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'total_pages'      => $total,
			'resolved_pages'   => $resolved_n,
			'unresolved_pages' => $unresolved_n,
			'resolved_views'   => $resolved_views,
			'unresolved_views' => $unresolved_views,
			'unresolved_ratio' => round( $ratio, 4 ),
			'by_route_family'  => $by_family,
		),
	);
}

/**
 * Content-format coverage: resolved posts that classified as 'uncategorized',
 * counted on DEDUPED pages (not raw rows).
 *
 * A high uncategorized ratio means the format classifier has no mapping for
 * many posts — a taxonomy gap, not corruption. Unresolved routes are excluded
 * (they never reach the classifier).
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_format_coverage( array $rows ) {
	$by_format     = array();
	$uncategorized = 0;

	foreach ( $rows as $r ) {
		if ( empty( $r['is_content'] ) ) {
			continue;
		}
		// Dedupe by post id — one vote per resolved page.
		$key = 'p' . (int) $r['post_id'];
		if ( isset( $by_format['_seen'][ $key ] ) ) {
			continue;
		}
		$by_format['_seen'][ $key ] = true;

		$format = isset( $r['format'] ) ? (string) $r['format'] : 'uncategorized';
		if ( '' === $format ) {
			$format = 'uncategorized';
		}
		if ( ! isset( $by_format[ $format ] ) ) {
			$by_format[ $format ] = 0;
		}
		++$by_format[ $format ];
		if ( 'uncategorized' === $format ) {
			++$uncategorized;
		}
	}
	unset( $by_format['_seen'] );

	$resolved_total = 0;
	foreach ( $by_format as $count ) {
		$resolved_total += $count;
	}

	$ratio = $resolved_total > 0 ? $uncategorized / $resolved_total : 0.0;
	// Warn above 30% — a lot of distinct resolved pages fall outside every bucket.
	$status     = ( $resolved_total > 0 && $ratio > 0.30 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$uncategorized} of {$resolved_total} distinct resolved page(s) classified as 'uncategorized'.";
	if ( 'warning' === $status ) {
		$evidence[] = 'Over 30% of resolved pages match no content-format bucket — a taxonomy-mapping gap. Add their category slugs to extrachill_analytics_format_category_map().';
	} else {
		$evidence[] = 'Format classifier covers the majority of resolved pages.';
	}
	arsort( $by_format );

	return array(
		'check'    => 'format_coverage',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'resolved_pages'      => $resolved_total,
			'uncategorized_pages' => $uncategorized,
			'uncategorized_ratio' => round( $ratio, 4 ),
			'by_format'           => $by_format,
		),
	);
}

/**
 * Zero-view revenue: rows with revenue > 0 but views = 0.
 *
 * A Mediavine source quirk (rounding, sub-threshold fills, a missed views cell).
 * Disclosed as a warning — never corruption.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_zero_view_revenue( array $rows ) {
	$count   = 0;
	$revenue = 0.0;
	$samples = array();

	foreach ( $rows as $r ) {
		$views = isset( $r['views'] ) ? (int) $r['views'] : 0;
		$rev   = isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
		if ( $views <= 0 && $rev > 0 ) {
			++$count;
			$revenue += $rev;
			if ( count( $samples ) < 5 ) {
				$samples[] = array(
					'slug'    => isset( $r['slug'] ) ? (string) $r['slug'] : '',
					'revenue' => round( $rev, 4 ),
					'views'   => 0,
				);
			}
		}
	}

	$status   = $count > 0 ? 'warning' : 'pass';
	$evidence = array();
	if ( 0 === $count ) {
		$evidence[] = 'No rows report revenue with zero pageviews.';
	} else {
		$evidence[] = "{$count} row(s) report revenue (total \${$revenue}) with zero pageviews.";
		$evidence[] = 'A Mediavine source quirk — the pages-ability flags these zero_views with derived_rpm = 0 and revenue preserved.';
	}

	return array(
		'check'    => 'zero_view_revenue',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'    => $count,
			'revenue' => round( $revenue, 4 ),
			'samples' => $samples,
		),
	);
}

/**
 * Views-with-zero-revenue: rows with views > 0 but revenue = 0.
 *
 * Usually sub-threshold ad fills on low-traffic pages. Warning at high volume.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_views_zero_revenue( array $rows ) {
	$count      = 0;
	$views      = 0;
	$total_rows = count( $rows );

	foreach ( $rows as $r ) {
		$v   = isset( $r['views'] ) ? (int) $r['views'] : 0;
		$rev = isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
		if ( $v > 0 && $rev <= 0 ) {
			++$count;
			$views += $v;
		}
	}

	$ratio = $total_rows > 0 ? $count / $total_rows : 0.0;
	// Warn above 20% — a sizeable share of rows earned nothing.
	$status     = ( $total_rows > 0 && $ratio > 0.20 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$count} row(s) with {$views} views report zero revenue.";
	if ( 'warning' === $status ) {
		$evidence[] = sprintf( 'Over 20%% of rows (%.1f%%) are viewed-but-zero-revenue — typically sub-threshold ad fills on low-traffic pages.', $ratio * 100 );
	}

	return array(
		'check'    => 'views_zero_revenue',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'  => $count,
			'views' => $views,
			'ratio' => round( $ratio, 4 ),
		),
	);
}

/**
 * Negative / impossible values: negative views/revenue/rpm/cpm, OR rates/views
 * outside their structurally valid ranges. This is the one check where 'fail' is
 * warranted on a genuine data-integrity violation.
 *
 * Range contract:
 *   - views, revenue, rpm, cpm: must be >= 0.
 *   - viewability, fill_rate: must be in [0, 100] (percentages).
 *   - source/derived rpm, cpm: must be finite and non-negative. High values are
 *     warnings, not failures: a small pageview denominator can legitimately
 *     produce an extreme rate.
 *   - impressions/pageview: must be in [0, 1000].
 *   - views: flagged as impossibly high above 1,000,000,000.
 *   - every floating-point metric must be finite.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_negative_impossible_values( array $rows ) {
	$bad               = array();
	$high_rate_rows    = 0;
	$high_rate_samples = array();
	$samples           = array();

	foreach ( $rows as $r ) {
		$issues      = array();
		$slug        = isset( $r['slug'] ) ? (string) $r['slug'] : '';
		$views       = isset( $r['views'] ) ? (int) $r['views'] : 0;
		$rev         = isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
		$rpm         = isset( $r['source_rpm'] ) ? (float) $r['source_rpm'] : 0.0;
		$derived_rpm = isset( $r['derived_rpm'] ) ? (float) $r['derived_rpm'] : 0.0;
		$cpm         = isset( $r['cpm'] ) ? (float) $r['cpm'] : 0.0;
		$viewability = isset( $r['viewability'] ) ? (float) $r['viewability'] : 0.0;
		$fill_rate   = isset( $r['fill_rate'] ) ? (float) $r['fill_rate'] : 0.0;
		$impressions = isset( $r['impressions_per_pageview'] ) ? (float) $r['impressions_per_pageview'] : 0.0;

		$finite_metrics = array(
			'revenue'                  => $rev,
			'source rpm'               => $rpm,
			'derived rpm'              => $derived_rpm,
			'cpm'                      => $cpm,
			'viewability'              => $viewability,
			'fill_rate'                => $fill_rate,
			'impressions per pageview' => $impressions,
		);
		foreach ( $finite_metrics as $label => $value ) {
			if ( ! is_finite( $value ) ) {
				$issues[] = "non-finite {$label}";
			}
		}

		if ( $views < 0 ) {
			$issues[] = "negative views ({$views})";
		}
		if ( $views > 1000000000 ) {
			$issues[] = "impossibly high views ({$views})";
		}
		if ( $rev < 0 ) {
			$issues[] = "negative revenue ({$rev})";
		}
		if ( $rpm < 0 ) {
			$issues[] = "negative source rpm ({$rpm})";
		}
		if ( $derived_rpm < 0 ) {
			$issues[] = "negative derived rpm ({$derived_rpm})";
		}
		if ( $cpm < 0 ) {
			$issues[] = "negative cpm ({$cpm})";
		}
		$high_rates = array();
		if ( $rpm > 1000 ) {
			$high_rates[] = "high source rpm ({$rpm})";
		}
		if ( $derived_rpm > 1000 ) {
			$high_rates[] = "high derived rpm ({$derived_rpm})";
		}
		if ( $cpm > 1000 ) {
			$high_rates[] = "high cpm ({$cpm})";
		}
		if ( $viewability < 0 || $viewability > 100 ) {
			$issues[] = "viewability out of [0,100] ({$viewability})";
		}
		if ( $fill_rate < 0 || $fill_rate > 100 ) {
			$issues[] = "fill_rate out of [0,100] ({$fill_rate})";
		}
		if ( $impressions < 0 || $impressions > 1000 ) {
			$issues[] = "impressions_per_pageview out of [0,1000] ({$impressions})";
		}

		if ( ! empty( $issues ) ) {
			$bad[] = $r;
			if ( count( $samples ) < 5 ) {
				$samples[] = array(
					'slug'   => $slug,
					'issues' => $issues,
				);
			}
		}
		if ( ! empty( $high_rates ) ) {
			++$high_rate_rows;
			if ( count( $high_rate_samples ) < 5 ) {
				$high_rate_samples[] = array(
					'slug'   => $slug,
					'issues' => $high_rates,
				);
			}
		}
	}

	$status   = ! empty( $bad ) ? 'fail' : ( $high_rate_rows > 0 ? 'warning' : 'pass' );
	$evidence = array();
	if ( ! empty( $bad ) ) {
		$evidence[] = count( $bad ) . ' row(s) with negative or impossible values:';
		foreach ( $samples as $s ) {
			$evidence[] = "  {$s['slug']}: " . implode( '; ', $s['issues'] );
		}
		$evidence[] = 'Negative/out-of-range values are genuine integrity violations — investigate the import source.';
	}
	if ( $high_rate_rows > 0 ) {
		$evidence[] = $high_rate_rows . ' row(s) have unusually high rate values (up to 5 shown):';
		foreach ( $high_rate_samples as $s ) {
			$evidence[] = "  {$s['slug']}: " . implode( '; ', $s['issues'] );
		}
		$evidence[] = 'High rate values are anomalies, not structurally impossible values; inspect source metrics and their denominators before treating them as corruption.';
	}
	if ( empty( $bad ) && 0 === $high_rate_rows ) {
		$evidence[] = 'No negative, non-finite, or out-of-range values detected (views/revenue/rpm/cpm >= 0; viewability/fill_rate in [0,100]; impressions/pageview in [0,1000]).';
	}

	return array(
		'check'    => 'negative_impossible_values',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'              => count( $bad ),
			'samples'           => $samples,
			'high_rate_rows'    => $high_rate_rows,
			'high_rate_samples' => $high_rate_samples,
		),
	);
}

/**
 * Stored-vs-derived RPM variance: where Mediavine's reported rpm disagrees with
 * revenue/(views/1000). Disclosure — the two RPMs are kept distinct on purpose.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_rpm_variance( array $rows ) {
	$high_variance = 0;
	$checked       = 0;
	$samples       = array();

	foreach ( $rows as $r ) {
		$views = isset( $r['views'] ) ? (int) $r['views'] : 0;
		if ( $views <= 0 ) {
			continue;
		}
		++$checked;

		$derived = isset( $r['derived_rpm'] ) ? (float) $r['derived_rpm'] : 0.0;
		$source  = isset( $r['source_rpm'] ) ? (float) $r['source_rpm'] : 0.0;

		$denom    = max( abs( $source ), 0.0001 );
		$relative = abs( $derived - $source ) / $denom;

		if ( $relative > 0.20 ) {
			++$high_variance;
			if ( count( $samples ) < 5 ) {
				$samples[] = array(
					'slug'        => isset( $r['slug'] ) ? (string) $r['slug'] : '',
					'source_rpm'  => round( $source, 4 ),
					'derived_rpm' => round( $derived, 4 ),
					'relative'    => round( $relative, 4 ),
				);
			}
		}
	}

	$ratio = $checked > 0 ? $high_variance / $checked : 0.0;
	// Warn above 10% — a sizeable share of rows disagree by more than 20%.
	$status     = ( $checked > 0 && $ratio > 0.10 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$high_variance} of {$checked} views-positive row(s) differ by >20% between source rpm and derived rpm.";
	if ( 'warning' === $status ) {
		$evidence[] = 'The two RPMs disagree widely — they are reported separately on purpose (source_rpm vs derived_rpm). Rank on whichever fits the question.';
	}

	return array(
		'check'    => 'stored_vs_derived_rpm_variance',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'checked'       => $checked,
			'high_variance' => $high_variance,
			'ratio'         => round( $ratio, 4 ),
			'samples'       => $samples,
		),
	);
}

/**
 * Source/import provenance coverage: rows missing an import_batch or period_label.
 *
 * The import path always stamps both, so any gap is a regression signal.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_provenance_coverage( array $rows ) {
	$total          = count( $rows );
	$missing_batch  = 0;
	$missing_period = 0;

	foreach ( $rows as $r ) {
		$batch = isset( $r['import_batch'] ) ? (string) $r['import_batch'] : '';
		$label = isset( $r['period_label'] ) ? (string) $r['period_label'] : '';
		if ( '' === $batch ) {
			++$missing_batch;
		}
		if ( '' === $label ) {
			++$missing_period;
		}
	}

	$missing  = $missing_batch + $missing_period;
	$status   = ( $total > 0 && $missing > 0 ) ? 'warning' : 'pass';
	$evidence = array();
	if ( 0 === $missing ) {
		$evidence[] = "All {$total} row(s) carry import_batch and period_label provenance.";
	} else {
		$evidence[] = "{$missing_batch} row(s) missing import_batch, {$missing_period} missing period_label.";
		$evidence[] = 'The import path always stamps both — a gap suggests a write-path regression.';
	}

	return array(
		'check'    => 'provenance_coverage',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'           => $total,
			'missing_batch'  => $missing_batch,
			'missing_period' => $missing_period,
		),
	);
}
