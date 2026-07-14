<?php
/**
 * Behavioral + contract tests for the content-revenue page-level lens (#141).
 *
 * The pure core (aggregation, metric derivation, thresholding, stable sorting,
 * benchmark) is unit-tested directly; the WordPress-dependent resolution gate
 * is locked down via a source-string contract on the ability callback.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue-pages.php';
require_once dirname( __DIR__ ) . '/inc/core/content-format-classifier.php';

/**
 * Verify page-level analysis contracts.
 */
final class GetContentRevenuePagesTest extends TestCase {

	/**
	 * Helper: a minimal resolved-content record.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function content( array $over = array() ) {
		return array_merge(
			array(
				'page_key'                 => 'p1',
				'is_content'               => true,
				'post_id'                  => 1,
				'format'                   => 'song-meaning',
				'categories'               => array( 'song-meanings' ),
				'route_family'             => '',
				'views'                    => 1000,
				'revenue'                  => 100.0,
				'source_rpm'               => 100.0,
				'cpm'                      => 5.0,
				'viewability'              => 80.0,
				'fill_rate'                => 90.0,
				'impressions_per_pageview' => 2.0,
				'url'                      => '/song/',
				'title'                    => 'A Song',
				'path'                     => '/song/',
				'published_date'           => '2024-01-01 00:00:00',
			),
			$over
		);
	}

	/**
	 * Helper: a minimal unresolved-route record.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function unresolved( array $over = array() ) {
		return array_merge(
			array(
				'page_key'                 => 'u' . md5( '/' ),
				'is_content'               => false,
				'post_id'                  => 0,
				'format'                   => '',
				'categories'               => array(),
				'route_family'             => 'home',
				'views'                    => 5000,
				'revenue'                  => 5.0,
				'source_rpm'               => 1.0,
				'cpm'                      => 0.0,
				'viewability'              => 0.0,
				'fill_rate'                => 0.0,
				'impressions_per_pageview' => 0.0,
				'url'                      => '/',
				'title'                    => '',
				'path'                     => '',
				'published_date'           => '',
			),
			$over
		);
	}

	/**
	 * Equal numeric post IDs from distinct owning sites remain distinct pages.
	 */
	public function test_owning_site_identity_prevents_page_collisions(): void {
		$records = array(
			$this->content(
				array(
					'page_key'        => 'p1:71',
					'content_blog_id' => 1,
					'post_id'         => 71,
					'title'           => 'Main',
					'format'          => 'song-meaning',
				)
			),
			$this->content(
				array(
					'page_key'        => 'p7:71',
					'content_blog_id' => 7,
					'post_id'         => 71,
					'title'           => 'Event',
					'format'          => 'events',
				)
			),
			$this->content(
				array(
					'page_key'        => 'p11:71',
					'content_blog_id' => 11,
					'post_id'         => 71,
					'title'           => 'Wire',
					'format'          => 'news',
				)
			),
		);
		$built   = extrachill_analytics_revenue_build_pages(
			$records,
			array(
				'cohort' => 'resolved',
				'limit'  => 0,
			)
		);

		$this->assertSame( 3, $built['totals']['pages_before_limit'] );
		$this->assertSame( array( 11, 1, 7 ), array_column( $built['pages'], 'content_blog_id' ) );
		$this->assertSame( array( 'p11:71', 'p1:71', 'p7:71' ), array_column( $built['pages'], 'page_key' ) );
	}

	/**
	 * Derived RPM = revenue / (views/1000), distinct from source_rpm.
	 */
	public function test_derived_rpm_distinct_from_source_rpm(): void {
		$built = extrachill_analytics_revenue_build_pages(
			array(
				// 1000 views, $100 revenue → derived RPM = 100. Source says 95.
				$this->content( array( 'source_rpm' => 95.0 ) ),
			),
			array(
				'cohort'  => 'resolved',
				'sort_by' => 'derived_rpm',
			)
		);

		$page = $built['pages'][0];
		$this->assertEquals( 100.0, $page['derived_rpm'] );
		$this->assertEquals( 95.0, $page['source_rpm'] );
		$this->assertNotEquals( $page['derived_rpm'], $page['source_rpm'] );
	}

