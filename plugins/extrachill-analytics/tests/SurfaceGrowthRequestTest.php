<?php
/**
 * Tests for Surface Growth upstream request reuse.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/class-surfacegrowthgaabilityfixture.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-surface-growth.php';

/**
 * Ensure current traffic source rows drive both organic count and share.
 */
final class SurfaceGrowthRequestTest extends TestCase {
	/**
	 * One surface performs two date and two traffic-source reads.
	 */
	public function test_current_traffic_sources_result_is_reused_for_share(): void {
		$ability = new SurfaceGrowthGaAbilityFixture();
		$result  = extrachill_analytics_surface_demand_growth(
			array( 'host' => 'extrachill.com' ),
			$ability,
			true,
			7
		);

		$this->assertCount( 4, $ability->requests );
		$this->assertSame( array( 'date_stats', 'date_stats', 'traffic_sources', 'traffic_sources' ), array_column( $ability->requests, 'action' ) );
		$this->assertSame( 40, $result['current_organic'] );
		$this->assertSame( 20, $result['previous_organic'] );
		$this->assertSame( 0.4, $result['organic_share'] );
		$this->assertSame( 100.0, $result['slope_pct'] );
	}

	/**
	 * Traffic-source errors preserve the all-session fallback semantics.
	 */
	public function test_traffic_source_error_preserves_all_session_fallback(): void {
		$ability               = new SurfaceGrowthGaAbilityFixture();
		$ability->fail_traffic = true;
		$result                = extrachill_analytics_surface_demand_growth(
			array( 'host' => 'extrachill.com' ),
			$ability,
			true,
			7
		);

		$this->assertTrue( $result['measured'] );
		$this->assertSame( 'all_sessions', $result['basis'] );
		$this->assertSame( 70, $result['current_organic'] );
		$this->assertSame( 70, $result['previous_organic'] );
		$this->assertNull( $result['organic_share'] );
	}

	/**
	 * A truncated date series remains an explicit coverage gap.
	 */
	public function test_truncated_date_series_remains_not_instrumented(): void {
		$result = extrachill_analytics_surface_demand_growth(
			array( 'host' => 'extrachill.com' ),
			new SurfaceGrowthGaAbilityFixture(),
			true,
			8
		);

		$this->assertFalse( $result['measured'] );
		$this->assertTrue( $result['not_instrumented'] );
		$this->assertStringContainsString( 'truncated daily series', $result['reason'] );
	}

	/** Exact ranges pass the selected and preceding windows through unchanged. */
	public function test_exact_range_uses_equal_preceding_ga_window(): void {
		$ability = new SurfaceGrowthGaAbilityFixture();
		$current = extrachill_analytics_resolve_date_range(
			array(
				'start_date' => '2026-07-08',
				'end_date'   => '2026-07-14',
			)
		);
		$prior   = extrachill_analytics_previous_date_range( $current );

		extrachill_analytics_surface_demand_growth(
			array( 'host' => 'extrachill.com' ),
			$ability,
			true,
			7,
			$current,
			$prior
		);

		$this->assertSame( '2026-07-08', $ability->requests[0]['start_date'] );
		$this->assertSame( '2026-07-14', $ability->requests[0]['end_date'] );
		$this->assertSame( '2026-07-01', $ability->requests[1]['start_date'] );
		$this->assertSame( '2026-07-07', $ability->requests[1]['end_date'] );
	}
}
