<?php
/**
 * Behavioral + contract tests for the content-revenue diagnostics lens (#141).
 *
 * The pure core (every check's status/evidence/totals + the summary rollup) is
 * unit-tested directly against a synthesized snapshot; the WordPress-dependent
 * resolution gate is locked down via a source-string contract on the callback.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue-diagnostics.php';

/**
 * Verify diagnostics contracts.
 */
final class GetContentRevenueDiagnosticsTest extends TestCase {

	/**
	 * Helper: a normalized row.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function row( array $over = array() ) {
		return array_merge(
			array(
				'period_label'             => '2026-05',
				'import_batch'             => 'mv-2026-05',
				'period_start'             => '2026-05-01',
				'period_end'               => '2026-05-31',
				'imported_at'              => gmdate( 'Y-m-d H:i:s' ),
				'slug'                     => '/some-post/',
				'stored_post_id'           => 1,
				'post_id'                  => 1,
				'is_content'               => true,
				'route_family'             => '',
				'format'                   => 'song-meaning',
				'views'                    => 1000,
				'revenue'                  => 100.0,
				'source_rpm'               => 100.0,
				'cpm'                      => 5.0,
				'viewability'              => 80.0,
				'fill_rate'                => 90.0,
				'impressions_per_pageview' => 2.0,
				'derived_rpm'              => 100.0,
			),
			$over
		);
	}

	/**
	 * Helper: a period-batch aggregate.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function period( array $over = array() ) {
		return array_merge(
			array(
				'period_label' => '2026-05',
				'import_batch' => 'mv-2026-05',
				'period_start' => '2026-05-01',
				'period_end'   => '2026-05-31',
				'rows'         => 100,
				'revenue'      => 1000.0,
				'views'        => 50000,
				'imported_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			$over
		);
	}

	/**
	 * Helper: find one check by name in a built result.
	 *
	 * @param array  $built Built diagnostics.
	 * @param string $name  Check name.
	 * @return array|null
	 */
	private function check( array $built, $name ) {
		foreach ( $built['checks'] as $c ) {
			if ( $name === $c['check'] ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Helper: build independent_totals that reconcile with a row set (pass case).
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function reconciling_totals( array $rows ) {
		$views   = 0;
		$revenue = 0.0;
		foreach ( $rows as $r ) {
			$views   += isset( $r['views'] ) ? (int) $r['views'] : 0;
			$revenue += isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
		}
		return array(
			'rows'    => count( $rows ),
			'views'   => $views,
			'revenue' => round( $revenue, 4 ),
		);
	}

	/**
	 * Empty store → freshness fails, overall fail.
	 */
	public function test_empty_store_freshness_fails(): void {
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array(),
				'rows'               => array(),
				'independent_totals' => array(
					'rows'    => 0,
					'views'   => 0,
					'revenue' => 0.0,
				),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		$this->assertSame( 'fail', $freshness['status'] );
		$this->assertSame( 'fail', $built['overall_status'] );
	}

	/**
	 * Only the flat "all-time" bucket → freshness warns (no dated periods).
	 */
	public function test_only_alltime_bucket_warns(): void {
		$rows  = array( $this->row() );
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array(
					$this->period(
						array(
							'period_label' => 'all-time',
							'period_start' => null,
							'period_end'   => null,
						)
					),
				),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		$this->assertSame( 'warning', $freshness['status'] );
	}

	/**
	 * A recent dated period → freshness passes.
	 */
	public function test_recent_dated_period_passes_freshness(): void {
		// period_end is today → data age 0 days → pass.
		$rows  = array( $this->row( array( 'period_end' => gmdate( 'Y-m-d' ) ) ) );
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period( array( 'period_end' => gmdate( 'Y-m-d' ) ) ) ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		$this->assertSame( 'pass', $freshness['status'] );
	}

	/**
	 * M2: freshness is driven by the period BOUNDARY (period_end), not import date.
	 * A period whose data ended >45 days ago warns even if imported today.
	 */
	public function test_stale_data_by_period_boundary_warns(): void {
		$old_end      = gmdate( 'Y-m-d', strtotime( '-60 days' ) ); // data ended 60d ago.
		$imported_now = gmdate( 'Y-m-d H:i:s' ); // but imported just now.

		$rows = array(
			$this->row(
				array(
					'period_end'  => $old_end,
					'imported_at' => $imported_now,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array(
					$this->period(
						array(
							'period_end'  => $old_end,
							'imported_at' => $imported_now,
						)
					),
				),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		// Imported NOW, but the data itself is 60 days old → still warns.
		$this->assertSame( 'warning', $freshness['status'] );
		$this->assertGreaterThan( 45, $freshness['totals']['data_age_days'] );
	}

	/**
	 * Contiguous monthly periods → missing_periods passes.
	 */
	public function test_contiguous_periods_pass(): void {
		$periods = array();
		foreach ( array( '2026-03', '2026-04', '2026-05' ) as $label ) {
			$periods[] = $this->period(
				array(
					'period_label' => $label,
					'period_start' => $label . '-01',
					'period_end'   => gmdate( 'Y-m-t', strtotime( $label . '-01' ) ),
				)
			);
		}

		$rows  = array( $this->row() );
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => $periods,
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$missing = $this->check( $built, 'missing_periods' );
		$this->assertSame( 'pass', $missing['status'] );
		$this->assertSame( 0, $missing['totals']['missing_periods'] );
	}

	/**
	 * A gap in the monthly sequence → missing_periods warns with the gap listed.
	 */
	public function test_gap_in_periods_warns(): void {
		$periods = array();
		foreach ( array( '2026-03', '2026-05' ) as $label ) {
			$periods[] = $this->period(
				array(
					'period_label' => $label,
					'period_start' => $label . '-01',
				)
			);
		}

		$rows  = array( $this->row() );
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => $periods,
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$missing = $this->check( $built, 'missing_periods' );
		$this->assertSame( 'warning', $missing['status'] );
		$this->assertContains( '2026-04', $missing['totals']['missing'] );
	}

	/**
	 * Same period_label across two batches → duplicate_period_batches warns.
	 */
	public function test_duplicate_period_batches_warns(): void {
		$periods = array(
			$this->period(
				array(
					'period_label' => '2026-05',
					'import_batch' => 'batch-a',
				)
			),
			$this->period(
				array(
					'period_label' => '2026-05',
					'import_batch' => 'batch-b',
				)
			),
		);

		$rows  = array( $this->row() );
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => $periods,
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$dup = $this->check( $built, 'duplicate_period_batches' );
		$this->assertSame( 'warning', $dup['status'] );
		$this->assertSame( 1, $dup['totals']['duplicate_labels'] );
		$this->assertArrayHasKey( '2026-05', $dup['totals']['duplicates'] );
	}

	/**
	 * B1: reconciliation passes when PHP row-sum matches the INDEPENDENT SQL aggregate.
	 */
	public function test_reconciliation_passes_when_row_sum_matches_independent(): void {
		$rows = array(
			$this->row(
				array(
					'views'   => 1000,
					'revenue' => 100.0,
				)
			),
			$this->row(
				array(
					'slug'    => '/other/',
					'views'   => 500,
					'revenue' => 25.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => array(
					'rows'    => 2,
					'views'   => 1500,
					'revenue' => 125.0,
				),
			)
		);

		$recon = $this->check( $built, 'totals_reconciliation' );
		$this->assertSame( 'pass', $recon['status'] );
		$this->assertTrue( $recon['totals']['reconciled'] );
	}

	/**
	 * B1: reconciliation FAILS when the row-sum diverges from the independent
	 * aggregate (a read-path regression signal).
	 */
	public function test_reconciliation_fails_on_independent_mismatch(): void {
		$rows = array(
			$this->row(
				array(
					'views'   => 1000,
					'revenue' => 100.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => array(
					'rows'    => 2,
					'views'   => 2000,
					'revenue' => 999.0,
				),
			)
		);

		$recon = $this->check( $built, 'totals_reconciliation' );
		$this->assertSame( 'fail', $recon['status'] );
		$this->assertFalse( $recon['totals']['reconciled'] );
	}

	/**
	 * B1: the independent side is NOT a recomputed copy of the row-sum. Passing
	 * no independent_totals (the old tautology shape) yields a mismatch → fail,
	 * never a silent pass.
	 */
	public function test_reconciliation_does_not_silently_pass_without_independent(): void {
		$rows = array(
			$this->row(
				array(
					'views'   => 1000,
					'revenue' => 100.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods' => array( $this->period() ),
				'rows'    => $rows,
			)
		);

		$recon = $this->check( $built, 'totals_reconciliation' );
		$this->assertSame( 'fail', $recon['status'] );
	}

	/**
	 * B1: content/unresolved reconciliation reports a stale stored post_id as a
	 * warning (post was trashed since import, correctly routed to unresolved).
	 */
	public function test_content_unresolved_reconciliation_flags_stale_post_id(): void {
		$rows = array(
			// A healthy resolved post.
			$this->row(
				array(
					'post_id'        => 10,
					'stored_post_id' => 10,
				)
			),
			// An unresolved row that stored a post_id at import (now stale/trashed).
			$this->row(
				array(
					'slug'           => '/trashed/',
					'post_id'        => 0,
					'stored_post_id' => 77,
					'is_content'     => false,
					'route_family'   => 'other',
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$cur = $this->check( $built, 'content_unresolved_reconciliation' );
		$this->assertSame( 'warning', $cur['status'] );
		$this->assertSame( 1, $cur['totals']['stale_post_id_rows'] );
		$this->assertSame( 1, $cur['totals']['resolved_pages'] );
		$this->assertSame( 1, $cur['totals']['unresolved_pages'] );
		$this->assertTrue( $cur['totals']['partition_complete'] );
	}

	/**
	 * M4: resolution_coverage counts DEDUPED pages, not raw rows. A post with
	 * three URL variants counts once.
	 */
	public function test_resolution_coverage_dedupes_pages(): void {
		// One resolved post (post 1) via three variant rows + two distinct
		// unresolved routes.
		$rows = array(
			$this->row(
				array(
					'slug'           => '/a/',
					'post_id'        => 1,
					'stored_post_id' => 1,
				)
			),
			$this->row(
				array(
					'slug'           => '/a/?x',
					'post_id'        => 1,
					'stored_post_id' => 1,
				)
			),
			$this->row(
				array(
					'slug'           => '/a/?y',
					'post_id'        => 1,
					'stored_post_id' => 1,
				)
			),
			$this->row(
				array(
					'slug'           => '/home/',
					'post_id'        => 0,
					'stored_post_id' => 0,
					'is_content'     => false,
					'route_family'   => 'home',
				)
			),
			$this->row(
				array(
					'slug'           => '/page/2/',
					'post_id'        => 0,
					'stored_post_id' => 0,
					'is_content'     => false,
					'route_family'   => 'pagination',
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$cov = $this->check( $built, 'resolution_coverage' );
		// 1 distinct resolved page (not 3), 2 distinct unresolved.
		$this->assertSame( 1, $cov['totals']['resolved_pages'] );
		$this->assertSame( 2, $cov['totals']['unresolved_pages'] );
		$this->assertSame( 3, $cov['totals']['total_pages'] );
	}

	/**
	 * M4: resolution_coverage warns when >50% of distinct pages are unresolved.
	 */
	public function test_resolution_coverage_warns_on_high_ratio(): void {
		$rows = array();
		// 2 distinct resolved, 3 distinct unresolved of 5 = 60% → warning.
		$rows[] = $this->row(
			array(
				'slug'           => '/r1/',
				'post_id'        => 1,
				'stored_post_id' => 1,
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/r2/',
				'post_id'        => 2,
				'stored_post_id' => 2,
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/home/',
				'post_id'        => 0,
				'stored_post_id' => 0,
				'is_content'     => false,
				'route_family'   => 'home',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/page/2/',
				'post_id'        => 0,
				'stored_post_id' => 0,
				'is_content'     => false,
				'route_family'   => 'pagination',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/old.html',
				'post_id'        => 0,
				'stored_post_id' => 0,
				'is_content'     => false,
				'route_family'   => 'legacy-html',
			)
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$cov = $this->check( $built, 'resolution_coverage' );
		$this->assertSame( 'warning', $cov['status'] );
		$this->assertSame( 3, $cov['totals']['unresolved_pages'] );
	}

	/**
	 * M4: format_coverage dedupes by post id and warns on high uncategorized.
	 */
	public function test_format_coverage_dedupes_and_warns_on_uncategorized(): void {
		// 2 distinct classified posts + 3 distinct uncategorized of 5 resolved.
		$rows   = array();
		$rows[] = $this->row(
			array(
				'slug'           => '/a/',
				'post_id'        => 1,
				'stored_post_id' => 1,
				'format'         => 'song-meaning',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/b/',
				'post_id'        => 2,
				'stored_post_id' => 2,
				'format'         => 'news',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/c/',
				'post_id'        => 3,
				'stored_post_id' => 3,
				'format'         => 'uncategorized',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/d/',
				'post_id'        => 4,
				'stored_post_id' => 4,
				'format'         => 'uncategorized',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'           => '/e/',
				'post_id'        => 5,
				'stored_post_id' => 5,
				'format'         => 'uncategorized',
			)
		);
		// Variant of post 3 — must NOT double-count.
		$rows[] = $this->row(
			array(
				'slug'           => '/c/?x',
				'post_id'        => 3,
				'stored_post_id' => 3,
				'format'         => 'uncategorized',
			)
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$fmt = $this->check( $built, 'format_coverage' );
		$this->assertSame( 'warning', $fmt['status'] );
		// 5 distinct resolved pages (the post-3 variant collapsed), 3 uncategorized.
		$this->assertSame( 5, $fmt['totals']['resolved_pages'] );
		$this->assertSame( 3, $fmt['totals']['uncategorized_pages'] );
	}

	/**
	 * Zero-view revenue → warning, revenue preserved in totals.
	 */
	public function test_zero_view_revenue_warns(): void {
		$rows = array(
			$this->row(
				array(
					'slug'    => '/z/',
					'views'   => 0,
					'revenue' => 12.50,
				)
			),
			$this->row(
				array(
					'slug'           => '/ok/',
					'post_id'        => 2,
					'stored_post_id' => 2,
					'views'          => 1000,
					'revenue'        => 100.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$zv = $this->check( $built, 'zero_view_revenue' );
		$this->assertSame( 'warning', $zv['status'] );
		$this->assertSame( 1, $zv['totals']['rows'] );
		$this->assertEquals( 12.5, $zv['totals']['revenue'] );
	}

	/**
	 * Views-positive zero-revenue rows → warning above 20%.
	 */
	public function test_views_zero_revenue_warns_above_threshold(): void {
		$rows = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'           => '/r' . $i . '/',
					'post_id'        => $i + 1,
					'stored_post_id' => $i + 1,
					'views'          => 1000,
					'revenue'        => 10.0,
				)
			);
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'           => '/z' . $i . '/',
					'post_id'        => 100 + $i,
					'stored_post_id' => 100 + $i,
					'views'          => 500,
					'revenue'        => 0.0,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$vz = $this->check( $built, 'views_zero_revenue' );
		$this->assertSame( 'warning', $vz['status'] );
		$this->assertSame( 3, $vz['totals']['rows'] );
	}

	/**
	 * Negative revenue → negative_impossible_values FAILS.
	 */
	public function test_negative_values_fail(): void {
		$rows = array(
			$this->row(
				array(
					'slug'           => '/bad/',
					'post_id'        => 2,
					'stored_post_id' => 2,
					'revenue'        => -5.0,
				)
			),
			$this->row( array( 'slug' => '/ok/' ) ),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$neg = $this->check( $built, 'negative_impossible_values' );
		$this->assertSame( 'fail', $neg['status'] );
		$this->assertSame( 1, $neg['totals']['rows'] );
		$this->assertSame( 'fail', $built['overall_status'] );
	}

	/**
	 * M7: out-of-range rate values (viewability/fill_rate > 100) are impossible → fail.
	 */
	public function test_out_of_range_rates_are_impossible(): void {
		$rows = array(
			$this->row(
				array(
					'slug'           => '/bad-view/',
					'post_id'        => 2,
					'stored_post_id' => 2,
					'viewability'    => 150.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$neg = $this->check( $built, 'negative_impossible_values' );
		$this->assertSame( 'fail', $neg['status'] );
	}

	/**
	 * M7: impossibly high RPM (> 1000) is flagged.
	 */
	public function test_impossibly_high_rpm_is_flagged(): void {
		$rows = array(
			$this->row(
				array(
					'slug'           => '/bad-rpm/',
					'post_id'        => 2,
					'stored_post_id' => 2,
					'source_rpm'     => 5000.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$neg = $this->check( $built, 'negative_impossible_values' );
		$this->assertSame( 'fail', $neg['status'] );
	}

	/**
	 * Negative impressions/pageview and impossible derived RPM are flagged.
	 */
	public function test_additional_impossible_metrics_are_flagged(): void {
		$rows = array(
			$this->row(
				array(
					'impressions_per_pageview' => -1.0,
					'derived_rpm'              => 1500.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$check = $this->check( $built, 'negative_impossible_values' );
		$this->assertSame( 'fail', $check['status'] );
		$this->assertStringContainsString( 'derived rpm', implode( ' ', $check['totals']['samples'][0]['issues'] ) );
		$this->assertStringContainsString( 'impressions_per_pageview', implode( ' ', $check['totals']['samples'][0]['issues'] ) );
	}

	/**
	 * Source anomalies (zero-view revenue, variance) do NOT fail on their own.
	 */
	public function test_source_anomalies_are_warnings_not_failures(): void {
		$rows = array(
			$this->row(
				array(
					'slug'           => '/z/',
					'post_id'        => 2,
					'stored_post_id' => 2,
					'views'          => 0,
					'revenue'        => 5.0,
					'source_rpm'     => 0.0,
				)
			),
			$this->row(
				array(
					'slug'           => '/ok/',
					'post_id'        => 3,
					'stored_post_id' => 3,
					'views'          => 1000,
					'revenue'        => 100.0,
					'source_rpm'     => 50.0,
					'derived_rpm'    => 100.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$this->assertSame( 'warning', $this->check( $built, 'zero_view_revenue' )['status'] );
		$this->assertSame( 'warning', $this->check( $built, 'stored_vs_derived_rpm_variance' )['status'] );
		$this->assertSame( 'pass', $this->check( $built, 'negative_impossible_values' )['status'] );
		// No fail-level check → overall is warning, not fail.
		$this->assertSame( 'warning', $built['overall_status'] );
	}

	/**
	 * Stored-vs-derived RPM variance flags rows that disagree by >20%.
	 */
	public function test_rpm_variance_flags_high_disagreement(): void {
		$rows = array();
		// 6 views-positive rows; 2 disagree by >20% → 33% → warning (>10%).
		for ( $i = 0; $i < 4; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'           => '/ok' . $i . '/',
					'post_id'        => $i + 1,
					'stored_post_id' => $i + 1,
					'views'          => 1000,
					'revenue'        => 100.0,
					'source_rpm'     => 100.0,
					'derived_rpm'    => 100.0,
				)
			);
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'           => '/v' . $i . '/',
					'post_id'        => 50 + $i,
					'stored_post_id' => 50 + $i,
					'views'          => 1000,
					'revenue'        => 100.0,
					'source_rpm'     => 50.0,
					'derived_rpm'    => 100.0,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$var = $this->check( $built, 'stored_vs_derived_rpm_variance' );
		$this->assertSame( 'warning', $var['status'] );
		$this->assertSame( 6, $var['totals']['checked'] );
		$this->assertSame( 2, $var['totals']['high_variance'] );
	}

	/**
	 * Missing import_batch → provenance_coverage warns.
	 */
	public function test_provenance_coverage_warns_on_missing_batch(): void {
		$rows = array(
			$this->row( array( 'import_batch' => '' ) ),
			$this->row(
				array(
					'slug'           => '/ok/',
					'post_id'        => 2,
					'stored_post_id' => 2,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$prov = $this->check( $built, 'provenance_coverage' );
		$this->assertSame( 'warning', $prov['status'] );
		$this->assertSame( 1, $prov['totals']['missing_batch'] );
	}

	/**
	 * M5: an empty scoped query never passes — reconciliation warns when there
	 * are zero rows (and zero independent rows) in scope.
	 */
	public function test_empty_scoped_query_does_not_pass(): void {
		// Whole-store has periods, but the scoped query matched zero rows.
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => array(),
				'independent_totals' => array(
					'rows'    => 0,
					'views'   => 0,
					'revenue' => 0.0,
				),
			)
		);

		$recon = $this->check( $built, 'totals_reconciliation' );
		$this->assertSame( 'warning', $recon['status'] );
		// Overall is warning (not pass) because the empty scope cannot reconcile.
		$this->assertSame( 'warning', $built['overall_status'] );
	}

	/**
	 * A clean store → all pass, overall pass (12 checks now).
	 */
	public function test_clean_store_overall_pass(): void {
		$periods = array();
		foreach ( array( '2026-04', '2026-05', '2026-06' ) as $label ) {
			$periods[] = $this->period(
				array(
					'period_label' => $label,
					'period_start' => $label . '-01',
					'period_end'   => gmdate( 'Y-m-t', strtotime( $label . '-01' ) ),
				)
			);
		}

		$rows = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'           => '/r' . $i . '/',
					'post_id'        => $i + 1,
					'stored_post_id' => $i + 1,
					'views'          => 1000,
					'revenue'        => 100.0,
					'source_rpm'     => 100.0,
					'derived_rpm'    => 100.0,
					'format'         => 'song-meaning',
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => $periods,
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$this->assertSame( 'pass', $built['overall_status'] );
		$this->assertSame( 12, $built['summary']['total'] );
		$this->assertSame( 12, $built['summary']['pass'] );
		$this->assertSame( 0, $built['summary']['warning'] );
		$this->assertSame( 0, $built['summary']['fail'] );
	}

	/**
	 * Overall status precedence: fail beats warning beats pass.
	 */
	public function test_overall_status_fail_beats_warning(): void {
		$rows = array(
			$this->row(
				array(
					'slug'           => '/bad/',
					'post_id'        => 2,
					'stored_post_id' => 2,
					'revenue'        => -1.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'            => array( $this->period() ),
				'rows'               => $rows,
				'independent_totals' => $this->reconciling_totals( $rows ),
			)
		);

		$this->assertSame( 'fail', $built['overall_status'] );
		$this->assertGreaterThan( 0, $built['summary']['fail'] );
	}

	/**
	 * B2/B1/M2: source-string contract — the callback enforces blog
	 * authorization, switch_to_blog around resolution, queries the independent
	 * scope aggregate, and freshness uses the period boundary.
	 */
	public function test_callback_enforces_auth_switch_independent_and_boundary(): void {
		$source = $this->ability_source();

		// Authorization helper.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_authorize_blog_read', $source );
		$this->assertStringContainsString( 'manage_network_options', $source );
		// switch_to_blog around resolution.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_run_in_blog', $source );
		// Independent SQL aggregate for reconciliation.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_get_scope_totals', $source );
		$this->assertStringContainsString( "'independent_totals'", $source );
		// Freshness uses period_end boundary (not imported_at).
		$this->assertStringContainsString( "'latest_period_end'", $source );
		// No new table creation inside this ability.
		$this->assertStringNotContainsString( 'CREATE TABLE', $source );
	}

	/**
	 * Read the production ability source.
	 *
	 * @return string
	 */
	private function ability_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue-diagnostics.php' );
		$this->assertNotFalse( $source );

		return $source;
	}
}