	/**
	 * Zero-view revenue: no division error, revenue preserved, flagged.
	 */
	public function test_zero_view_revenue_handled_without_division_error(): void {
		$built = extrachill_analytics_revenue_build_pages(
			array(
				$this->content(
					array(
						'views'      => 0,
						'revenue'    => 12.50,
						'source_rpm' => 0.0,
					)
				),
				$this->content(
					array(
						'page_key' => 'p2',
						'views'    => 500,
						'revenue'  => 25.0,
					)
				),
			),
			array( 'cohort' => 'resolved' )
		);

		$by_key = array();
		foreach ( $built['pages'] as $p ) {
			$by_key[ $p['page_key'] ] = $p;
		}

		// The zero-view page is present, flagged, revenue preserved.
		$this->assertArrayHasKey( 'p1', $by_key );
		$this->assertTrue( $by_key['p1']['zero_views'] );
		$this->assertEquals( 12.5, $by_key['p1']['revenue'] );
		// derived_rpm is 0.0 — never INF, never NAN, never a thrown error.
		$this->assertSame( 0.0, $by_key['p1']['derived_rpm'] );

		// The views-positive page is NOT flagged.
		$this->assertFalse( $by_key['p2']['zero_views'] );
		$this->assertEquals( 50.0, $by_key['p2']['derived_rpm'] );

		// Totals disclose the zero-view cohort.
		$this->assertSame( 1, $built['totals']['zero_views_pages'] );
	}

	/**
	 * Cohort filter: resolved excludes unresolved routes and vice-versa.
	 */
	public function test_cohort_filtering(): void {
		$records = array(
			$this->content( array( 'page_key' => 'p1' ) ),
			$this->unresolved( array( 'page_key' => 'u' . md5( '/' ) ) ),
		);

		$resolved = extrachill_analytics_revenue_build_pages( $records, array( 'cohort' => 'resolved' ) );
		$this->assertCount( 1, $resolved['pages'] );
		$this->assertSame( 'resolved', $resolved['pages'][0]['cohort'] );

		$unresolved = extrachill_analytics_revenue_build_pages( $records, array( 'cohort' => 'unresolved' ) );
		$this->assertCount( 1, $unresolved['pages'] );
		$this->assertSame( 'unresolved', $unresolved['pages'][0]['cohort'] );

		$all = extrachill_analytics_revenue_build_pages( $records, array( 'cohort' => 'all' ) );
		$this->assertCount( 2, $all['pages'] );
	}

