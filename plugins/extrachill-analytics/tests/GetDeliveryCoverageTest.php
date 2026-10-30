<?php
/**
 * Behavioral tests for the cross-source delivery coverage diagnostic (#158).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/revenue-ad-policy.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-delivery-coverage.php';

/**
 * Verify delivery-coverage contracts.
 */
final class GetDeliveryCoverageTest extends TestCase {

	/**
	 * Build applicable policy evidence.
	 *
	 * @return array
	 */
	private function applicable_policy() {
		return array(
			'status'                => 'applicable',
			'site_enabled'          => true,
			'serve_ads'             => true,
			'integration_available' => true,
			'reason'                => 'enabled',
			'drift'                 => 'none',
		);
	}

	/**
	 * Build a measured source summary.
	 *
	 * @param int $views Applicable views.
	 * @return array
	 */
	private function source( $views ) {
		return array(
			'available'        => true,
			'pageviews'        => $views,
			'sessions'         => 0,
			'applicable_views' => $views,
			'blocked_views'    => 0,
			'unknown_views'    => 0,
			'resolved_views'   => 0,
			'unresolved_views' => 0,
			'route_families'   => array(),
		);
	}

	/**
	 * Build one normalized site snapshot.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function site( array $over = array() ) {
		return array_merge(
			array(
				'blog_id'        => 7,
				'canonical_host' => 'events.extrachill.com',
				'policy'         => $this->applicable_policy(),
				'ga'             => $this->source( 1000 ),
				'mediavine'      => $this->source( 950 ),
			),
			$over
		);
	}

	/**
	 * Aligned counters produce the documented ratio and status.
	 */
	public function test_aligned_coverage(): void {
		$report = extrachill_analytics_delivery_build_report( '2026-06-01', '2026-06-30', array( $this->site() ) );

		$this->assertTrue( $report['success'] );
		$this->assertSame( 'aligned', $report['sites'][0]['status'] );
		$this->assertSame( 0.95, $report['sites'][0]['coverage_ratio'] );
		$this->assertFalse( $report['sites'][0]['extreme_divergence'] );
		$this->assertSame( 1, $report['summary']['aligned'] );
	}

	/**
	 * Events-like evidence is an extreme warning, not a low-RPM conclusion.
	 */
	public function test_events_like_extreme_divergence_warns(): void {
		$report = extrachill_analytics_delivery_build_report(
			'2026-04-01',
			'2026-07-13',
			array(
				$this->site(
					array(
						'ga'        => $this->source( 54362 ),
						'mediavine' => $this->source( 5244 ),
					)
				),
			)
		);

		$this->assertSame( 'warning', $report['sites'][0]['status'] );
		$this->assertSame( 0.0965, $report['sites'][0]['coverage_ratio'] );
		$this->assertTrue( $report['sites'][0]['extreme_divergence'] );
		$this->assertStringContainsString( 'different counters', $report['source_contracts']['comparison'] );
	}

	/**
	 * Policy-blocked sites are non-applicable even if source fixtures exist.
	 */
	public function test_blocked_site_has_no_ratio(): void {
		$policy = array(
			'status'                => 'blocked',
			'site_enabled'          => false,
			'serve_ads'             => false,
			'integration_available' => true,
			'reason'                => 'site_disabled',
			'drift'                 => 'none',
		);
		$report = extrachill_analytics_delivery_build_report( '2026-06-01', '2026-06-30', array( $this->site( array( 'policy' => $policy ) ) ) );

		$this->assertSame( 'blocked', $report['sites'][0]['status'] );
		$this->assertNull( $report['sites'][0]['coverage_ratio'] );
	}

	/**
	 * Unavailable policy and unavailable sources stay unknown rather than zero.
	 */
	public function test_unavailable_policy_or_source_is_unknown(): void {
		$unknown_policy = array(
			'status'                => 'unknown',
			'site_enabled'          => null,
			'serve_ads'             => null,
			'integration_available' => null,
			'reason'                => 'policy_unavailable',
			'drift'                 => 'unknown',
		);
		$report         = extrachill_analytics_delivery_build_report(
			'2026-06-01',
			'2026-06-30',
			array(
				$this->site( array( 'policy' => $unknown_policy ) ),
				$this->site( array( 'ga' => extrachill_analytics_delivery_unknown_source( 'GA unavailable.' ) ) ),
			)
		);

		$this->assertSame( 'unknown', $report['sites'][0]['status'] );
		$this->assertSame( 'unknown', $report['sites'][1]['status'] );
		$this->assertNull( $report['sites'][1]['coverage_ratio'] );
		$this->assertSame( 2, $report['summary']['unknown'] );
	}