	/**
	 * Minimum-views threshold excludes sub-floor pages, applied after aggregation.
	 */
	public function test_min_views_threshold(): void {
		$records = array(
			$this->content(
				array(
					'page_key' => 'p1',
					'views'    => 100,
				)
			),
			$this->content(
				array(
					'page_key' => 'p2',
					'views'    => 500,
				)
			),
			$this->content(
				array(
					'page_key' => 'p3',
					'views'    => 5000,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_pages(
			$records,
			array(
				'cohort'    => 'resolved',
				'min_views' => 500,
			)
		);

		$keys = array_column( $built['pages'], 'page_key' );
		$this->assertSame( array( 'p2', 'p3' ), $keys );
		$this->assertSame( 2, $built['sample']['after_min_views'] );
	}

	/**
	 * M3: URL-variant rows of one post collapse by page_key; views/revenue sum,
	 * rate metrics are simple-averaged (no invented denominators). derived_rpm is
	 * recomputed from the summed revenue/views and remains the correct aggregate.
	 */
	public function test_variant_rows_collapse_and_source_average_rates(): void {
		$records = array(
			// Two variants of p1: 1000 views @ source_rpm 100, 3000 views @ source_rpm 40.
			$this->content(
				array(
					'page_key'   => 'p1',
					'views'      => 1000,
					'revenue'    => 100.0,
					'source_rpm' => 100.0,
				)
			),
			$this->content(
				array(
					'page_key'   => 'p1',
					'views'      => 3000,
					'revenue'    => 120.0,
					'source_rpm' => 40.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_pages( $records, array( 'cohort' => 'resolved' ) );

		$this->assertCount( 1, $built['pages'] );
		$page = $built['pages'][0];
		// Volume summed.
		$this->assertSame( 4000, $page['views'] );
		$this->assertEquals( 220.0, $page['revenue'] );
		// Derived RPM recomputed from the SUM: 220 / 4 = 55.
		$this->assertEquals( 55.0, $page['derived_rpm'] );
		// Source RPM is simple-averaged across the two variants: (100 + 40) / 2 = 70.
		// (NOT views-weighted, because the true denominator is not stored.).
		$this->assertEquals( 70.0, $page['source_rpm'] );
		// One page counted once.
		$this->assertSame( 1, $built['sample']['resolved_pages'] );
	}

	/**
	 * M1: totals (views/revenue/zero_views) cover the FULL cohort BEFORE the
	 * limit, not just the truncated top-N returned.
	 */
	public function test_totals_reflect_full_cohort_before_limit(): void {
		$records = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$records[] = $this->content(
				array(
					'page_key' => 'p' . $i,
					'views'    => 1000,
					'revenue'  => 10.0 * $i,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_pages(
			$records,
			array(
				'cohort'  => 'resolved',
				'sort_by' => 'revenue',
				'order'   => 'desc',
				'limit'   => 2,
			)
		);

		// Only 2 pages returned.
		$this->assertCount( 2, $built['pages'] );
		$this->assertSame( 2, $built['totals']['pages_returned'] );
		// But totals cover all 5 pages (full cohort before the limit).
		$this->assertSame( 5, $built['totals']['pages_before_limit'] );
		$this->assertSame( 5, $built['totals']['cohort_pages'] );
		$this->assertSame( 5000, $built['totals']['views'] );
		// Revenue 10+20+30+40+50 = 150.
		$this->assertEquals( 150.0, $built['totals']['revenue'] );
	}

	/**
	 * The implicit scope selects one freshest period/batch pair.
	 */
	public function test_default_scope_selects_one_freshest_batch(): void {
		$batches = array(
			(object) array(
				'period_label' => '2026-06',
				'period_end'   => '2026-06-30',
				'import_batch' => 'newest-june',
			),
			(object) array(
				'period_label' => '2026-06',
				'period_end'   => '2026-06-30',
				'import_batch' => 'older-june',
			),
			(object) array(
				'period_label' => 'all-time',
				'period_end'   => null,
				'import_batch' => 'lifetime',
			),
		);

		$scope = extrachill_analytics_revenue_select_default_scope( $batches );

		$this->assertSame( '2026-06', $scope['effective_period'] );
		$this->assertSame( 'newest-june', $scope['effective_batch'] );
		$this->assertTrue( $scope['defaulted'] );
	}

	/**
	 * A relative path inherits the authorized target blog's hostname, and the
	 * run-in-blog wrapper restores the original site afterwards.
	 */
	public function test_relative_path_uses_target_blog_hostname_and_restores_context(): void {
		$GLOBALS['extrachill_analytics_test_blog_id']       = 1;
		$GLOBALS['extrachill_analytics_test_blog_stack']    = array();
		$GLOBALS['extrachill_analytics_test_home_urls']     = array(
			1 => 'https://extrachill.com',
			7 => 'https://events.extrachill.com',
		);
		$GLOBALS['extrachill_analytics_test_resolved_urls'] = array();
		$GLOBALS['extrachill_analytics_test_url_post_ids']  = array(
			'https://events.extrachill.com/target-show/' => 77,
		);

		$post_id = extrachill_analytics_revenue_run_in_blog(
			7,
			static function () {
				return extrachill_analytics_revenue_resolve_post_id(
					'/target-show/',
					extrachill_analytics_revenue_resolution_hostname()
				);
			}
		);

		$this->assertSame( 77, $post_id );
		$this->assertSame( array( 'https://events.extrachill.com/target-show/' ), $GLOBALS['extrachill_analytics_test_resolved_urls'] );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_blog_stack'] );
		$this->assertSame( 'override.example.com', extrachill_analytics_revenue_resolution_hostname( 'override.example.com' ) );
	}

	/**
	 * Cohort totals exclude pages from the other cohort before min_views.
	 */
	public function test_cohort_pages_counts_only_selected_cohort(): void {
		$built = extrachill_analytics_revenue_build_pages(
			array(
				$this->content( array( 'page_key' => 'p1' ) ),
				$this->unresolved( array( 'page_key' => 'u1' ) ),
			),
			array( 'cohort' => 'resolved' )
		);

		$this->assertSame( 1, $built['totals']['cohort_pages'] );
	}

	/**
	 * M6/B2/B4: source-string contract — the callback populates path, enforces
	 * blog authorization, persisted owning-site metadata, applies the default
	 * window contract, and exposes selected periods/batches.
	 */
	public function test_callback_populates_path_and_enforces_auth_and_scope(): void {
		$source = $this->ability_source();

		// Owning-site metadata includes the canonical permalink path.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_content_metadata', $source );
		// Network/blog authorization.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_authorize_blog_read', $source );
		$this->assertStringContainsString( 'manage_network_options', $source );
		$this->assertStringNotContainsString( 'extrachill_analytics_revenue_resolve_post_id', $source );
		// Default window contract (freshest dated period).
		$this->assertStringContainsString( 'extrachill_analytics_revenue_resolve_default_period', $source );
		// Selected periods/batches exposed.
		$this->assertStringContainsString( "'selected_periods'", $source );
		$this->assertStringContainsString( "'selected_batches'", $source );
		// Reuses shared substrate (no new SQL/table).
		$this->assertStringContainsString( 'extrachill_analytics_revenue_get_rows', $source );
		$this->assertStringNotContainsString( 'CREATE TABLE', $source );
	}

	/**
	 * Stable sorting: by derived_rpm desc, with a deterministic page_key tiebreaker.
	 */
	public function test_sort_by_derived_rpm_desc_stable(): void {
		$records = array(
			$this->content(
				array(
					'page_key' => 'p3',
					'views'    => 1000,
					'revenue'  => 30.0,
				)
			), // RPM 30.
			$this->content(
				array(
					'page_key' => 'p1',
					'views'    => 1000,
					'revenue'  => 10.0,
				)
			), // RPM 10.
			$this->content(
				array(
					'page_key' => 'p2',
					'views'    => 1000,
					'revenue'  => 30.0,
				)
			), // RPM 30 (tie with p3).
		);

		$built = extrachill_analytics_revenue_build_pages(
			$records,
			array(
				'cohort'  => 'resolved',
				'sort_by' => 'derived_rpm',
				'order'   => 'desc',
			)
		);

		$keys = array_column( $built['pages'], 'page_key' );
		// p2 and p3 tie on RPM 30; tiebreaker is page_key asc → p2 before p3.
		// Then p1 (RPM 10).
		$this->assertSame( array( 'p2', 'p3', 'p1' ), $keys );
	}

	/**
	 * Ascending order reverses the primary direction; tiebreaker stays ascending.
	 */
	public function test_sort_ascending_keeps_tiebreaker_ascending(): void {
		$records = array(
			$this->content(
				array(
					'page_key' => 'p3',
					'views'    => 1000,
					'revenue'  => 30.0,
				)
			),
			$this->content(
				array(
					'page_key' => 'p1',
					'views'    => 1000,
					'revenue'  => 10.0,
				)
			),
			$this->content(
				array(
					'page_key' => 'p2',
					'views'    => 1000,
					'revenue'  => 30.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_pages(
			$records,
			array(
				'cohort'  => 'resolved',
				'sort_by' => 'derived_rpm',
				'order'   => 'asc',
			)
		);

		$keys = array_column( $built['pages'], 'page_key' );
		// Ascending: p1 (10) first, then p2/p3 (30, tie → page_key asc).
		$this->assertSame( array( 'p1', 'p2', 'p3' ), $keys );
	}

	/**
	 * Sort by each supported key surfaces the right page on top.
	 */
	public function test_sort_by_each_key(): void {
		$records = array(
			$this->content(
				array(
					'page_key'                 => 'p1',
					'views'                    => 1000,
					'revenue'                  => 10.0,
					'source_rpm'               => 10.0,
					'cpm'                      => 1.0,
					'viewability'              => 50.0,
					'fill_rate'                => 50.0,
					'impressions_per_pageview' => 1.0,
				)
			),
			// p2: higher on every metric including derived RPM (40/(2000/1000)=20 vs p1's 10).
			$this->content(
				array(
					'page_key'                 => 'p2',
					'views'                    => 2000,
					'revenue'                  => 40.0,
					'source_rpm'               => 20.0,
					'cpm'                      => 2.0,
					'viewability'              => 60.0,
					'fill_rate'                => 60.0,
					'impressions_per_pageview' => 2.0,
				)
			),
		);

		$expect_top = array(
			'views'                    => 'p2',
			'revenue'                  => 'p2',
			'derived_rpm'              => 'p2',
			'source_rpm'               => 'p2',
			'cpm'                      => 'p2',
			'viewability'              => 'p2',
			'fill_rate'                => 'p2',
			'impressions_per_pageview' => 'p2',
			'dollars_per_page'         => 'p2',
		);

		foreach ( $expect_top as $sort_by => $expected_key ) {
			$built = extrachill_analytics_revenue_build_pages(
				$records,
				array(
					'cohort'  => 'resolved',
					'sort_by' => $sort_by,
					'order'   => 'desc',
				)
			);
			$this->assertSame( $expected_key, $built['pages'][0]['page_key'], "sort_by={$sort_by} did not surface {$expected_key}" );
		}
	}

	/**
	 * Limit truncates and flags; pages_before_limit preserves the full count.
	 */
	public function test_limit_truncates_and_flags(): void {
		$records = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$records[] = $this->content(
				array(
					'page_key' => 'p' . $i,
					'views'    => 1000 * $i,
					'revenue'  => (float) $i,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_pages(
			$records,
			array(
				'cohort'  => 'resolved',
				'sort_by' => 'views',
				'order'   => 'desc',
				'limit'   => 3,
			)
		);

		$this->assertCount( 3, $built['pages'] );
		$this->assertSame( 10, $built['totals']['pages_before_limit'] );
		$this->assertSame( 3, $built['totals']['pages_returned'] );
		$this->assertTrue( $built['sample']['truncated'] );
		// Descending by views: p10, p9, p8.
		$this->assertSame( 'p10', $built['pages'][0]['page_key'] );
	}

	/**
	 * Benchmark opportunity requires >= 5 views-positive pages; below that it is suppressed.
	 */
	public function test_benchmark_suppressed_below_sample_floor(): void {
		$records = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$records[] = $this->content(
				array(
					'page_key' => 'p' . $i,
					'views'    => 1000,
					'revenue'  => 50.0,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_pages( $records, array( 'cohort' => 'resolved' ) );

		$this->assertFalse( $built['sample']['benchmark_computed'] );
		$this->assertFalse( $built['sample']['sufficient_for_benchmark'] );
		foreach ( $built['pages'] as $p ) {
			$this->assertFalse( $p['benchmark_opportunity'] );
			$this->assertNull( $p['benchmark_score'] );
		}
	}

	/**
	 * Benchmark qualifies high-RPM-at-volume pages; tiny-volume pages never qualify.
	 */
	public function test_benchmark_qualifies_high_rpm_at_volume(): void {
		// 6 pages. RPMs: p1=10, p2=20, p3=30, p4=40, p5=50, p6=300.
		// Median RPM = (30+40)/2 = 35. 1.5x = 52.5 → only p6 (300) qualifies on RPM.
		// Views: p1..p6 = 1000..6000. Median views = (3000+4000)/2 = 3500.
		// p6 has 6000 views >= 3500 → qualifies.
		$records = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$records[] = $this->content(
				array(
					'page_key' => 'p' . $i,
					'views'    => 1000 * $i,
					'revenue'  => 10.0 * $i, // RPM = (10i)/(i) = 10... wait: derived_rpm = revenue/(views/1000).
				)
			);
		}
		// Recompute: revenue 10i, views 1000i → derived_rpm = 10i / i = 10 for ALL.
		// That won't test qualification. Let me set explicit RPMs via revenue.
		$records = array();
		$rpms    = array(
			1 => 10.0,
			2 => 20.0,
			3 => 30.0,
			4 => 40.0,
			5 => 50.0,
			6 => 300.0,
		);
		for ( $i = 1; $i <= 6; $i++ ) {
			$rpm       = $rpms[ $i ];
			$views     = 1000 * $i;
			$rev       = $rpm * ( $views / 1000 );
			$records[] = $this->content(
				array(
					'page_key' => 'p' . $i,
					'views'    => $views,
					'revenue'  => $rev,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_pages( $records, array( 'cohort' => 'resolved' ) );

		$this->assertTrue( $built['sample']['benchmark_computed'] );

		$by_key = array();
		foreach ( $built['pages'] as $p ) {
			$by_key[ $p['page_key'] ] = $p;
		}

		// Only p6 qualifies (RPM 300 >= 52.5, views 6000 >= 3500).
		$this->assertTrue( $by_key['p6']['benchmark_opportunity'] );
		$this->assertFalse( $by_key['p1']['benchmark_opportunity'] );
		$this->assertFalse( $by_key['p5']['benchmark_opportunity'] );

		// Scores are populated for views-positive pages.
		$this->assertNotNull( $by_key['p6']['benchmark_score'] );
		// score = 300 / 35 ≈ 8.57.
		$this->assertEqualsWithDelta( 8.5714, $by_key['p6']['benchmark_score'], 0.01 );
	}

	/**
	 * A high-RPM page on tiny volume never qualifies as a benchmark.
	 */
	public function test_high_rpm_tiny_volume_never_qualifies(): void {
		// 5 pages with healthy volume + one tiny-volume high-RPM outlier.
		$records = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$records[] = $this->content(
				array(
					'page_key' => 'p' . $i,
					'views'    => 5000,
					'revenue'  => 50.0, // RPM 10.
				)
			);
		}
		// Tiny volume, huge RPM.
		$records[] = $this->content(
			array(
				'page_key' => 'p6',
				'views'    => 5,
				'revenue'  => 5.0, // RPM 1000.
			)
		);

		$built = extrachill_analytics_revenue_build_pages( $records, array( 'cohort' => 'resolved' ) );

		$by_key = array();
		foreach ( $built['pages'] as $p ) {
			$by_key[ $p['page_key'] ] = $p;
		}

		// p6 has huge RPM (1000) and a high score, but its views (5) are well
		// below the median (5000), so it does NOT qualify.
		$this->assertFalse( $by_key['p6']['benchmark_opportunity'] );
	}

	/**
	 * Post metadata is carried through to resolved pages.
	 */
	public function test_resolved_post_metadata_carried(): void {
		$built = extrachill_analytics_revenue_build_pages(
			array(
				$this->content(
					array(
						'page_key'       => 'p42',
						'post_id'        => 42,
						'title'          => 'Grateful Dead Ripple Meaning',
						'url'            => 'https://extrachill.com/ripple-meaning/',
						'format'         => 'song-meaning',
						'categories'     => array( 'song-meanings' ),
						'published_date' => '2022-03-15 10:00:00',
					)
				),
			),
			array( 'cohort' => 'resolved' )
		);

		$page = $built['pages'][0];
		$this->assertSame( 42, $page['post_id'] );
		$this->assertSame( 'Grateful Dead Ripple Meaning', $page['title'] );
		$this->assertSame( 'https://extrachill.com/ripple-meaning/', $page['url'] );
		$this->assertSame( '/song/', $page['path'] );
		$this->assertSame( 'song-meaning', $page['format'] );
		$this->assertSame( array( 'song-meanings' ), $page['categories'] );
		$this->assertSame( '2022-03-15 10:00:00', $page['published_date'] );
		// Resolved pages carry no route_family.
		$this->assertSame( '', $page['route_family'] );
	}

	/**
	 * Unresolved pages carry route_family, not post metadata.
	 */
	public function test_unresolved_pages_carry_route_family(): void {
		$built = extrachill_analytics_revenue_build_pages(
			array(
				$this->unresolved(
					array(
						'page_key'     => 'u' . md5( '/old.html' ),
						'route_family' => 'legacy-html',
						'url'          => '/old.html',
					)
				),
			),
			array( 'cohort' => 'unresolved' )
		);

		$page = $built['pages'][0];
		$this->assertSame( 'legacy-html', $page['route_family'] );
		$this->assertSame( '', $page['format'] );
		$this->assertSame( 0, $page['post_id'] );
	}

	/**
	 * Source-string contract: the callback publish-gates content, reuses the
	 * persisted attribution and classifiers, and delegates to the pure builder.
	 */
	public function test_callback_publish_gates_and_delegates(): void {
		$source = $this->ability_source();

		// Publish-status gate is owned by the persisted content metadata helper.
		// Read models consume persisted attribution; resolver fanout is ingestion-only.
		$this->assertStringNotContainsString( 'extrachill_analytics_revenue_resolve_post_id', $source );
		// Reuses the shared route-family classifier.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_classify_route_family', $source );
		// Metadata/classification runs in the owning-site helper.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_content_metadata', $source );
		// Reuses the shared store reader — no new SQL/table.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_get_rows', $source );
		// Delegates the pure half to the builder.
		$this->assertStringContainsString( 'extrachill_analytics_revenue_build_pages', $source );
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
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue-pages.php' );
		$this->assertNotFalse( $source );

		return $source;
	}
}