	/**
	 * A zero denominator is never divided or silently converted to zero coverage.
	 */
	public function test_zero_denominator_behavior(): void {
		$report = extrachill_analytics_delivery_build_report(
			'2026-06-01',
			'2026-06-30',
			array(
				$this->site(
					array(
						'ga'        => $this->source( 0 ),
						'mediavine' => $this->source( 0 ),
					)
				),
				$this->site(
					array(
						'ga'        => $this->source( 0 ),
						'mediavine' => $this->source( 10 ),
					)
				),
			)
		);

		$this->assertSame( 'unknown', $report['sites'][0]['status'] );
		$this->assertNull( $report['sites'][0]['coverage_ratio'] );
		$this->assertSame( 'warning', $report['sites'][1]['status'] );
		$this->assertTrue( $report['sites'][1]['extreme_divergence'] );
	}

	/**
	 * Source summaries preserve policy, resolution, and route-family evidence.
	 */
	public function test_source_breakdowns_are_kept_separate(): void {
		$summary = extrachill_analytics_delivery_summarize_source(
			array(
				array(
					'pageviews'    => 100,
					'sessions'     => 50,
					'policy'       => 'applicable',
					'route_family' => 'other',
					'resolved'     => true,
				),
				array(
					'pageviews'    => 20,
					'sessions'     => 5,
					'policy'       => 'blocked',
					'route_family' => 'app-account',
					'resolved'     => false,
				),
				array(
					'pageviews'    => 10,
					'sessions'     => 2,
					'policy'       => 'unknown',
					'route_family' => 'taxonomy-archive',
					'resolved'     => false,
				),
			),
			'2026-06-01',
			'2026-06-30'
		);

		$this->assertSame( 100, $summary['applicable_views'] );
		$this->assertSame( 20, $summary['blocked_views'] );
		$this->assertSame( 10, $summary['unknown_views'] );
		$this->assertSame( 100, $summary['resolved_views'] );
		$this->assertSame( 30, $summary['unresolved_views'] );
		$this->assertSame( 20, $summary['route_families']['app-account'] );
	}

	/**
	 * Contiguous persisted periods must exactly match requested boundaries.
	 */
	public function test_exact_contiguous_snapshot_window(): void {
		$rows   = array(
			(object) array(
				'period_start' => '2026-05-01',
				'period_end'   => '2026-05-31',
				'import_batch' => 'may',
			),
			(object) array(
				'period_start' => '2026-06-01',
				'period_end'   => '2026-06-30',
				'import_batch' => 'june',
			),
		);
		$window = extrachill_analytics_delivery_validate_snapshot_window( $rows, '2026-05-01', '2026-06-30' );

		$this->assertTrue( $window['exact'] );
		$this->assertCount( 2, $window['periods'] );
	}

	/**
	 * Partial boundaries, gaps, and duplicate batches cannot be compared.
	 */
	public function test_non_exact_snapshot_windows_are_unknown(): void {
		$partial   = array(
			(object) array(
				'period_start' => '2026-05-01',
				'period_end'   => '2026-05-31',
				'import_batch' => 'may',
			),
		);
		$gap       = array(
			(object) array(
				'period_start' => '2026-05-01',
				'period_end'   => '2026-05-15',
				'import_batch' => 'a',
			),
			(object) array(
				'period_start' => '2026-05-17',
				'period_end'   => '2026-05-31',
				'import_batch' => 'b',
			),
		);
		$duplicate = array(
			(object) array(
				'period_start' => '2026-05-01',
				'period_end'   => '2026-05-31',
				'import_batch' => 'a',
			),
			(object) array(
				'period_start' => '2026-05-01',
				'period_end'   => '2026-05-31',
				'import_batch' => 'b',
			),
		);

		$this->assertFalse( extrachill_analytics_delivery_validate_snapshot_window( $partial, '2026-05-01', '2026-06-30' )['exact'] );
		$this->assertFalse( extrachill_analytics_delivery_validate_snapshot_window( $gap, '2026-05-01', '2026-05-31' )['exact'] );
		$this->assertFalse( extrachill_analytics_delivery_validate_snapshot_window( $duplicate, '2026-05-01', '2026-05-31' )['exact'] );
	}

	/**
	 * Date input is strict and real calendar dates only.
	 */
	public function test_date_validation_is_exact(): void {
		$this->assertTrue( extrachill_analytics_delivery_valid_date( '2026-07-14' ) );
		$this->assertFalse( extrachill_analytics_delivery_valid_date( '2026-02-30' ) );
		$this->assertFalse( extrachill_analytics_delivery_valid_date( '07/14/2026' ) );
	}

	/**
	 * The runtime composes existing owners and never invokes the path resolver.
	 */
	public function test_runtime_ownership_contract(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-delivery-coverage.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( "wp_get_ability( 'datamachine/google-analytics' )", $source );
		$this->assertStringContainsString( 'extrachill_analytics_revenue_get_rows', $source );
		$this->assertStringContainsString( 'extrachill_get_ad_policy', $source );
		$this->assertStringNotContainsString( 'ec_resolve_frontend_paths(', $source );
	}
}
